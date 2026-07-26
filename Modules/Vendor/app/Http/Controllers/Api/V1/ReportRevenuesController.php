<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Order\Models\Order;
use Modules\Vendor\Support\VendorBranchFilter;

class ReportRevenuesController extends Controller
{
    /**
     * Get report revenues
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = VendorBranchFilter::resolveIds($request, $vendorId);

        $revenues = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value)
            ->with('branch')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'Title' => 'Order #'.$order->order_number,
                    'sub_title' => 'Completed order',
                    'order_id' => $order->id,
                    'amount_paid' => (float) $order->final_amount,
                    'date' => $order->created_at->format('Y-m-d'),
                    'payment_method' => $order->payment_method ?? 'N/A',
                    'name' => $order->branch ? $order->branch->name : null,
                ];
            });

        return successResponse($revenues, __('vendor.revenues_retrieved'));
    }

    /**
     * Filter revenues
     */
    public function filter(Request $request): JsonResponse
    {
        VendorBranchFilter::normalizeRequest($request);

        $validator = Validator::make($request->all(), array_merge(
            VendorBranchFilter::validationRules(),
            [
                'name' => ['nullable', 'string'],
                'start_date' => ['nullable', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            ]
        ));

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $branchIds = VendorBranchFilter::resolveIds($request, $vendorId);

        $query = Order::whereIn('branch_id', $branchIds)
            ->where('status', OrderStatus::COMPLETED->value);

        if ($request->start_date) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->end_date) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $revenues = $query->with('branch')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'Title' => 'Order #'.$order->order_number,
                    'sub_title' => 'Completed order',
                    'order_id' => $order->id,
                    'amount_paid' => (float) $order->final_amount,
                    'date' => $order->created_at->format('Y-m-d'),
                    'payment_method' => $order->payment_method ?? 'N/A',
                    'name' => $order->branch ? $order->branch->name : null,
                ];
            });

        return successResponse($revenues, __('vendor.filtered_revenues_retrieved'));
    }
}
