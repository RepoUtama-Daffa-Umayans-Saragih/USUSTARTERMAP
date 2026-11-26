<?php

namespace App\Services\Payment;

use App\Models\FotocopyOrder;
use App\Services\Cache\CampusRedisService;
use App\Services\Fotocopy\OrderPaymentFulfillmentService;
use App\Services\Payment\Contracts\PaymentStrategyInterface;
use App\Services\Payment\DTO\PaymentResult;
use App\Services\Payment\Strategies\BankTransferPaymentStrategy;
use App\Services\Payment\Strategies\CashPaymentStrategy;
use App\Services\Payment\Strategies\QrisPaymentStrategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PaymentManager
{
    /**
     * Cache instantiated strategies.
     */
    protected array $strategies = [];

    public function __construct()
    {
        $this->strategies = [
            'cash' => new CashPaymentStrategy(),
            'qris' => new QrisPaymentStrategy(),
            'bank_transfer' => new BankTransferPaymentStrategy(),
        ];
    }

    /**
     * Resolve the corresponding payment strategy by method name.
     */
    public function getStrategy(?string $method): PaymentStrategyInterface
    {
        $normalized = strtolower(trim((string) $method));

        return match ($normalized) {
            'cash', 'tunai', 'cod' => $this->strategies['cash'],
            'qris' => $this->strategies['qris'],
            'bank_transfer', 'bank', 'transfer', 'va' => $this->strategies['bank_transfer'],
            default => $this->strategies['cash'],
        };
    }

    /**
     * Process payment initialization for an order.
     */
    public function process(FotocopyOrder $order, ?string $method, array $options = []): PaymentResult
    {
        $strategy = $this->getStrategy($method);
        return $strategy->process($order, $options);
    }

    /**
     * Simulate success for an order based on its payment method, then fulfill invoice & WhatsApp dispatch.
     */
    public function simulateSuccess(FotocopyOrder $order): bool
    {
        return app(CampusRedisService::class)->withOrderPaymentLock(
            (string) $order->order_code,
            function () use ($order): bool {
                $fresh = $order->fresh();
                if (!$fresh) {
                    return false;
                }

                if (DB::getDriverName() === 'pgsql') {
                    $payload = is_array($fresh->payment_payload) ? $fresh->payment_payload : [];
                    $method = strtolower(trim((string) $fresh->payment_method));
                    if ($method === 'qris') {
                        $payload['paid_via'] = 'QRIS Webhook Simulator';
                        $payload['simulated_at'] = now()->toIso8601String();
                        $payload['settlement_reference'] = 'SETTLE-' . strtoupper(Str::random(10));
                    } elseif (in_array($method, ['bank_transfer', 'transfer', 'bank', 'va'], true)) {
                        $payload['paid_via'] = 'Bank VA Callback Simulator';
                        $payload['simulated_at'] = now()->toIso8601String();
                        $payload['bank_reference'] = 'TRX-' . strtoupper($fresh->payment_channel ?? 'VA') . '-' . time();
                    } else {
                        $payload['simulated_at'] = now()->toIso8601String();
                        $payload['simulated_by'] = 'Cashier Simulation';
                    }

                    $proc = DB::selectOne(
                        'CALL sp_mark_order_paid_atomic(?, ?, ?::jsonb, NULL, NULL)',
                        [
                            $fresh->order_code,
                            $fresh->payment_channel,
                            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]
                    );

                    $wasAlreadyPaid = (bool) ($proc->p_was_already_paid ?? false);
                    $updated = $fresh->fresh();

                    if (!$wasAlreadyPaid && $updated && $updated->order_status === 'Menunggu') {
                        $updated->order_status = 'Diproses';
                        $updated->save();
                        $updated = $updated->fresh();
                    }

                    if ($updated && $updated->isPaid()) {
                        app(OrderPaymentFulfillmentService::class)->handlePaidOrder($updated, !$wasAlreadyPaid);
                    }

                    return true;
                }

                $wasPaid = $fresh->isPaid();
                $strategy = $this->getStrategy($fresh->payment_method);
                $ok = $strategy->simulateSuccess($fresh);

                if ($ok && $fresh->fresh()?->isPaid()) {
                    app(OrderPaymentFulfillmentService::class)->handlePaidOrder($fresh->fresh(), !$wasPaid);
                }

                return $ok;
            }
        );
    }

    /**
     * Get list of supported payment methods with metadata for front-end rendering.
     */
    public function getSupportedMethods(): array
    {
        return [
            [
                'id' => 'qris',
                'name' => 'QRIS Real-Time',
                'description' => 'Scan QR Code langsung via GoPay, OVO, Dana, BCA Mobile, Livin, dll.',
                'badge' => 'Otomatis',
                'is_popular' => true,
            ],
            [
                'id' => 'bank_transfer',
                'name' => 'Transfer Bank (Virtual Account)',
                'description' => 'Transfer via BCA, Mandiri, BNI, BRI, atau BSI (Kode PoC: 88019920260001).',
                'badge' => 'Virtual Account',
                'is_popular' => false,
            ],
            [
                'id' => 'cash',
                'name' => 'Bayar di Tempat (Tunai)',
                'description' => 'Bayar tunai saat mengambil hasil fotokopi/cetakan langsung di kasir toko.',
                'badge' => 'Kasir Toko',
                'is_popular' => false,
            ],
        ];
    }
}
