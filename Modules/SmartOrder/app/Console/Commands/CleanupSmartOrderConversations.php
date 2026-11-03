<?php

namespace Modules\SmartOrder\Console\Commands;

use App\Enums\OrderStatus;
use Illuminate\Console\Command;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusLog;
use Modules\SmartOrder\Models\SmartOrderConversation;

class CleanupSmartOrderConversations extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'smart-order:cleanup-conversations';

    /**
     * The console command description.
     */
    protected $description = 'Delete smart order conversations 48h after the order they led to is completed. Never purely time-based: a never-confirmed conversation is only cleaned up 48h after creation, and a confirmed one whose order never completes is kept.';

    public function handle(): int
    {
        $deleted = 0;

        // Never confirmed (cart never created from it) — plain 48h-after-creation safety net.
        $deleted += SmartOrderConversation::whereNull('confirmed_at')
            ->where('created_at', '<=', now()->subHours(48))
            ->delete();

        SmartOrderConversation::whereNotNull('confirmed_at')->chunkById(100, function ($conversations) use (&$deleted) {
            foreach ($conversations as $conversation) {
                if ($this->isEligibleForCleanup($conversation)) {
                    $conversation->delete();
                    $deleted++;
                }
            }
        });

        $this->info("Deleted {$deleted} smart order conversation(s).");

        return self::SUCCESS;
    }

    /**
     * The backend has no direct conversation->order link (confirm() only
     * creates/merges cart items, order placement happens later through the
     * normal checkout flow). We match the best candidate order placed by
     * the same client on the same branch after this conversation was
     * confirmed, and clean up 48h after THAT order reaches 'completed' —
     * never on a flat timer once an order is in progress.
     */
    private function isEligibleForCleanup(SmartOrderConversation $conversation): bool
    {
        $order = Order::where('client_id', $conversation->client_id)
            ->where('branch_id', $conversation->branch_id)
            ->where('created_at', '>=', $conversation->confirmed_at)
            ->oldest()
            ->first();

        if (! $order) {
            // Confirmed into the cart but never actually checked out — fall
            // back to the same flat safety net as a never-confirmed conversation.
            return $conversation->confirmed_at->lte(now()->subHours(48));
        }

        $completedLog = OrderStatusLog::where('order_id', $order->id)
            ->where('status', OrderStatus::COMPLETED->value)
            ->latest()
            ->first();

        if (! $completedLog) {
            return false; // order still in progress — keep the conversation
        }

        return $completedLog->created_at->lte(now()->subHours(48));
    }
}
