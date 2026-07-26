<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Admin;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Vendor\Models\Vendor;

class Conversation extends Model
{
    protected $fillable = [
        'client_id',
        'vendor_id',
        'driver_id',
        'admin_id',
        'type',
        'last_message',
        'last_message_at',
        'is_active',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'is_active' => 'boolean',
    ];

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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function unreadMessagesCount($userId, $userType)
    {
        return $this->messages()
            ->where('is_read', false)
            ->where(function ($query) use ($userId, $userType) {
                $query->where('sender_type', '!=', $userType)
                    ->orWhere('sender_id', '!=', $userId);
            })
            ->count();
    }
}
