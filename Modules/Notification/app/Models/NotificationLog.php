<?php

namespace Modules\Notification\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use Cacheable;
    use HasFactory;

    protected $fillable = [
        'marketing_notification_id',
        'user_id',
        'status',
        'delivered_at',
        'read_at',
        'device_token',
        'failure_reason',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function marketingNotification(): BelongsTo
    {
        return $this->belongsTo(MarketingNotification::class);
    }
}
