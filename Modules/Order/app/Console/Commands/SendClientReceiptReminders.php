<?php

namespace Modules\Order\Console\Commands;

use App\Services\OrderNotificationService;
use App\Services\OrderPieceHandoffService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\Order;

class SendClientReceiptReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'orders:send-client-receipt-reminders';

    /**
     * The console command description.
     */
    protected $description = 'Remind the client to confirm receipt 24h/48h after the driver/laundry declared handoff, and auto-confirm receipt on their behalf after 72h with no response.';

    public function __construct(
        private OrderPieceHandoffService $handoffService,
        private OrderNotificationService $notifications,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $orders = Order::whereIn('active_handoff_type', [
            OrderPieceHandoffService::TYPE_DRIVER_TO_CLIENT,
            OrderPieceHandoffService::TYPE_LAUNDRY_TO_CLIENT,
        ])
            ->whereNotNull('active_handoff_declared_at')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders awaiting client receipt confirmation.');

            return self::SUCCESS;
        }

        $reminded24 = 0;
        $reminded48 = 0;
        $autoConfirmed = 0;

        foreach ($orders as $order) {
            $declaredAt = $order->active_handoff_declared_at;
            $hours = now()->diffInHours($declaredAt);

            try {
                if ($hours >= 72) {
                    $this->handoffService->confirmHandoff(
                        $order,
                        'client',
                        (int) $order->client_id,
                        $order->active_handoff_type,
                        'Auto-confirmed by system after 72 hours with no client response'
                    );
                    $autoConfirmed++;

                    continue;
                }

                if ($hours >= 48 && ! $order->client_receipt_reminder_48h_sent_at) {
                    $this->sendReminder($order, $declaredAt, '48h');
                    $order->update(['client_receipt_reminder_48h_sent_at' => now()]);
                    $reminded48++;

                    continue;
                }

                if ($hours >= 24 && ! $order->client_receipt_reminder_24h_sent_at) {
                    $this->sendReminder($order, $declaredAt, '24h');
                    $order->update(['client_receipt_reminder_24h_sent_at' => now()]);
                    $reminded24++;
                }
            } catch (\Throwable $e) {
                $this->error("  Failed for order #{$order->id}: {$e->getMessage()}");

                Log::error('Client receipt reminder/auto-confirm failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done: {$reminded24} 24h reminder(s), {$reminded48} 48h reminder(s), {$autoConfirmed} auto-confirmed.");

        return self::SUCCESS;
    }

    private function sendReminder(Order $order, Carbon $declaredAt, string $stage): void
    {
        $date = $declaredAt->format('d/m');
        $titleKey = "order.client_receipt_reminder_{$stage}_title";
        $bodyKey = "order.client_receipt_reminder_{$stage}_body";

        $this->notifications->sendToClient(
            $order,
            __($titleKey, [], 'ar'),
            __($titleKey, [], 'en'),
            __($bodyKey, ['order_number' => $order->order_number, 'date' => $date], 'ar'),
            __($bodyKey, ['order_number' => $order->order_number, 'date' => $date], 'en'),
            'client_receipt_reminder'
        );
    }
}
