<?php

namespace Modules\Chat\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Vendor\Models\Vendor;

class Conversation extends Model
{
    use Cacheable;
    use HasSoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::uuid();
            }
        });
    }

    protected $fillable = [
        'client_id',
        'vendor_id',
        'driver_id',
        'admin_id',
        'order_id',
        'type',
        'status',
        'last_message',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest('created_at')->limit(1);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Modules\Order\Models\Order::class, 'order_id');
    }

    public function unreadMessagesCount(string $senderType, int $senderId): int
    {
        return $this->messages()
            ->where('is_read', false)
            ->where(function ($query) use ($senderType, $senderId) {
                $query->where('sender_type', '!=', $senderType)
                    ->orWhere('sender_id', '!=', $senderId);
            })
            ->count();
    }
}
