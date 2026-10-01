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
            ->with(['client', 'branch', 'driver', 'admin', 'order', 'lastMessage'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->orderBy('last_message_at', 'desc');

        $branchFilterRequested = VendorBranchFilter::hasFilter($request);
        if ($branchFilterRequested || ! $employee->isOwner()) {
            $requestedBranchIds = VendorBranchFilter::requestedIds($request);
            $accessibleBranchIds = array_map('intval', $employee->getAccessibleBranchIds());
            $branchIds = VendorBranchFilter::resolveIds($request, $vendorId)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => in_array($id, $accessibleBranchIds, true))
                ->values();

            if ($branchFilterRequested && $requestedBranchIds !== null
                && count(array_diff($requestedBranchIds, $branchIds->all())) > 0) {
                return errorResponse(__('vendor.unauthorized_action'), null, 403);
            }

            if ($branchIds->isEmpty()) {
                $conversationsQuery->whereRaw('1 = 0');
            } else {
                $conversationsQuery->where(function ($query) use ($branchIds) {
                    $query->whereIn('branch_id', $branchIds)
                        ->orWhereHas('order', fn ($q) => $q->whereIn('branch_id', $branchIds));
                });
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

        if (! $this->canAccessVendorConversation($employee, $vendorId, $conversationId)) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

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

        if (! $this->canAccessVendorConversation($employee, $vendorId, $conversationId)) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'target' => ['nullable', 'string', 'in:client,delivery,admin'],
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

            if ($hasTarget && $request->input('target') !== 'admin' && ! $request->filled('target_id')) {
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
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        if ($branchId !== null && ! $employee->canAccessBranch($branchId)) {
            return validationErrorResponse([
                'branch_id' => [__('vendor.unauthorized_action')],
            ]);
        }

        $convId = $convIdFromUrl;
        $orderId = $request->order_id ? (int) $request->order_id : null;
        $target = $request->target ?? ($orderId ? 'client' : null);
        $targetId = $request->target_id ? (int) $request->target_id : null;

        if ($convId && ! $this->canAccessVendorConversation($employee, $vendorId, $convId)) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

        if ($branchId !== null && ! $convId) {
            if ($orderId !== null || ($target !== null && $target !== 'admin')) {
                return validationErrorResponse([
                    'branch_id' => ['Branch support chats cannot be combined with order or other chat targets.'],
                ]);
            }
            $target = 'admin';
        }
        if ($target === 'admin' && $branchId === null && ! $employee->isOwner()) {
            return validationErrorResponse([
                'branch_id' => ['A branch is required when a branch employee contacts admin.'],
            ]);
        }
        if ($target === 'admin') {
            $orderId = null;
            $targetId = null;
        }
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
                $driverId,
                $branchId
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

    private function canAccessVendorConversation($employee, int $vendorId, string $conversationId): bool
    {
        $conversation = Conversation::where('id', $conversationId)
            ->where('vendor_id', $vendorId)
            ->first();

        if (! $conversation) {
            return false;
        }

        if ($employee->isOwner()) {
            return true;
        }

        $branchId = $conversation->branch_id;
        if ($branchId === null && $conversation->order_id !== null) {
            $branchId = Order::whereKey($conversation->order_id)->value('branch_id');
        }

        return $branchId === null || $employee->canAccessBranch((int) $branchId);
    }
}
