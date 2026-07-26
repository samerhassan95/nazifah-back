<?php

namespace Modules\Chat\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Chat\Models\Conversation;

interface ConversationRepositoryInterface
{
    public function findByParticipants(int $clientId, ?int $vendorId = null, ?int $driverId = null): ?Conversation;

    public function create(array $data): Conversation;

    public function update(Conversation $conversation, array $data): bool;

    public function getClientConversations(int $clientId, int $perPage = 15): LengthAwarePaginator;

    public function getConversationById(string $id): ?Conversation;

    public function findForOrder(int $orderId, ?int $clientId = null, ?int $vendorId = null, ?int $driverId = null): ?Conversation;

    public function findVendorClientChat(int $vendorId, int $clientId, ?int $orderId = null): ?Conversation;

    public function findVendorDriverChat(int $vendorId, int $driverId): ?Conversation;

    public function findAdminChat(string $participantType, int $participantId): ?Conversation;

    public function getConversationsForParticipant(string $type, int $id, int $perPage = 20): LengthAwarePaginator;

    public function getAllConversations(int $perPage = 20): LengthAwarePaginator;
}
