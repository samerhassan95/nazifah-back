<?php

namespace Modules\SmartOrder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\SmartOrder\Services\SmartOrderConversationService;

class SmartOrderController extends Controller
{
    public function __construct(
        private SmartOrderConversationService $service
    ) {}

    /**
     * POST /api/v1/user/smart-order/messages
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'conversation_id' => ['nullable', 'integer'],
            'text' => ['required', 'string'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'source' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $locale = $request->input('locale', app()->getLocale());
        $conversationId = $request->filled('conversation_id') ? (int) $request->input('conversation_id') : null;

        $result = $this->service->sendMessage($request->user(), $conversationId, (string) $request->input('text'), $locale);

        if (! $result['success']) {
            return errorResponse($result['message'], null, $result['status']);
        }

        return successResponse($result['data'], 'OK', $result['status']);
    }

    /**
     * POST /api/v1/user/smart-order/{conversation_id}/resolve
     */
    public function resolve(Request $request, int $conversationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'line_key' => ['nullable', 'string'],
            'field' => ['required', 'string', 'in:piece,service,laundry,unknown_item'],
            'option_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $locale = $request->input('locale', app()->getLocale());

        $result = $this->service->resolve(
            $request->user(),
            $conversationId,
            (string) $request->input('line_key', ''),
            (string) $request->input('field'),
            (int) $request->input('option_id'),
            $locale
        );

        if (! $result['success']) {
            return errorResponse($result['message'], null, $result['status']);
        }

        return successResponse($result['data'], 'OK', $result['status']);
    }

    /**
     * POST /api/v1/user/smart-order/{conversation_id}/confirm
     */
    public function confirm(Request $request, int $conversationId): JsonResponse
    {
        $locale = $request->input('locale', app()->getLocale());
        $replaceCart = $request->boolean('replace_cart', false);

        $result = $this->service->confirm($request->user(), $conversationId, $replaceCart, $locale);

        if (! $result['success']) {
            return errorResponse($result['message'], $result['conflict'] ?? null, $result['status']);
        }

        return successResponse($result['data'], __('cart.item_added'), $result['status']);
    }
}
