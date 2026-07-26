<?php

namespace Modules\Vendor\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class VendorEmployeeBranchAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_employee_id',
        'branch_id',
        'vendor_role_id',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(VendorEmployee::class, 'vendor_employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(VendorRole::class, 'vendor_role_id');
    }
}
