<?php

namespace App\Services\Fotocopy;

use App\Jobs\DispatchOrderToWhatsAppJob;
use App\Jobs\RefreshMsbdMaterializedViewsJob;
use App\Models\FotocopyOrder;
use App\Services\Cache\CampusRedisService;
use App\Services\Invoice\OrderInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderPaymentFulfillmentService
{
    public function __construct(
        protected OrderInvoiceService $invoiceService
    ) {
    }

    /**
     * Execute post-payment fulfillment strictly ONLY when the order is paid:
     * 1. Generate official invoice PDF (`INV-YYYYMMDD-XXXX.pdf`) on private `local` disk.
     * 2. Dispatch automated WhatsApp burst (summary -> PDFs + notes -> invoice PDF).
     */
    public function handlePaidOrder(FotocopyOrder $order, bool $dispatchWhatsApp = true): void
    {
        app(CampusRedisService::class)->withOrderPaymentLock(
            (string) $order->order_code,
            function () use ($order, $dispatchWhatsApp): void {
                $fresh = $order->fresh();
                if (!$fresh || !$fresh->isPaid()) {
                    return;
                }

                $this->invoiceService->generateForOrder($fresh);

                if ($dispatchWhatsApp && !$fresh->isWaDispatched() && class_exists(DispatchOrderToWhatsAppJob::class)) {
                    $fresh->wa_dispatch_status = 'queued';
                    $fresh->save();

                    if (config('whatsapp.sync_dispatch', true)) {
                        DispatchOrderToWhatsAppJob::dispatchSync($fresh->id);
                    } else {
                        DispatchOrderToWhatsAppJob::dispatch($fresh->id);
                    }
                }

                if (DB::getDriverName() === 'pgsql') {
                    try {
                        RefreshMsbdMaterializedViewsJob::dispatch('revenue');
                    } catch (\Throwable $e) {
                        Log::debug('OrderPaymentFulfillmentService MV refresh skipped: ' . $e->getMessage());
                    }
                }
            }
        );
    }
}
