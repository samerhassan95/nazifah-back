<?php

namespace Modules\Invoice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Services\InvoiceService;

class SubmitInvoiceToZatcaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $invoiceId) {}

    public function handle(InvoiceService $invoiceService): void
    {
        $invoice = Invoice::find($this->invoiceId);
        if (! $invoice) {
            return;
        }

        $invoiceService->submitToZatca($invoice);
    }
}
