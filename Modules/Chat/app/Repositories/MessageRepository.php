<?php

namespace Modules\Chat\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Chat\Models\Message;

class MessageRepository implements MessageRepositoryInterface
{
    public function create(array $data): Message
    {
        return Message::create($data);
    }

    public function getConversationMessages(string $conversationId, int $perPage = 50): LengthAwarePaginator
    {
        return Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function markAsRead(string $conversationId, string $senderType, int $senderId): int
    {
        return Message::where('conversation_id', $conversationId)
            ->where('is_read', false)
            ->where(function ($query) use ($senderType, $senderId) {
                $query->where('sender_type', '!=', $senderType)
                    ->orWhere('sender_id', '!=', $senderId);
            })
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }
}
