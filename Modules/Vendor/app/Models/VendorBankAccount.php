<?php

namespace Modules\Vendor\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Bank;
use Modules\Branch\Models\Branch;

class VendorBankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'bank_id',
        'branch_id',
        'bank_name',
        'iban_number',
        'account_holder',
        'iban_document',
        'status',
        'rejection_reason',
        'vendor_status',
        'vendor_rejection_reason',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * A branch-private account (needs vendor approval), as opposed to a
     * general vendor-wide account that only needs admin approval.
     */
    public function isBranchAccount(): bool
    {
        return $this->branch_id !== null;
    }

    /**
     * Final approval state surfaced to clients: an account is usable only when
     * the admin approved it (and, for branch accounts, the vendor approved too).
     */
    public function isFullyApproved(): bool
    {
        return $this->status === 'approved'
            && (! $this->isBranchAccount() || $this->vendor_status === 'approved');
    }
}
