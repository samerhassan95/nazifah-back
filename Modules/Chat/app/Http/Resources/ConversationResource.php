<?php

namespace Modules\Chat\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $request->user();
        if (! $user) {
            return parent::toArray($request);
        }

        // Determine current user role type and relevant ID
        $myRole = null;
        $myId = null;
        if ($user instanceof \Modules\Client\Models\Client) {
            $myRole = 'client';
            $myId = $user->id;
        } elseif ($user instanceof \Modules\Vendor\Models\VendorEmployee) {
            $myRole = 'vendor';
            $myId = $user->vendor_id;
        } elseif ($user instanceof \Modules\Driver\Models\Driver) {
            $myRole = 'driver';
            $myId = $user->id;
        } elseif ($user instanceof \Modules\Admin\Models\Admin) {
            $myRole = 'admin';
            $myId = $user->id;
        }

        $lastMsg = $this->lastMessage ? $this->lastMessage->first() : null;
        $senderInfo = $lastMsg ? $this->resolveSenderInfo($lastMsg, $myRole, $myId) : null;
        $lastMsgIsMe = $senderInfo['is_me'] ?? false;

        $uploadService = app(\App\Services\UploadFilesService::class);

        // Participation flags (from withExists)
        $hasClientMsg = (bool) ($this->has_client_participation ?? false);
        $hasVendorMsg = (bool) ($this->has_vendor_participation ?? false);
        $hasDriverMsg = (bool) ($this->has_driver_participation ?? false);

        return [
            'conversation_id' => $this->id,
            'order_id' => $this->order_id,
            'type' => $this->type,
            'status' => $this->status,
            'last_message' => $this->last_message,
            'last_message_at' => $this->last_message_at?->toISOString(),
            'last_message_sender' => $senderInfo, // Return sender info regardless of who it is
            'last_message_is_me' => $lastMsgIsMe,
            'unread_count' => $myRole ? $this->unreadMessagesCount($myRole, $myId) : 0,

            // Participant objects: Return if they exist in the conversation
            'client' => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->full_name,
                'image' => $uploadService->getFullUrl($this->client->image ?? null),
            ] : null,
            'vendor' => $this->vendor ? [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name ?? $this->vendor->getTranslatedName(app()->getLocale()),
                'image' => $uploadService->getFullUrl($this->vendor->image ?? $this->vendor->logo ?? null),
            ] : null,
            'driver' => $this->driver ? [
                'id' => $this->driver->id,
                'name' => $this->driver->full_name,
                'image' => $uploadService->getFullUrl($this->driver->image ?? null),
            ] : null,
            'admin' => $this->admin ? [
                'id' => $this->admin->id,
                'name' => $this->admin->name ?? $this->admin->full_name ?? 'Admin',
                'image' => $uploadService->getFullUrl($this->admin->image ?? null),
            ] : null,
        ];
    }

    private function resolveSenderInfo($message, $myRole, $myId): array
    {
        $type = $message->sender_type;
        $id = $message->sender_id;
        $isMe = ($type === $myRole && $id == $myId);

        $model = match ($type) {
            'client' => $this->client_id == $id ? $this->client : \Modules\Client\Models\Client::find($id),
            'vendor' => $this->vendor_id == $id ? $this->vendor : \Modules\Vendor\Models\Vendor::find($id),
            'driver' => $this->driver_id == $id ? $this->driver : \Modules\Driver\Models\Driver::find($id),
            'admin' => $this->admin_id == $id ? $this->admin : \Modules\Admin\Models\Admin::find($id),
            default => null,
        };

        $name = 'Unknown';
        $image = null;
        if ($model) {
            $name = match ($type) {
                'admin' => $model->name ?? $model->full_name ?? 'Admin',
                'vendor' => $model->name ?? 'Vendor',
                default => $model->full_name ?? $model->name ?? null,
            };
            $image = $model->image ?? $model->logo ?? null;
        }

        return [
            'type' => $type,
            'name' => $name,
            'image' => app(\App\Services\UploadFilesService::class)->getFullUrl($image),
            'is_me' => $isMe,
        ];
    }

    private function resolveChatWith($myRole, $uploadService): ?array
    {
        // Logic: Return the "Other" party.
        if ($myRole === 'driver') {
            if ($this->type === 'order' && $this->vendor_id) {
                return $this->formatParticipant('vendor', $this->vendor, $uploadService);
            }
            if ($this->client_id) {
                return $this->formatParticipant('client', $this->client, $uploadService);
            }
            if ($this->admin_id) {
                return $this->formatParticipant('admin', $this->admin, $uploadService);
            }
        }

        if ($myRole === 'vendor') {
            if ($this->driver_id) {
                return $this->formatParticipant('driver', $this->driver, $uploadService);
            }
            if ($this->client_id) {
                return $this->formatParticipant('client', $this->client, $uploadService);
            }
            if ($this->admin_id) {
                return $this->formatParticipant('admin', $this->admin, $uploadService);
            }
        }

        if ($myRole === 'client') {
            if ($this->vendor_id) {
                return $this->formatParticipant('vendor', $this->vendor, $uploadService);
            }
            if ($this->driver_id) {
                return $this->formatParticipant('driver', $this->driver, $uploadService);
            }
            if ($this->admin_id) {
                return $this->formatParticipant('admin', $this->admin, $uploadService);
            }
        }

        if ($myRole === 'admin') {
            if ($this->client_id) {
                return $this->formatParticipant('client', $this->client, $uploadService);
            }
            if ($this->vendor_id) {
                return $this->formatParticipant('vendor', $this->vendor, $uploadService);
            }
            if ($this->driver_id) {
                return $this->formatParticipant('driver', $this->driver, $uploadService);
            }
        }

        return null;
    }

    private function formatParticipant($type, $model, $uploadService): array
    {
        if (! $model) {
            return ['type' => $type, 'id' => null, 'name' => ucfirst($type), 'image' => null];
        }

        return [
            'type' => $type,
            'id' => $model->id,
            'name' => ($type === 'vendor' ? ($model->name ?? 'Vendor') : ($model->full_name ?? $model->name ?? 'User')),
            'image' => $uploadService->getFullUrl($model->image ?? $model->logo ?? null),
        ];
    }
}
