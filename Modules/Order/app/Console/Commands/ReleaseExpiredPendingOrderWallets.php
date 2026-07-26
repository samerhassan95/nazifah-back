<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\PendingOrder;
use Modules\Order\Services\OrderPaymentService;

class ReleaseExpiredPendingOrderWallets extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'orders:release-expired-wallet-holds
                            {--dry-run : Show what would be released without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Release wallet holds for pending orders that have expired without payment completion.';

    public function __construct(private OrderPaymentService $orderPaymentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }

        $expiredOrders = PendingOrder::expired()->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('No expired pending orders found.');

            return self::SUCCESS;
        }

        $this->info("Found {$expiredOrders->count()} expired pending order(s).");

        $released = 0;
        $failed = 0;

        foreach ($expiredOrders as $pendingOrder) {
            $orderNumber = $pendingOrder->order_data['order_number'] ?? "PO#{$pendingOrder->id}";

            if ($isDryRun) {
                $this->line("  [DRY RUN] Would release wallet hold for: {$orderNumber} (expired at {$pendingOrder->expires_at})");

                continue;
            }

            try {
                DB::transaction(function () use ($pendingOrder) {
                    // Release any wallet hold tied to this pending order
                    $this->orderPaymentService->releasePendingOrderWalletReservations($pendingOrder->id);

                    // Mark the pending order as expired so it is not processed again
                    $pendingOrder->update(['status' => 'expired']);
                });

                $this->line("  Released wallet hold for order {$orderNumber} (client_id={$pendingOrder->client_id})");

                Log::info('Expired pending order wallet hold released', [
                    'pending_order_id' => $pendingOrder->id,
                    'order_number'     => $orderNumber,
                    'client_id'        => $pendingOrder->client_id,
                    'expired_at'       => $pendingOrder->expires_at?->toISOString(),
                ]);

                $released++;
            } catch (\Throwable $e) {
                $this->error("  Failed to release wallet hold for {$orderNumber}: {$e->getMessage()}");

                Log::error('Failed to release expired pending order wallet hold', [
                    'pending_order_id' => $pendingOrder->id,
                    'order_number'     => $orderNumber,
                    'error'            => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        if (! $isDryRun) {
            $this->info("Done: {$released} released, {$failed} failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
