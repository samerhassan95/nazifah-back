<?php

namespace Modules\Branch\Models;

use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchWorkingHourShift extends Model
{
    use HasSoftDeletes;

    protected $fillable = [
        'branch_id',
        'day_of_week',
        'start_time',
        'end_time',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
