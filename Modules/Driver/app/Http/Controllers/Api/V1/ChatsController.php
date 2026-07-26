<?php

namespace Modules\Driver\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Http\Resources\ConversationWithMessagesResource;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ChatService;
use Modules\Order\Models\Order;

class ChatsController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private UploadFilesService $uploadService
    ) {}

    /**
     * List conversations for this driver (includes admin support chats)
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user();
        $driverId = $driver->id;
        $perPage = (int) $request->get('per_page', 15);

        $conversationsQuery = Conversation::where('driver_id', $driverId)
            ->has('messages')
            ->with(['client', 'vendor', 'admin', 'order', 'lastMessage'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->orderBy('last_message_at', 'desc');

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
        $driver = $request->user();
        $perPage = $request->get('per_page', 50);

        $conversation = $this->chatService->getConversationWithMessagesForDriver($conversationId, $driver->id, $perPage);
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
        $driver = $request->user();
        $perPage = $request->get('per_page', 50);

        $messages = $this->chatService->getMessagesForDriver($conversationId, $driver->id, $perPage);
        if ($messages === null) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

        return successResponse(
            \Modules\Chat\Http\Resources\MessageResource::collection($messages),
            __('chat.messages_retrieved')
        );
    }

    /**
     * Send message.
     * - conversation_id provided → send in that conversation
     * - order_id provided (no conversation_id) → find/create order chat with client
     * - neither → find/create support chat with admin
     */
    public function sendMessage(Request $request, ?string $conversationId = null): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:5000'],
            'conversation_id' => ['nullable', 'string'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);
        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $driver = $request->user();
        $driverId = $driver->id;

        $convId = $conversationId ?? $request->conversation_id;
        $orderId = $request->order_id ? (int) $request->order_id : null;
        $clientId = null;
        $vendorId = null;

        if (! $convId && $orderId) {
            $order = Order::with('branch')->forDriver($driverId)->find($orderId);
            if (! $order) {
                return notFoundResponse(__('order.order_not_found'));
            }
            $clientId = (int) $order->client_id;
            $vendorId = $order->branch ? (int) $order->branch->vendor_id : null;
        }

        $fileUrl = null;
        if ($request->hasFile('file')) {
            $fileUrl = $this->uploadService->uploadChatFile($request->file('file'));
        }

        try {
            $result = $this->chatService->driverSend(
                $driverId,
                $request->message,
                $convId,
                $orderId,
                $clientId,
                $vendorId,
                $request->message_type ?? 'text',
                $fileUrl
            );
        } catch (\Exception $e) {
            return notFoundResponse($e->getMessage());
        }

        $conversation = $this->chatService->getConversationWithMessagesForDriver(
            $result['conversation_id'], $driverId, $request->get('per_page', 50)
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
