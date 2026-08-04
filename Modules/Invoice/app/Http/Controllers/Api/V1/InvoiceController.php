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

        $invoice = Invoice::where('order_id', $order->id)->first();
        if (! $invoice) {
            return response()->json([
                'status' => false,
                'code' => 404,
                'message' => 'Invoice not found',
            ], 404);
        }

        $service = app(\Modules\Invoice\Services\InvoiceService::class);

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
        $invoice->loadMissing('order.client', 'branch.vendor');

        $payload = $invoice->invoice_payload ?? [];
        $html = view('invoice::show', [
            'invoice' => $invoice,
            'payload' => $payload,
        ])->render();

        return response($html);
    }
}
