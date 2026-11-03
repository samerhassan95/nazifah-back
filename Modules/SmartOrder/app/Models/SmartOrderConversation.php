<?php

namespace Modules\SmartOrder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Client\Models\Client;
use Modules\Vendor\Models\Vendor;

class SmartOrderConversation extends Model
{
    public const STATUS_NEEDS_CLARIFICATION = 'needs_clarification';

    public const STATUS_READY = 'ready';

    public const STATUS_NOT_UNDERSTOOD = 'not_understood';

    public const STATUS_CONFIRMED = 'confirmed';

    public const SELECTION_USER = 'user';

    public const SELECTION_AUTO_SINGLE = 'auto_single';

    public const SELECTION_PICKED = 'picked';

    protected $fillable = [
        'client_id',
        'vendor_id',
        'branch_id',
        'laundry_selection',
        'status',
        'messages',
        'draft_lines',
        'estimated_total',
        'pending_question',
        'last_reply',
        'last_activity_at',
        'confirmed_at',
    ];

    protected $casts = [
        'messages' => 'array',
        'draft_lines' => 'array',
        'estimated_total' => 'decimal:2',
        'pending_question' => 'array',
        'last_activity_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * 24h of inactivity invalidates the conversation — the app starts a new one.
     */
    public function isExpired(): bool
    {
        if (! $this->last_activity_at) {
            return false;
        }

        return $this->last_activity_at->lt(now()->subHours(24));
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
