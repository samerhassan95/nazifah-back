<?php

namespace Modules\SmartOrder\Console\Commands;

use Illuminate\Console\Command;
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
    protected $description = 'Delete smart order conversations 48h after they were confirmed (cart created), or 48h after creation if never confirmed.';

    public function handle(): int
    {
        $cutoff = now()->subHours(48);

        $confirmed = SmartOrderConversation::whereNotNull('confirmed_at')
            ->where('confirmed_at', '<=', $cutoff)
            ->delete();

        $abandoned = SmartOrderConversation::whereNull('confirmed_at')
            ->where('created_at', '<=', $cutoff)
            ->delete();

        $this->info("Deleted {$confirmed} confirmed and {$abandoned} abandoned smart order conversation(s).");

        return self::SUCCESS;
    }
}
