<?php

namespace Modules\Chat\Http\Resources;

use App\Services\UploadFilesService;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray($request): array
    {
        $uploadService = app(UploadFilesService::class);
        $sender = $this->resolveSender();
        $isMe = $this->checkIsMe();

        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'sender_id' => $this->sender_id,
            'sender_name' => $sender['name'] ?? null,
            'sender_image' => $uploadService->getFullUrl($sender['image'] ?? null),
            'is_me' => $isMe,
            'message' => $this->message,
            'type' => $this->type,
            'file_url' => $uploadService->getFullUrl($this->file_url),
            'is_read' => (bool) $this->is_read,
            'read_at' => $this->read_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }

    private function checkIsMe(): bool
    {
        $user = request()->user();
        if (! $user) {
            return false;
        }

        $myType = null;
        $myId = null;
        if ($user instanceof \Modules\Client\Models\Client) {
            $myType = 'client';
            $myId = $user->id;
        } elseif ($user instanceof \Modules\Vendor\Models\VendorEmployee) {
            $myType = 'vendor';
            $myId = $user->vendor_id;
        } elseif ($user instanceof \Modules\Driver\Models\Driver) {
            $myType = 'driver';
            $myId = $user->id;
        } elseif ($user instanceof \Modules\Admin\Models\Admin) {
            $myType = 'admin';
            $myId = $user->id;
        }

        if (! $myType) {
            return false;
        }

        return $this->sender_type === $myType && $this->sender_id == $myId;
    }

    private function resolveSender(): array
    {
        $type = $this->sender_type;
        $id = $this->sender_id;

        if (! $type || ! $id) {
            return ['name' => null, 'image' => null];
        }

        $model = match ($type) {
            'client' => \Modules\Client\Models\Client::find($id),
            'vendor' => \Modules\Vendor\Models\Vendor::find($id),
            'driver' => \Modules\Driver\Models\Driver::find($id),
            'admin' => \Modules\Admin\Models\Admin::find($id),
            default => null,
        };

        if (! $model) {
            return ['name' => ucfirst($type), 'image' => null];
        }

        $name = match ($type) {
            'admin' => $model->name ?? $model->full_name ?? 'Admin',
            'vendor' => $model->name ?? $model->getTranslatedName(app()->getLocale()) ?? 'Vendor',
            default => $model->full_name ?? $model->name ?? null,
        };

        return [
            'name' => $name,
            'image' => $model->image ?? $model->logo ?? null,
        ];
    }
}
