<?php

namespace Modules\Chat\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Chat\Models\Conversation;

class ConversationRepository implements ConversationRepositoryInterface
{
    public function findByParticipants(int $clientId, ?int $vendorId = null, ?int $driverId = null): ?Conversation
    {
        return Conversation::where('client_id', $clientId)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->when($driverId, fn ($q) => $q->where('driver_id', $driverId))
            ->when(! $vendorId && ! $driverId, fn ($q) => $q->whereNull('vendor_id')->whereNull('driver_id'))
            ->first();
    }

    public function create(array $data): Conversation
    {
        return Conversation::create($data);
    }

    public function update(Conversation $conversation, array $data): bool
    {
        return $conversation->update($data);
    }

    public function getClientConversations(int $clientId, int $perPage = 15): LengthAwarePaginator
    {
        return Conversation::where('client_id', $clientId)
            ->has('messages')
            ->with(['vendor', 'driver', 'admin', 'lastMessage'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->orderBy('last_message_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    public function getConversationById(string $id): ?Conversation
    {
        return Conversation::with(['messages' => function ($query) {
            $query->orderBy('created_at', 'asc');
        }, 'client', 'vendor', 'driver', 'admin', 'order'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->find($id);
    }

    public function findForOrder(int $orderId, ?int $clientId = null, ?int $vendorId = null, ?int $driverId = null): ?Conversation
    {
        $q = Conversation::where('order_id', $orderId);
        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($vendorId !== null) {
            $q->where('vendor_id', $vendorId);
        }
        if ($vendorId === null) {
            $q->whereNull('vendor_id');
        }
        if ($driverId !== null) {
            $q->where('driver_id', $driverId);
        } else {
            $q->whereNull('driver_id');
        }

        return $q->with(['vendor', 'driver', 'client', 'order'])->first();
    }

    public function findVendorClientChat(int $vendorId, int $clientId, ?int $orderId = null): ?Conversation
    {
        $q = Conversation::where('vendor_id', $vendorId)
            ->where('client_id', $clientId)
            ->whereNull('driver_id');

        if ($orderId !== null) {
            $q->where('order_id', $orderId);
        } else {
            $q->whereNull('order_id');
        }

        return $q->with(['vendor', 'driver', 'client', 'order'])->first();
    }

    /**
     * Vendor ↔ driver chat without order (no client on thread).
     */
    public function findVendorDriverChat(int $vendorId, int $driverId): ?Conversation
    {
        return Conversation::where('vendor_id', $vendorId)
            ->where('driver_id', $driverId)
            ->whereNull('order_id')
            ->whereNull('client_id')
            ->with(['vendor', 'driver', 'client', 'order'])
            ->first();
    }

    /**
     * Find existing support chat between a participant and admin (no order).
     */
    public function findAdminChat(string $participantType, int $participantId): ?Conversation
    {
        $column = match ($participantType) {
            'client' => 'client_id',
            'vendor' => 'vendor_id',
            'driver' => 'driver_id',
            default => null,
        };
        if (! $column) {
            return null;
        }

        return Conversation::where($column, $participantId)
            ->where('type', 'support')
            ->whereNull('order_id')
            ->with(['client', 'vendor', 'driver', 'admin', 'order'])
            ->first();
    }

    /**
     * Get conversations where a participant is involved (by type and id).
     */
    public function getConversationsForParticipant(string $type, int $id, int $perPage = 20): LengthAwarePaginator
    {
        $column = match ($type) {
            'client' => 'client_id',
            'vendor' => 'vendor_id',
            'driver' => 'driver_id',
            default => null,
        };
        if (! $column) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return Conversation::where($column, $id)
            ->has('messages')
            ->with(['client', 'vendor', 'driver', 'admin', 'order', 'lastMessage'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->orderBy('last_message_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Admin: get all conversations in the system (no ownership filter).
     */
    public function getAllConversations(int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::has('messages')
            ->with(['client', 'vendor', 'driver', 'admin', 'order', 'lastMessage'])
            ->withExists(['messages as has_client_participation' => fn ($q) => $q->where('sender_type', 'client')])
            ->withExists(['messages as has_vendor_participation' => fn ($q) => $q->where('sender_type', 'vendor')])
            ->withExists(['messages as has_driver_participation' => fn ($q) => $q->where('sender_type', 'driver')])
            ->orderBy('last_message_at', 'desc')
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }
}
