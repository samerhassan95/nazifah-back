<?php

namespace Modules\Piece\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Branch\Models\Branch;
use Modules\Service\Models\Service;
use Modules\Vendor\Models\Vendor;
use Spatie\Translatable\HasTranslations;

class Piece extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations;
    use HasSoftDeletes;

    protected static function booted()
    {
        static::saved(fn () => self::clearCache());
        static::deleted(fn () => self::clearCache());
    }

    public static function clearCache(): void
    {
        flushCacheTags(['pieces', 'branches']);
    }

    /**
     * Always eager load iconRelation for icon accessor
     */
    protected $with = ['iconRelation'];

    protected $fillable = [
        'vendor_id',
        'name',
        'icon_id',
        'is_active',
    ];

    public $translatable = ['name'];

    protected $casts = [
        'order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_piece', 'piece_id', 'service_id')
            ->withPivot('branch_id', 'price')
            ->withTimestamps();
    }

    /**
     * Get additional services (ServiceAddition) associated with this piece
     */
    public function additionalServices(): BelongsToMany
    {
        return $this->belongsToMany(\Modules\Service\Models\ServiceAddition::class, 'service_addition_piece', 'piece_id', 'service_addition_id')
            ->withPivot('branch_id', 'price')
            ->withTimestamps();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Get the icon associated with this piece
     */
    public function iconRelation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Admin\Models\Icon::class, 'icon_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_piece', 'piece_id', 'branch_id')
            ->withPivot('name', 'is_active')
            ->withTimestamps();
    }

    public function getDisplayNameAtBranch(int $branchId, string $lang): string
    {
        return \Modules\Piece\Support\PieceBranchOffering::displayNameForBranch($this, $branchId, $lang);
    }

    /**
     * Get active branches for this piece
     */
    public function activeBranches(): BelongsToMany
    {
        return $this->branches()
            ->where('branches.is_active', true)
            ->where('branch_piece.is_active', true);
    }

    /**
     * Additional services explicitly linked to this piece at a branch (service_addition_piece only).
     */
    public function additionalServicesAtBranch(int $branchId)
    {
        return \App\Support\CatalogActiveFilter::scopeActiveServiceAdditionsForPiece(
            $this->additionalServices()->with('iconRelation'),
            $branchId
        )->where('service_addition_piece.branch_id', $branchId);
    }

    /**
     * @deprecated Use additionalServicesAtBranch() — vendor-level null branch rows are excluded.
     */
    public function getAdditionalServicesForBranch(int $branchId)
    {
        return $this->additionalServicesAtBranch($branchId)->get();
    }

    /**
     * Get the price for this piece in a specific service (service_piece pivot).
     */
    public function getPriceForService(int $serviceId): ?float
    {
        $service = $this->services()->where('services.id', $serviceId)->first();

        if ($service && $service->pivot->price !== null) {
            return (float) $service->pivot->price;
        }

        return null;
    }
}
