<?php

namespace Modules\Payment\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Payment\Models\PaymentMethod;
use Modules\Payment\Services\ActiveGatewayResolver;

class PaymentMethodController extends Controller
{
    /**
     * Get all active payment methods
     */
    public function index(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        $activeGateway = ActiveGatewayResolver::name();
        $tags = ['payment_methods'];
        $versionKey = getTagVersionKey($tags);
        $cacheKey = "user:v5:payment_methods:{$activeGateway}:v{$versionKey}:{$locale}";

        $payload = Cache::remember($cacheKey, 86400, function () use ($locale) {
            return PaymentMethod::getActivePayloadForUser($locale);
        });

        return successResponse(
            $payload,
            __('payment.methods_retrieved')
        );
    }
}
