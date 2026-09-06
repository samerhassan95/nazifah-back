<?php

namespace Modules\Order\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Models\OrderModificationIntent;
use Modules\Order\Services\OrderPaymentService;

class ExpireStaleOrderModificationIntents extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'orders:expire-stale-modification-intents
                            {--minutes=5 : Age threshold in minutes}
                            {--dry-run : Show what would be expired without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Cancel unpaid order-edit surcharge legs (and expire their modification intent) once they have sat pending past the age threshold — the client started an order edit that needed a surcharge payment and never completed it.';

    public function __construct(private OrderPaymentService $orderPaymentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $minutes = max(1, (int) $this->option('minutes'));

        if ($isDryRun) {
            $this->warn('[DRY RUN] No changes will be made.');
        }

        $staleIntents = OrderModificationIntent::where('status', OrderModificationIntent::STATUS_PENDING)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($staleIntents->isEmpty()) {
            $this->info('No stale pending modification intents found.');

            return self::SUCCESS;
        }

        $this->info("Found {$staleIntents->count()} stale pending modification intent(s).");

        $expired = 0;
        $failed = 0;

        foreach ($staleIntents as $intent) {
            if ($isDryRun) {
                $this->line("  [DRY RUN] Would expire intent #{$intent->id} (order_id={$intent->order_id}, created_at={$intent->created_at})");

                continue;
            }

            try {
                DB::transaction(function () use ($intent) {
                    $this->orderPaymentService->expireStaleModificationIntent($intent);
                });

                $this->line("  Expired intent #{$intent->id} (order_id={$intent->order_id})");

                Log::info('Expired stale order modification intent', [
                    'modification_intent_id' => $intent->id,
                    'order_id' => $intent->order_id,
                    'created_at' => $intent->created_at?->toISOString(),
                ]);

                $expired++;
            } catch (\Throwable $e) {
                $this->error("  Failed to expire intent #{$intent->id}: {$e->getMessage()}");

                Log::error('Failed to expire stale order modification intent', [
                    'modification_intent_id' => $intent->id,
                    'order_id' => $intent->order_id,
                    'error' => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        if (! $isDryRun) {
            $this->info("Done: {$expired} expired, {$failed} failed.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
