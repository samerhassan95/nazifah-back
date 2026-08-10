<?php

namespace Modules\Invoice\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\Order;

class InvoiceController extends Controller
{
    public function showForOrder(Request $request, Order $order)
    {
        if ((int) $order->client_id !== (int) $request->user()->id) {
            return response()->json([
                'status' => false,
                'code' => 403,
                'message' => __('order.unauthorized_access'),
            ], 403);
        }

        $service = app(\Modules\Invoice\Services\InvoiceService::class);
        $invoice = $service->createOrFetchForOrder($order);

        return response()->json([
            'status' => true,
            'code' => 200,
            'message' => 'Invoice retrieved successfully',
            'data' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'zatca_status' => $invoice->zatca_status,
                'whatsapp_status' => $invoice->whatsapp_status,
                'total_amount' => (float) $invoice->total_amount,
                'issued_at' => optional($invoice->issued_at)->toIso8601String(),
                'share_url' => $service->shareUrl($invoice),
                'payload' => $invoice->invoice_payload,
            ],
        ]);
    }

    public function share(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('order.client', 'order.items.piece', 'order.items.service', 'branch.vendor');

        $payload = $invoice->invoice_payload;
        if (empty($payload)) {
            $builder = app(\Modules\Invoice\Services\InvoicePayloadBuilder::class);
            $payload = $builder->buildForOrder($invoice->order, $invoice);
            $invoice->forceFill(['invoice_payload' => $payload])->save();
        }

        $html = view('invoice::show', [
            'invoice' => $invoice,
            'payload' => $payload,
        ])->render();

        return response($html);
    }
}
