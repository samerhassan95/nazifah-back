<?php

namespace Modules\Vendor\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Branch\Models\Branch;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Http\Resources\ConversationWithMessagesResource;
use Modules\Chat\Http\Resources\MessageResource;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ChatService;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Vendor\Support\VendorBranchFilter;

class ChatsController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private UploadFilesService $uploadService
    ) {}

    /**
     * Get chats/conversations for this vendor
     */
    public function index(Request $request): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $perPage = (int) $request->get('per_page', 15);

        $conversationsQuery = Conversation::where('vendor_id', $vendorId)
            ->has('messages')
            ->with(['client', 'driver', 'admin', 'order', 'lastMessage'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->orderBy('last_message_at', 'desc');

        if (VendorBranchFilter::hasFilter($request)) {
            $branchIds = VendorBranchFilter::resolveIds($request, $vendorId);

            if ($branchIds->isEmpty()) {
                $conversationsQuery->whereRaw('1 = 0');
            } else {
                $conversationsQuery->whereHas('order', fn ($q) => $q->whereIn('branch_id', $branchIds));
            }
        }

        $conversations = $conversationsQuery->paginate($perPage);

        return successResponse(
            ConversationResource::collection($conversations),
            'Chats retrieved successfully'
        );
    }

    /**
     * Get one conversation with messages
     */
    public function show(Request $request, string $conversationId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $perPage = $request->get('per_page', 50);

        $conversation = $this->chatService->getConversationWithMessagesForVendor($conversationId, $vendorId, $perPage);
        if (! $conversation) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

        return successResponse(
            new ConversationWithMessagesResource($conversation),
            __('chat.messages_retrieved')
        );
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages(Request $request, string $conversationId): JsonResponse
    {
        $employee = $request->user();
        $vendorId = $employee->vendor_id;
        $perPage = (int) $request->get('per_page', 50);

        $messages = $this->chatService->getMessagesForVendor($conversationId, $vendorId, $perPage);
        if ($messages === null) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

        return successResponse(
            MessageResource::collection($messages),
            __('chat.messages_retrieved')
        );
    }

    /**
     * Send message.
     * - conversation_id → existing chat
     * - target=client + target_id → client id (order_id optional)
     * - target=delivery + target_id → driver id (order_id optional)
     * - order_id only → target defaults to client; ids from order if target_id omitted
     * - neither → support chat with admin
     */
    public function sendMessage(Request $request, ?string $conversationId = null): JsonResponse
    {
        $convIdFromUrl = $conversationId ?? $request->conversation_id;

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:5000'],
            'conversation_id' => ['nullable', 'string'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'target' => ['nullable', 'string', 'in:client,delivery'],
            'target_id' => ['nullable', 'integer'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);

        $validator->after(function ($v) use ($request, $convIdFromUrl) {
            if ($convIdFromUrl) {
                return;
            }

            $hasTarget = $request->filled('target');
            $hasOrder = $request->filled('order_id');

            if ($hasTarget && ! $request->filled('target_id')) {
                $v->errors()->add('target_id', __('chat.target_id_required'));
            }

            if (! $hasTarget && ! $hasOrder) {
                return;
            }
        });

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $employee = $request->user();
        $vendorId = (int) $employee->vendor_id;

        $convId = $convIdFromUrl;
        $orderId = $request->order_id ? (int) $request->order_id : null;
        $target = $request->target ?? ($orderId ? 'client' : null);
        $targetId = $request->target_id ? (int) $request->target_id : null;
        $clientId = null;
        $driverId = null;

        if (! $convId && ($orderId || $target)) {
            $branchIds = Branch::where('vendor_id', $vendorId)->pluck('id');
            $order = null;

            if ($orderId) {
                $order = Order::where('id', $orderId)->whereIn('branch_id', $branchIds)->first();
                if (! $order) {
                    return notFoundResponse(__('order.order_not_found'));
                }
            }

            if ($target === 'delivery') {
                $driverId = $targetId;
                if ($order) {
                    $orderDriverIds = array_filter([
                        (int) $order->delivery_driver_id,
                        (int) $order->pickup_driver_id,
                        (int) $order->driver_id,
                    ]);
                    if (! in_array($driverId, $orderDriverIds, true)) {
                        return validationErrorResponse([
                            'target_id' => [__('chat.driver_not_on_order')],
                        ]);
                    }
                    $clientId = (int) $order->client_id;
                }

                if (! Driver::where('id', $driverId)->where('vendor_id', $vendorId)->exists()) {
                    return validationErrorResponse([
                        'target_id' => [__('chat.invalid_driver_for_vendor')],
                    ]);
                }
            } elseif ($target === 'client') {
                $clientId = $targetId;

                if ($order) {
                    if ((int) $order->client_id !== $clientId) {
                        return validationErrorResponse([
                            'target_id' => [__('chat.client_not_on_order')],
                        ]);
                    }
                } elseif (! Client::where('id', $clientId)->exists()) {
                    return notFoundResponse(__('chat.client_not_found'));
                } elseif (! Order::whereIn('branch_id', $branchIds)->where('client_id', $clientId)->exists()) {
                    return validationErrorResponse([
                        'target_id' => [__('chat.invalid_client_for_vendor')],
                    ]);
                }
            }

            if ($order) {
                if ($target === 'client' && $clientId === null) {
                    $clientId = (int) $order->client_id;
                }
                if ($target === 'delivery') {
                    if ($driverId === null) {
                        $driverId = (int) ($order->delivery_driver_id ?? $order->pickup_driver_id ?? $order->driver_id);
                        if (! $driverId) {
                            return validationErrorResponse(['target' => [__('chat.no_driver_assigned')]]);
                        }
                    }
                    if ($clientId === null) {
                        $clientId = (int) $order->client_id;
                    }
                }
            }
        }

        $fileUrl = null;
        if ($request->hasFile('file')) {
            $fileUrl = $this->uploadService->uploadChatFile($request->file('file'));
        }

        try {
            $result = $this->chatService->vendorSend(
                $vendorId,
                $request->message,
                $convId,
                $orderId,
                $clientId,
                $request->message_type ?? 'text',
                $fileUrl,
                $driverId
            );
        } catch (\Exception $e) {
            return notFoundResponse($e->getMessage());
        }

        $conversation = $this->chatService->getConversationWithMessagesForVendor(
            $result['conversation_id'], $vendorId, $request->get('per_page', 50)
        );

        if (! $conversation) {
            $conversation = $this->chatService->getConversationWithMessagesForAdmin(
                $result['conversation_id'], $request->get('per_page', 50)
            );
        }

        return successResponse(
            new ConversationWithMessagesResource($conversation),
            __('chat.message_sent')
        );
    }
}
