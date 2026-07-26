<?php

namespace Modules\Notification\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Notification\Enums\UserTargetType;

class MarketingNotification extends Model
{
    use Cacheable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'notification_title',
        'description',
        'user_target_type',
        'target_user_ids',
        'sending_date',
        'sending_time',
        'is_sent',
        'sent_at',
        'created_by',
        'status',
        'scheduled_at',
        'deep_link',
        'image_url',
        'segment_filters',
        'total_recipients',
        'sent_count',
        'read_count',
        'failed_count',
    ];

    protected $casts = [
        'target_user_ids' => 'array',
        'sending_date' => 'date',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
        'user_target_type' => UserTargetType::class,
        'segment_filters' => 'array',
        'scheduled_at' => 'datetime',
    ];

    public function notificationLogs()
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get the admin who created this notification
     */
    public function creator()
    {
        return $this->belongsTo(\Modules\Admin\Models\Admin::class, 'created_by');
    }

    /**
     * Check if notification should be sent now
     */
    public function shouldSendNow(): bool
    {
        if ($this->is_sent) {
            return false;
        }

        $scheduledDateTime = $this->sending_date->format('Y-m-d').' '.$this->sending_time;
        $scheduledTimestamp = strtotime($scheduledDateTime);

        return $scheduledTimestamp <= time();
    }

    /**
     * Mark notification as sent
     */
    public function markAsSent(): void
    {
        $this->update([
            'is_sent' => true,
            'sent_at' => now(),
        ]);
    }

    /**
     * Get target users based on user_target_type and target_user_ids
     */
    public function getTargetUsers()
    {
        if ($this->user_target_type === UserTargetType::ALL) {
            // Get all users from all types
            $clients = \Modules\Client\Models\Client::where('is_active', true)->get();
            $drivers = \Modules\Driver\Models\Driver::where('is_active', true)->get();
            $vendors = \Modules\Vendor\Models\Vendor::where('is_active', true)->get();

            return collect([
                'clients' => $clients,
                'drivers' => $drivers,
                'vendors' => $vendors,
            ]);
        }

        $modelClass = $this->user_target_type->getModelClass();

        if (empty($this->target_user_ids) || in_array('all', $this->target_user_ids)) {
            // Get all users of this type
            return $modelClass::where('is_active', true)->get();
        }

        // Get specific users
        return $modelClass::whereIn('id', $this->target_user_ids)
            ->where('is_active', true)
            ->get();
    }
}
