<?php

namespace Modules\Service\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Piece\Models\Piece;
use Modules\Service\Support\ServiceAdditionBranchOffering;
use Spatie\Translatable\HasTranslations;

class ServiceAddition extends Model
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
        flushCacheTags(['services', 'branches']);
    }

    protected $fillable = [
        'vendor_id',
        'name',
        'price',
        'icon_id',
        'is_active',
    ];

    public $translatable = [
        'name',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(\Modules\Vendor\Models\Vendor::class);
    }

    /**
     * Get the icon associated with this service addition
     */
    public function iconRelation()
    {
        return $this->belongsTo(\Modules\Admin\Models\Icon::class, 'icon_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_service_addition', 'service_addition_id', 'service_id')
            ->withTimestamps();
    }

    /**
     * Get pieces that are associated with this additional service
     */
    public function pieces(): BelongsToMany
    {
        return $this->belongsToMany(Piece::class, 'service_addition_piece', 'service_addition_id', 'piece_id')
            ->withPivot('branch_id', 'price')
            ->withTimestamps();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(\Modules\Branch\Models\Branch::class, 'branch_service_addition', 'service_addition_id', 'branch_id')
            ->withPivot('name', 'price', 'icon_id', 'is_active')
            ->withTimestamps();
    }

    /**
     * Effective price: branch_service_addition → service_addition_piece → catalog.
     * Branch offering wins when set (matches vendor additional-services?branch_id=).
     */
    public function getPriceForPieceAtBranch(int $pieceId, int $branchId): float
    {
        $branchPrice = ServiceAdditionBranchOffering::priceForBranch($branchId, $this);
        if ($branchPrice !== null) {
            return $branchPrice;
        }

        $piece = $this->pieces()
            ->where('pieces.id', $pieceId)
            ->wherePivot('branch_id', $branchId)
            ->first();

        if ($piece && $piece->pivot->price !== null) {
            return (float) $piece->pivot->price;
        }

        return (float) ($this->price ?? 0);
    }

    public function getDisplayNameAtBranch(int $branchId, string $lang): string
    {
        return ServiceAdditionBranchOffering::displayNameForBranch($this, $branchId, $lang);
    }

    public function getCatalogPriceOrNull(): ?float
    {
        return $this->price !== null ? (float) $this->price : null;
    }

    /**
     * Price for linking at a branch: branch offering only (never reads other branches).
     */
    public function resolveBranchAssignmentPrice(int $branchId): ?float
    {
        return ServiceAdditionBranchOffering::priceForBranch($branchId, $this);
    }
}
