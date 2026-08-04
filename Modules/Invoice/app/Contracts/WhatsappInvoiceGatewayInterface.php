<?php

namespace Modules\Invoice\Contracts;

use Modules\Invoice\DTOs\WhatsappDeliveryResult;
use Modules\Invoice\Models\Invoice;

interface WhatsappInvoiceGatewayInterface
{
    public function sendInvoiceLink(Invoice $invoice, array $payload): WhatsappDeliveryResult;
}
