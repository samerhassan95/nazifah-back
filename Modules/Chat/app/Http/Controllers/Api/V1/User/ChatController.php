<?php

namespace Modules\Chat\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\SendMessageRequest;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Http\Resources\ConversationWithMessagesResource;
use Modules\Chat\Http\Resources\MessageResource;
use Modules\Chat\Services\ChatService;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private UploadFilesService $uploadService
    ) {}

    /**
     * Send message (creates conversation if first message).
     * - conversation_id provided → send in that conversation
     * - order_id + vendor_id → find/create order chat
     * - neither → find/create support chat with admin
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $fileUrl = null;

        if ($request->hasFile('file')) {
            $fileUrl = $this->uploadService->uploadChatFile($request->file('file'));
        }

        $result = $this->chatService->sendMessage(
            clientId: $user->id,
            message: $request->message,
            conversationId: $request->conversation_id,
            type: $request->type ?? 'support',
            vendorId: $request->vendor_id,
            driverId: $request->driver_id,
            messageType: $request->message_type ?? 'text',
            fileUrl: $fileUrl,
            orderId: $request->order_id
        );

        $conversationId = $result['conversation_id'];
        $perPage = $request->get('per_page', 50);
        $conversation = $this->chatService->getConversationWithMessages($conversationId, $user->id, $perPage);

        return successResponse(
            new ConversationWithMessagesResource($conversation),
            __('chat.message_sent')
        );
    }

    /**
     * Get all conversations for current user
     */
    public function getConversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->get('per_page', 15);

        $conversations = $this->chatService->getConversations($user->id, $perPage);

        return successResponse(
            ConversationResource::collection($conversations),
            __('chat.conversations_retrieved')
        );
    }

    /**
     * Get messages by conversation
     */
    public function getMessages(Request $request, string $conversationId): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->get('per_page', 50);

        $messages = $this->chatService->getMessages($conversationId, $user->id, $perPage);

        if (! $messages) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

        return successResponse(
            MessageResource::collection($messages),
            __('chat.messages_retrieved')
        );
    }
}
