<?php

namespace Modules\Chat\Services;

use App\Services\FirebaseService as PushFirebaseService;
use Modules\Admin\Models\Admin;
use Modules\Chat\Events\MessageSent;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Repositories\ConversationRepositoryInterface;
use Modules\Chat\Repositories\MessageRepositoryInterface;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Order\Models\Order;
use Modules\Vendor\Models\Vendor;
use Modules\Vendor\Models\VendorEmployee;

class ChatService
{
    public function __construct(
        private ConversationRepositoryInterface $conversationRepository,
        private MessageRepositoryInterface $messageRepository,
        private FirebaseService $firebaseService,
        private PushFirebaseService $pushFirebaseService
    ) {}

    public function sendMessage(int $clientId, string $message, ?string $conversationId = null, string $type = 'support', ?int $vendorId = null, ?int $driverId = null, string $messageType = 'text', ?string $fileUrl = null, ?int $orderId = null): array
    {
        // If conversation_id is provided, use that conversation
        if ($conversationId) {
            $conversation = $this->conversationRepository->getConversationById($conversationId);

            // Verify the conversation belongs to the client
            if (! $conversation || $conversation->client_id !== $clientId) {
                throw new \Exception(__('chat.conversation_not_found'));
            }
        } elseif ($orderId !== null) {
            // Order-linked chat: Create separate conversations based on participants
            // - vendor_chat: client + vendor (driver_id = null)
            // - delivery_chat: client + driver (vendor_id = null)
            // - support: client only (vendor_id = null, driver_id = null)

            $conversation = $this->conversationRepository->findForOrder($orderId, $clientId, $vendorId, $driverId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'client_id' => $clientId,
                    'vendor_id' => $vendorId,
                    'driver_id' => $driverId,
                    'order_id' => $orderId,
                    'type' => $type,
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }

            // Update conversation type only when needed (e.g. first message sets it).
            if ($conversation && $conversation->type !== $type) {
                $this->conversationRepository->update($conversation, ['type' => $type]);
                $conversation->refresh();
            }
        } else {
            $conversation = $this->conversationRepository->create([
                'client_id' => $clientId,
                'vendor_id' => $vendorId,
                'driver_id' => $driverId,
                'order_id' => null,
                'type' => $type,
                'status' => 'active',
                'last_message' => $message,
                'last_message_at' => now(),
            ]);
        }

        // Create message
        $messageData = [
            'conversation_id' => $conversation->id,
            'sender_type' => 'client',
            'sender_id' => $clientId,
            'message' => $message,
            'type' => $messageType,
            'file_url' => $fileUrl,
        ];

        $newMessage = $this->messageRepository->create($messageData);

        // Update conversation
        $this->conversationRepository->update($conversation, [
            'last_message' => $message,
            'last_message_at' => now(),
        ]);

        // Send to Firebase
        $this->firebaseService->sendMessageToFirebase($conversation->id, $newMessage);

        // Broadcast for WebSocket real-time chat (Reverb/Pusher)
        MessageSent::dispatch($newMessage);

        $this->notifyRecipients($conversation, 'client', $newMessage);

        return [
            'conversation_id' => $conversation->id,
            'message' => $newMessage,
        ];
    }

    public function getConversations(int $clientId, int $perPage = 15)
    {
        return $this->conversationRepository->getClientConversations($clientId, $perPage);
    }

    public function getMessages(string $conversationId, int $clientId, int $perPage = 50)
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);

        if (! $conversation || $conversation->client_id !== $clientId) {
            return null;
        }

        // Mark messages as read
        $this->messageRepository->markAsRead($conversationId, 'client', $clientId);

        return $this->messageRepository->getConversationMessages($conversationId, $perPage);
    }

    public function getConversationWithMessages(string $conversationId, int $clientId, int $perPage = 50): ?Conversation
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);

        if (! $conversation || $conversation->client_id !== $clientId) {
            return null;
        }

        // Mark messages as read
        $this->messageRepository->markAsRead($conversationId, 'client', $clientId);

        // Load paginated messages
        $messages = $this->messageRepository->getConversationMessages($conversationId, $perPage);

        // Attach messages to conversation
        // Keep paginator instance so API can return pagination metadata
        $conversation->setRelation('messages', $messages);

        return $conversation;
    }

    /**
     * Get conversation for an order (for embedding in order APIs). Handles order_id null (support chats).
     */
    public function getConversationForOrder(int $orderId, ?int $clientId = null, ?int $vendorId = null, ?int $driverId = null): ?Conversation
    {
        return $this->conversationRepository->findForOrder($orderId, $clientId, $vendorId, $driverId);
    }

    /**
     * Ensure an order conversation exists.
     * - vendor chat: driver_id MUST be null
     * - delivery chat: vendor_id MUST be null
     *
     * Note: last_message is intentionally left null because this is a placeholder thread.
     */
    public function ensureConversationForOrder(int $orderId, int $clientId, ?int $vendorId = null, ?int $driverId = null): ?Conversation
    {
        $conversation = $this->conversationRepository->findForOrder($orderId, $clientId, $vendorId, $driverId);
        if ($conversation) {
            return $conversation;
        }

        return $this->conversationRepository->create([
            'client_id' => $clientId,
            'vendor_id' => $vendorId,
            'driver_id' => $driverId,
            'order_id' => $orderId,
            'type' => 'order',
            'status' => 'active',
            'last_message' => null,
            'last_message_at' => null,
        ]);
    }

    /**
     * Vendor: get conversation with messages. Conversation must have vendor_id = $vendorId (or order belongs to vendor).
     */
    public function getConversationWithMessagesForVendor(string $conversationId, int $vendorId, int $perPage = 50): ?Conversation
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation || (int) $conversation->vendor_id !== (int) $vendorId) {
            return null;
        }
        $this->messageRepository->markAsRead($conversationId, 'vendor', $vendorId);
        $messages = $this->messageRepository->getConversationMessages($conversationId, $perPage);
        // Keep paginator instance so API can return pagination metadata
        $conversation->setRelation('messages', $messages);

        return $conversation;
    }

    /**
     * Vendor: get messages only.
     */
    public function getMessagesForVendor(string $conversationId, int $vendorId, int $perPage = 50)
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation || (int) $conversation->vendor_id !== (int) $vendorId) {
            return null;
        }
        $this->messageRepository->markAsRead($conversationId, 'vendor', $vendorId);

        return $this->messageRepository->getConversationMessages($conversationId, $perPage);
    }

    /**
     * Vendor: send message in a conversation.
     */
    public function sendMessageFromVendor(int $vendorId, string $conversationId, string $message, string $messageType = 'text', ?string $fileUrl = null): array
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation || (int) $conversation->vendor_id !== (int) $vendorId) {
            throw new \Exception(__('chat.conversation_not_found'));
        }
        $newMessage = $this->messageRepository->create([
            'conversation_id' => $conversationId,
            'sender_type' => 'vendor',
            'sender_id' => $vendorId,
            'message' => $message,
            'type' => $messageType,
            'file_url' => $fileUrl,
        ]);
        $this->conversationRepository->update($conversation, [
            'last_message' => $message,
            'last_message_at' => now(),
        ]);
        $this->firebaseService->sendMessageToFirebase($conversation->id, $newMessage);
        MessageSent::dispatch($newMessage);
        $this->notifyRecipients($conversation, 'vendor', $newMessage);

        return ['conversation_id' => $conversation->id, 'message' => $newMessage];
    }

    /**
     * Driver: get conversation with messages. Conversation must have driver_id = $driverId.
     */
    public function getConversationWithMessagesForDriver(string $conversationId, int $driverId, int $perPage = 50): ?Conversation
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation || (int) $conversation->driver_id !== (int) $driverId) {
            return null;
        }
        $this->messageRepository->markAsRead($conversationId, 'driver', $driverId);
        $messages = $this->messageRepository->getConversationMessages($conversationId, $perPage);
        // Keep paginator instance so API can return pagination metadata
        $conversation->setRelation('messages', $messages);

        return $conversation;
    }

    /**
     * Driver: get messages only.
     */
    public function getMessagesForDriver(string $conversationId, int $driverId, int $perPage = 50)
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation || (int) $conversation->driver_id !== (int) $driverId) {
            return null;
        }
        $this->messageRepository->markAsRead($conversationId, 'driver', $driverId);

        return $this->messageRepository->getConversationMessages($conversationId, $perPage);
    }

    /**
     * Driver: send message in a conversation.
     */
    public function sendMessageFromDriver(int $driverId, string $conversationId, string $message, string $messageType = 'text', ?string $fileUrl = null): array
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation || (int) $conversation->driver_id !== (int) $driverId) {
            throw new \Exception(__('chat.conversation_not_found'));
        }
        $newMessage = $this->messageRepository->create([
            'conversation_id' => $conversationId,
            'sender_type' => 'driver',
            'sender_id' => $driverId,
            'message' => $message,
            'type' => $messageType,
            'file_url' => $fileUrl,
        ]);
        $this->conversationRepository->update($conversation, [
            'last_message' => $message,
            'last_message_at' => now(),
        ]);
        $this->firebaseService->sendMessageToFirebase($conversation->id, $newMessage);
        MessageSent::dispatch($newMessage);
        $this->notifyRecipients($conversation, 'driver', $newMessage);

        return ['conversation_id' => $conversation->id, 'message' => $newMessage];
    }

    /**
     * Admin: list all conversations in the system (any chat).
     */
    public function getAllConversationsForAdmin(int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->conversationRepository->getAllConversations($perPage);
    }

    /**
     * Admin: get any conversation with messages (no ownership check).
     */
    public function getConversationWithMessagesForAdmin(string $conversationId, int $perPage = 50): ?Conversation
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation) {
            return null;
        }
        $messages = $this->messageRepository->getConversationMessages($conversationId, $perPage);
        $conversation->setRelation('messages', $messages);

        return $conversation;
    }

    /**
     * Admin: get messages for any conversation.
     */
    public function getMessagesForAdmin(string $conversationId, int $perPage = 50)
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation) {
            return null;
        }

        return $this->messageRepository->getConversationMessages($conversationId, $perPage);
    }

    /**
     * Admin: send message in any conversation (sender_type = admin).
     */
    public function sendMessageFromAdmin(int $adminId, string $conversationId, string $message, string $messageType = 'text', ?string $fileUrl = null): array
    {
        $conversation = $this->conversationRepository->getConversationById($conversationId);
        if (! $conversation) {
            throw new \Exception(__('chat.conversation_not_found'));
        }
        $newMessage = $this->messageRepository->create([
            'conversation_id' => $conversationId,
            'sender_type' => 'admin',
            'sender_id' => $adminId,
            'message' => $message,
            'type' => $messageType,
            'file_url' => $fileUrl,
        ]);
        $this->conversationRepository->update($conversation, [
            'last_message' => $message,
            'last_message_at' => now(),
        ]);
        $this->firebaseService->sendMessageToFirebase($conversation->id, $newMessage);
        MessageSent::dispatch($newMessage);
        $this->notifyRecipients($conversation, 'admin', $newMessage);

        return ['conversation_id' => $conversation->id, 'message' => $newMessage];
    }

    /**
     * Vendor: smart send.
     * - conversation_id → use it
     * - order_id + clientId (no driverId) → find/create vendor↔client chat for order
     * - order_id + clientId + driverId    → find/create vendor↔driver chat for order
     * - driverId only (no order)         → find/create vendor↔driver direct chat
     * - neither → support chat with admin
     */
    public function vendorSend(int $vendorId, string $message, ?string $conversationId = null, ?int $orderId = null, ?int $clientId = null, string $messageType = 'text', ?string $fileUrl = null, ?int $driverId = null): array
    {
        if ($conversationId) {
            $conversation = $this->conversationRepository->getConversationById($conversationId);
            if (! $conversation || (int) $conversation->vendor_id !== $vendorId) {
                throw new \Exception(__('chat.conversation_not_found'));
            }
        } elseif ($orderId !== null && $driverId !== null) {
            // Vendor → driver for a specific order
            $conversation = $this->conversationRepository->findForOrder($orderId, $clientId, $vendorId, $driverId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'client_id' => $clientId,
                    'vendor_id' => $vendorId,
                    'driver_id' => $driverId,
                    'order_id' => $orderId,
                    'type' => 'order',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        } elseif ($driverId !== null) {
            // Vendor → driver without order
            $conversation = $this->conversationRepository->findVendorDriverChat($vendorId, $driverId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'vendor_id' => $vendorId,
                    'driver_id' => $driverId,
                    'type' => 'order',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        } elseif ($orderId !== null && $clientId !== null) {
            // Vendor → Client chat for this order
            $conversation = $this->conversationRepository->findForOrder($orderId, $clientId, $vendorId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'client_id' => $clientId,
                    'vendor_id' => $vendorId,
                    'order_id' => $orderId,
                    'type' => 'order',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        } elseif ($clientId !== null && $driverId === null) {
            // Vendor → Client (no order, or order_id null)
            $conversation = $this->conversationRepository->findVendorClientChat($vendorId, $clientId, $orderId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'client_id' => $clientId,
                    'vendor_id' => $vendorId,
                    'order_id' => $orderId,
                    'type' => $orderId !== null ? 'order' : 'order',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        } else {
            $conversation = $this->conversationRepository->findAdminChat('vendor', $vendorId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'vendor_id' => $vendorId,
                    'type' => 'support',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        }

        return $this->createAndBroadcast($conversation, 'vendor', $vendorId, $message, $messageType, $fileUrl);
    }

    /**
     * Driver: smart send. conversation_id → use it; order_id → find/create for order; neither → support chat.
     */
    public function driverSend(int $driverId, string $message, ?string $conversationId = null, ?int $orderId = null, ?int $clientId = null, ?int $vendorId = null, string $messageType = 'text', ?string $fileUrl = null): array
    {
        if ($conversationId) {
            $conversation = $this->conversationRepository->getConversationById($conversationId);
            if (! $conversation || (int) $conversation->driver_id !== $driverId) {
                throw new \Exception(__('chat.conversation_not_found'));
            }
        } elseif ($orderId !== null && $clientId !== null) {
            // Driver ↔ Client chat (vendor_id must be null for delivery chat)
            $conversation = $this->conversationRepository->findForOrder($orderId, $clientId, null, $driverId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'client_id' => $clientId,
                    'vendor_id' => null,
                    'driver_id' => $driverId,
                    'order_id' => $orderId,
                    'type' => 'order',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        } else {
            $conversation = $this->conversationRepository->findAdminChat('driver', $driverId);
            if (! $conversation) {
                $conversation = $this->conversationRepository->create([
                    'driver_id' => $driverId,
                    'type' => 'support',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                ]);
            }
        }

        return $this->createAndBroadcast($conversation, 'driver', $driverId, $message, $messageType, $fileUrl);
    }

    /**
     * Admin: smart send. conversation_id → use it; target_type+target_id → find/create support chat.
     */
    public function adminSend(int $adminId, string $message, ?string $conversationId = null, ?string $targetType = null, ?int $targetId = null, string $messageType = 'text', ?string $fileUrl = null): array
    {
        if ($conversationId) {
            $conversation = $this->conversationRepository->getConversationById($conversationId);
            if (! $conversation) {
                throw new \Exception(__('chat.conversation_not_found'));
            }
            if (! $conversation->admin_id) {
                $this->conversationRepository->update($conversation, ['admin_id' => $adminId]);
            }
        } elseif ($targetType && $targetId) {
            $conversation = $this->conversationRepository->findAdminChat($targetType, $targetId);
            if (! $conversation) {
                $data = [
                    'type' => 'support',
                    'status' => 'active',
                    'last_message' => $message,
                    'last_message_at' => now(),
                    'admin_id' => $adminId,
                ];
                $data["{$targetType}_id"] = $targetId;
                $conversation = $this->conversationRepository->create($data);
            } elseif (! $conversation->admin_id) {
                $this->conversationRepository->update($conversation, ['admin_id' => $adminId]);
            }
        } else {
            throw new \Exception('conversation_id or target_type+target_id required');
        }

        return $this->createAndBroadcast($conversation, 'admin', $adminId, $message, $messageType, $fileUrl);
    }

    /**
     * Create message, update conversation, broadcast.
     */
    private function createAndBroadcast(Conversation $conversation, string $senderType, int $senderId, string $message, string $messageType, ?string $fileUrl): array
    {
        $newMessage = $this->messageRepository->create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'message' => $message,
            'type' => $messageType,
            'file_url' => $fileUrl,
        ]);

        $this->conversationRepository->update($conversation, [
            'last_message' => $message,
            'last_message_at' => now(),
        ]);

        $this->firebaseService->sendMessageToFirebase($conversation->id, $newMessage);
        MessageSent::dispatch($newMessage);
        $this->notifyRecipients($conversation, $senderType, $newMessage);

        return ['conversation_id' => $conversation->id, 'message' => $newMessage];
    }

    /**
     * Push a "new message" FCM notification to the other side(s) of the conversation
     * (never to the sender). Best-effort: a delivery failure must never break sending
     * the chat message itself.
     */
    private function notifyRecipients(Conversation $conversation, string $senderType, Message $message): void
    {
        try {
            $body = trim((string) $message->message);
            if ($body === '') {
                $body = match ($message->type) {
                    'image' => __('chat.notification_image_placeholder'),
                    'file' => __('chat.notification_file_placeholder'),
                    default => '',
                };
            }

            $payload = [
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $message->id,
                'sender_id' => (string) $message->sender_id,
                'sender_type' => $senderType,
                'sender_name' => $this->resolveSenderName($senderType, (int) $message->sender_id),
                'message' => $body,
            ];

            foreach ($this->resolveRecipientTokens($conversation, $senderType) as $token) {
                $this->pushFirebaseService->sendChatNotification($token, $payload);
            }
        } catch (\Throwable) {
            // Chat push notifications are best-effort; never fail message sending.
        }
    }

    /**
     * @return list<string>
     */
    private function resolveRecipientTokens(Conversation $conversation, string $senderType): array
    {
        $tokens = [];

        if ($senderType !== 'client' && $conversation->client_id) {
            $client = Client::find($conversation->client_id);
            if ($client) {
                $tokens = array_merge($tokens, $client->getFcmTokenStrings());
            }
        }

        if ($senderType !== 'vendor' && $conversation->vendor_id) {
            $branchId = $conversation->order_id
                ? Order::find($conversation->order_id)?->branch_id
                : null;

            $employees = $branchId
                ? VendorEmployee::notifiableForOrderBranch((int) $conversation->vendor_id, (int) $branchId)
                : VendorEmployee::where('vendor_id', $conversation->vendor_id)->active()->get();

            foreach ($employees as $employee) {
                $tokens = array_merge($tokens, $employee->getFcmTokenStrings());
            }
        }

        if ($senderType !== 'driver' && $conversation->driver_id) {
            $driver = Driver::find($conversation->driver_id);
            if ($driver) {
                $tokens = array_merge($tokens, $driver->getFcmTokenStrings());
            }
        }

        if ($senderType !== 'admin' && $conversation->admin_id) {
            $admin = Admin::find($conversation->admin_id);
            if ($admin) {
                $tokens = array_merge($tokens, $admin->getFcmTokenStrings());
            }
        }

        return array_values(array_unique($tokens));
    }

    private function resolveSenderName(string $senderType, int $senderId): ?string
    {
        $name = match ($senderType) {
            'client' => Client::find($senderId)?->getTranslation('full_name', 'ar'),
            'vendor' => Vendor::find($senderId)?->getTranslatedName('ar'),
            'driver' => Driver::find($senderId)?->getTranslation('full_name', 'ar'),
            'admin' => Admin::find($senderId)?->name,
            default => null,
        };

        return is_string($name) && trim($name) !== '' ? $name : null;
    }
}
