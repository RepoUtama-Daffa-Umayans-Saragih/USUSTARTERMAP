<?php

namespace App\Jobs;

use App\Models\FotocopyOrder;
use App\Models\Mongo\WhatsappDispatchEvent;
use App\Models\WhatsappDispatchLog;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppPayloadFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendCustomerReadyNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public int $orderId)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppManager $waManager, WhatsAppPayloadFormatter $formatter): void
    {
        $order = FotocopyOrder::with(['store'])->find($this->orderId);
        if (!$order || $order->order_status !== 'Selesai') {
            return;
        }

        $customerWa = $order->normalized_customer_whatsapp;
        if (!$customerWa) {
            return;
        }

        $alreadyNotified = WhatsappDispatchLog::where('fotocopy_order_id', $order->id)
            ->where('recipient_role', 'customer')
            ->where('message_type', 'status_update')
            ->where('status', 'sent')
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $driver = $waManager->driver();
        $driverName = $driver->driverName();
        $message = $formatter->formatCustomerReadyMessage($order);
        $res = $driver->sendText($customerWa, $message);
        $status = ($res['success'] ?? false) ? 'sent' : 'failed';

        WhatsappDispatchLog::create([
            'fotocopy_order_id' => $order->id,
            'recipient_phone' => $customerWa,
            'recipient_role' => 'customer',
            'message_type' => 'status_update',
            'payload_json' => ['text' => $message],
            'attachment_path' => null,
            'driver' => $driverName,
            'external_message_id' => $res['external_message_id'] ?? null,
            'status' => $status,
            'attempts' => max(1, (int) $this->attempts()),
            'error_message' => $res['error'] ?? null,
            'sent_at' => ($res['success'] ?? false) ? now() : null,
        ]);

        try {
            WhatsappDispatchEvent::create([
                'order_id' => $order->id,
                'order_code' => $order->order_code,
                'driver' => $driverName,
                'recipient' => $customerWa,
                'status' => $status,
                'payload' => [
                    'recipient_role' => 'customer',
                    'message_type' => 'status_update',
                    'text' => $message,
                ],
                'raw_response' => $res,
                'expires_at' => now()->addDays(WhatsappDispatchEvent::TTL_DAYS),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendCustomerReadyNotificationJob MongoDB telemetry skipped: ' . $e->getMessage());
        }
    }
}
