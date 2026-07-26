<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Payment\Models\PaymentMethod;

class AdminPaymentMethodController extends Controller
{
    /**
     * Display a listing of all payment methods (including inactive)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $locale = $request->input('language', app()->getLocale());
            $paymentMethods = PaymentMethod::getAllWithDetails($locale);

            return successResponse(
                $paymentMethods,
                __('payment.methods_retrieved')
            );

        } catch (\Exception $e) {
            return ErrorResponse::make(__('payment.retrieval_failed'), null, 500);
        }
    }

    /**
     * Toggle payment method status (activate/deactivate)
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $paymentMethod = PaymentMethod::findOrFail($id);

            // Toggle the status (flip it to opposite)
            $paymentMethod->is_active = ! $paymentMethod->is_active;
            $paymentMethod->save();

            $locale = $request->input('language', app()->getLocale());
            $enum = $paymentMethod->getEnumInstance();

            return successResponse(
                [
                    'id' => $paymentMethod->id,
                    'value' => $paymentMethod->method_key,
                    'label' => $enum ? $enum->getDisplayName($locale) : $paymentMethod->method_key,
                    'is_active' => $paymentMethod->is_active,
                ],
                __('payment.status_updated')
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ErrorResponse::make(__('payment.method_not_found'), null, 404);
        } catch (\Exception $e) {
            return ErrorResponse::make(__('payment.update_failed'), null, 500);
        }
    }

    /**
     * Update sort order for multiple payment methods
     */
    public function updateSortOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'methods' => 'required|array',
            'methods.*.id' => 'required|exists:payment_methods,id',
            'methods.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return ErrorResponse::make(__('auth.validation_error'), $validator->errors(), 422);
        }

        try {
            foreach ($request->methods as $methodData) {
                PaymentMethod::where('id', $methodData['id'])->update([
                    'sort_order' => $methodData['sort_order'],
                ]);
            }

            // Mass update via the query builder bypasses Eloquent model events,
            // so the `saved` hook that flushes the cache never fires. Flush
            // explicitly or the user endpoint serves the old order for up to 24h.
            PaymentMethod::clearCache();

            return successResponse(null, __('payment.sort_order_updated'));
        } catch (\Exception $e) {
            return ErrorResponse::make(__('payment.sort_order_failed'), null, 500);
        }
    }
}
