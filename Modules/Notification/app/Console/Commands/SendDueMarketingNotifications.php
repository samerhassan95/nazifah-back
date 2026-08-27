<?php

namespace Modules\Notification\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Http\Controllers\Api\V1\MarketingNotificationController;
use Modules\Notification\Models\MarketingNotification;

class SendDueMarketingNotifications extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'notifications:send-due-marketing';

    /**
     * The console command description.
     */
    protected $description = 'Send marketing notifications whose scheduled time has arrived.';

    public function handle(MarketingNotificationController $controller): int
    {
        $due = MarketingNotification::where('status', 'draft')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('No due marketing notifications.');

            return self::SUCCESS;
        }

        foreach ($due as $notification) {
            try {
                $controller->send($notification->id);
                $this->info("Sent marketing notification #{$notification->id}");
            } catch (\Throwable $e) {
                Log::error('Failed to send scheduled marketing notification', [
                    'marketing_notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to send #{$notification->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
