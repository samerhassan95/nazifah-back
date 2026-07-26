<?php

namespace Modules\Whatsapp\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappCampaign extends Model
{
    use Cacheable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'whatsapp_template_id',
        'target_type',
        'whatsapp_group_id',
        'user_ids',
        'scheduled_at',
        'sent_at',
        'status',
        'total_recipients',
        'sent_count',
        'delivered_count',
        'read_count',
        'failed_count',
        'variable_values',
    ];

    protected $casts = [
        'user_ids' => 'array',
        'variable_values' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WhatsappGroup::class, 'whatsapp_group_id');
    }

    public function getDeliveryRateAttribute(): float
    {
        if ($this->total_recipients > 0) {
            return round(($this->delivered_count / $this->total_recipients) * 100, 2);
        }

        return 0.0;
    }

    public function getReadRateAttribute(): float
    {
        if ($this->delivered_count > 0) {
            return round(($this->read_count / $this->delivered_count) * 100, 2);
        }

        return 0.0;
    }
}
