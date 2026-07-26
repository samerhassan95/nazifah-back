<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\UploadFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Chat\Http\Resources\ConversationWithMessagesResource;
use Modules\Chat\Services\ChatService;

class AdminChatsController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private UploadFilesService $uploadService
    ) {}

    /**
     * List all conversations in the system (admin can see any chat).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 20);
        $conversations = $this->chatService->getAllConversationsForAdmin($perPage);

        $items = $conversations->getCollection()->map(function ($conversation) {
            return [
                'id' => $conversation->id,
                'client_id' => $conversation->client_id,
                'vendor_id' => $conversation->vendor_id,
                'driver_id' => $conversation->driver_id,
                'order_id' => $conversation->order_id,
                'type' => $conversation->type,
                'status' => $conversation->status,
                'last_message' => $conversation->last_message,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
                'client' => $conversation->client ? [
                    'id' => $conversation->client->id,
                    'name' => is_array($conversation->client->full_name ?? null)
                        ? ($conversation->client->full_name[app()->getLocale()] ?? $conversation->client->full_name['en'] ?? '')
                        : ($conversation->client->full_name ?? ''),
                ] : null,
                'vendor' => $conversation->vendor ? [
                    'id' => $conversation->vendor->id,
                    'name' => $conversation->vendor->getTranslatedName(app()->getLocale()),
                ] : null,
                'driver' => $conversation->driver ? [
                    'id' => $conversation->driver->id,
                    'name' => is_array($conversation->driver->full_name ?? null)
                        ? ($conversation->driver->full_name[app()->getLocale()] ?? $conversation->driver->full_name['en'] ?? '')
                        : ($conversation->driver->full_name ?? ''),
                ] : null,
            ];
        });

        return successResponse([
            'data' => $items,
            'pagination' => [
                'current_page' => $conversations->currentPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
                'last_page' => $conversations->lastPage(),
            ],
        ], __('chat.conversations_retrieved'));
    }

    /**
     * Get one conversation with messages (admin can open any chat).
     */
    public function show(Request $request, string $conversationId): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 50);
        $conversation = $this->chatService->getConversationWithMessagesForAdmin($conversationId, $perPage);

        if (! $conversation) {
            return notFoundResponse(__('chat.conversation_not_found'));
        }

        return successResponse(
            new ConversationWithMessagesResource($conversation),
            __('chat.messages_retrieved')
        );
    }

    /**
     * Get messages for a conversation (admin can view any chat messages).
     */
    public function getMessages(Request $request, string $conversationId): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 50);
        $messages = $this->chatService->getMessagesForAdmin($conversationId, $perPage);

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
     * - target_type + target_id provided (no conversation_id) → find/create support chat with that user
     */
    public function sendMessage(Request $request, ?string $conversationId = null): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:5000'],
            'conversation_id' => ['nullable', 'string'],
            'target_type' => ['nullable', 'string', 'in:client,vendor,driver'],
            'target_id' => ['nullable', 'integer'],
            'message_type' => ['nullable', 'string', 'in:text,image,file'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        $admin = $request->user();

        $convId = $conversationId ?? $request->conversation_id;

        $fileUrl = null;
        if ($request->hasFile('file')) {
            $fileUrl = $this->uploadService->uploadChatFile($request->file('file'));
        }

        try {
            $result = $this->chatService->adminSend(
                $admin->id,
                $request->message,
                $convId,
                $request->target_type,
                $request->target_id ? (int) $request->target_id : null,
                $request->message_type ?? 'text',
                $fileUrl
            );
        } catch (\Exception $e) {
            return notFoundResponse($e->getMessage());
        }

        $conversation = $this->chatService->getConversationWithMessagesForAdmin(
            $result['conversation_id'],
            $request->get('per_page', 50)
        );

        return successResponse(
            new ConversationWithMessagesResource($conversation),
            __('chat.message_sent')
        );
    }
}
