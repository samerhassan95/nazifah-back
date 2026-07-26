<?php

namespace Modules\Notification\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Client\Models\Client;
use Modules\Notification\Enums\NotificationType;
use Spatie\Translatable\HasTranslations;

class Notification extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations;
    use HasSoftDeletes;

    protected $fillable = [
        'user_id',
        'user_type',
        'title',
        'message',
        'type',
        'notification_type',
        'image',
        'is_read',
        'read_at',
        'data',
    ];

    public $translatable = ['title', 'message'];

    protected $casts = [
        'image' => 'string',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'notification_type' => NotificationType::class,
        'data' => 'array',
    ];

    public function user()
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'user_id')->where('user_type', 'client');
    }

    public function markAsRead(): void
    {
        if (! $this->read_at) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Scope to filter by notification type
     */
    public function scopeByType($query, NotificationType $type)
    {
        return $query->where('notification_type', $type->value);
    }

    /**
     * Scope to filter by user
     */
    public function scopeForUser($query, int $userId, string $userType)
    {
        return $query->where('user_id', $userId)
            ->where('user_type', $userType);
    }

    /**
     * Order-related fields for API responses (matches FCM push payload).
     * Used by client, vendor, driver, and admin notification endpoints.
     *
     * @return array{
     *     order_id: ?int,
     *     order_number: ?string,
     *     order_status: ?string,
     *     notification_type: ?string
     * }
     */
    public function orderMetaForApi(): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'order_id' => isset($data['order_id']) ? (int) $data['order_id'] : null,
            'order_number' => $data['order_number'] ?? null,
            'order_status' => $data['order_status'] ?? null,
            'notification_type' => $data['notification_type'] ?? null,
        ];
    }
}
