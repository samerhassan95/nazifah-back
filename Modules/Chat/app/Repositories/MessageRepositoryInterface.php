<?php

namespace Modules\Chat\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Chat\Models\Message;

interface MessageRepositoryInterface
{
    public function create(array $data): Message;

    public function getConversationMessages(string $conversationId, int $perPage = 50): LengthAwarePaginator;

    public function markAsRead(string $conversationId, string $senderType, int $senderId): int;
}
