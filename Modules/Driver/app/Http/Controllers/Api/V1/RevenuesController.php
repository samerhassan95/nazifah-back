<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Order\Models\Order;

class RevenuesController extends Controller
{
    /**
     * Get driver revenues summary
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();
        $lang = app()->getLocale();

        $query = Order::forDriver($driver->id)
            ->whereIn('status', [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value])
            ->with(['branch', 'client', 'items.piece', 'items.service']);

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('updated_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('updated_at', '<=', $request->end_date);
        }

        // Filter by month
        if ($request->has('month') && $request->has('year')) {
            $query->whereMonth('updated_at', $request->month)
                ->whereYear('updated_at', $request->year);
        }

        $orders = $query->orderBy('updated_at', 'desc')
            ->paginate($request->per_page ?? 15);

        $revenues = $orders->map(function ($order) use ($lang, $driver) {
            // Get title from first item
            $title = null;
            $subTitle = null;

            if ($order->items && $order->items->count() > 0) {
                $firstItem = $order->items->first();
                if ($firstItem && $firstItem->piece) {
                    $title = method_exists($firstItem->piece, 'getTranslation')
                        ? $firstItem->piece->getTranslation('name', $lang)
                        : $firstItem->piece->name;
                }

                if ($firstItem && $firstItem->service) {
                    $subTitle = method_exists($firstItem->service, 'getTranslation')
                        ? $firstItem->service->getTranslation('service_name', $lang)
                        : $firstItem->service->service_name;
                }
            }

            $paymentMethod = $order->payment_method ?? 'cash_on_delivery';
            $isDeliveryDriver = (int) $order->delivery_driver_id === (int) $driver->id;
            // الدفع عند التسليم = payment collected at delivery; only delivery driver collects it
            if ($paymentMethod === 'cash_on_delivery' && ! $isDeliveryDriver) {
                $amountPaid = 0.0;
                $paymentMethod = $lang === 'ar' ? 'الدفع عند التسليم (لم تستلمه)' : 'cash_on_delivery (not collected by you)';
            } else {
                $amountPaid = (float) $order->final_amount;
            }

            $driverDeliveryFee = $order->getDeliveryFeeForDriver($driver->id);

            return [
                'title' => $title ?? $order->order_number,
                'sub_title' => $subTitle ?? ($order->branch?->name ?? ''),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'amount_paid' => $amountPaid,
                'date' => $order->updated_at?->format('Y-m-d'),
                'payment_method' => $paymentMethod,
                'delivery_fee' => (float) $driverDeliveryFee,
            ];
        })->toArray();

        return successResponse(
            ['revenues' => $revenues],
            'Revenues retrieved successfully',
            200,
            [
                'current_page' => $orders->currentPage(),
                'from' => $orders->firstItem(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
            ]
        );
    }

    /**
     * Get detailed revenue history with filters
     */
    public function history(Request $request): JsonResponse
    {
        $driver = $request->user();

        $query = Order::forDriver($driver->id)
            ->whereIn('status', [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value])
            ->with(['branch:id,name', 'client:id,full_name']);

        // Filter by date range
        if ($request->has('start_date')) {
            $query->whereDate('updated_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('updated_at', '<=', $request->end_date);
        }

        // Filter by month
        if ($request->has('month') && $request->has('year')) {
            $query->whereMonth('updated_at', $request->month)
                ->whereYear('updated_at', $request->year);
        }

        $orders = $query->orderBy('updated_at', 'desc')
            ->paginate($request->per_page ?? 15);

        $data = $orders->map(function ($order) use ($driver) {
            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'branch_name' => $order->branch?->name,
                'client_name' => $order->client?->full_name,
                'amount' => (float) $order->final_amount,
                'delivery_fee' => (float) $order->getDeliveryFeeForDriver($driver->id),
                'completed_at' => $order->updated_at?->format('Y-m-d H:i:s'),
            ];
        });

        return successResponse(
            $data,
            'Revenue history retrieved successfully',
            200,
            [
                'current_page' => $orders->currentPage(),
                'from' => $orders->firstItem(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
            ]
        );
    }
}
