<?php

namespace Modules\Invoice\Contracts;

use Modules\Invoice\DTOs\InvoiceComplianceResult;
use Modules\Invoice\Models\Invoice;

interface InvoiceComplianceGatewayInterface
{
    public function submitSimplifiedInvoice(Invoice $invoice, array $payload): InvoiceComplianceResult;
}
