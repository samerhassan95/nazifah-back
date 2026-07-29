<?php

namespace Modules\Service\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Piece\Models\Piece;
use Spatie\Translatable\HasTranslations;

class Service extends Model
{
    use Cacheable;
    use HasFactory, HasTranslations;
    use HasSoftDeletes;

    protected static function booted()
    {
        static::saved(fn ($service) => self::clearCache($service));
        static::deleted(fn ($service) => self::clearCache($service));
    }

    public static function clearCache(Service $service): void
    {
        flushCacheTags(['services', 'categories']);
        if ($service->category_id) {
            flushCacheTags("category_{$service->category_id}");
        }
    }

    /**
     * Always eager load iconRelation for icon accessor
     */
    protected $with = ['iconRelation'];

    /**
     * Append computed attributes
     */
    protected $appends = ['icon'];

    protected $fillable = [
        'service_name',
        'description',
        'category_id',
        'image',
        'icon_id',
        'order',
        'is_active',
    ];

    public $translatable = [
        'service_name',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the icon attribute from the icon relation
     * Maps icon_id -> icons table -> path
     */
    public function getIconAttribute(): ?string
    {
        if ($this->iconRelation) {
            return $this->iconRelation->full_path;
        }

        return null;
    }

    public function additions(): BelongsToMany
    {
        return $this->belongsToMany(ServiceAddition::class, 'service_service_addition', 'service_id', 'service_addition_id')
            ->withTimestamps();
    }

    public function pieces(): BelongsToMany
    {
        return $this->belongsToMany(Piece::class, 'service_piece', 'service_id', 'piece_id')
            ->withPivot('branch_id', 'price')
            ->withTimestamps();
    }

    /**
     * Get branches that offer this service
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_service', 'service_id', 'branch_id')
            ->withPivot('icon_id', 'name', 'description', 'is_active')
            ->withTimestamps();
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\Vendor\Models\Vendor::class,
            'vendor_service',
            'service_id',
            'vendor_id'
        )->withPivot('name', 'description', 'icon_id', 'is_active')
            ->withTimestamps();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the icon associated with this service
     */
    public function iconRelation(): BelongsTo
    {
        return $this->belongsTo(\Modules\Admin\Models\Icon::class, 'icon_id');
    }

    /**
     * Get the price for a specific piece in this service
     */
    public function getPriceForPiece(int $pieceId): ?float
    {
        $piece = $this->pieces()->where('pieces.id', $pieceId)->first();

        if ($piece && $piece->pivot->price !== null) {
            return (float) $piece->pivot->price;
        }

        return null;
    }

    /**
     * Combined price for service + piece at a branch (service_piece only).
     * No fallback to branch_service.price or services.price — those are not piece prices.
     */
    public function getPriceForPieceAtBranch(int $pieceId, int $branchId): float
    {
        $price = $this->getPriceForPieceAtBranchOrNull($pieceId, $branchId);

        return $price !== null ? $price : 0.0;
    }

    /**
     * service_piece price for this service + piece + branch, or null if not configured.
     */
    public function getPriceForPieceAtBranchOrNull(int $pieceId, int $branchId): ?float
    {
        $branchSpecific = $this->pieces()
            ->where('pieces.id', $pieceId)
            ->wherePivot('branch_id', $branchId)
            ->first();
        if ($branchSpecific && $branchSpecific->pivot->price !== null) {
            return (float) $branchSpecific->pivot->price;
        }

        $vendorLevel = $this->pieces()
            ->where('pieces.id', $pieceId)
            ->whereNull('service_piece.branch_id')
            ->first();
        if ($vendorLevel && $vendorLevel->pivot->price !== null) {
            return (float) $vendorLevel->pivot->price;
        }

        return null;
    }

    /**
     * Services have no standalone branch price — only service_piece (service + piece + branch).
     */
    public function getPriceAtBranchOrNull(int $branchId): ?float
    {
        return null;
    }
}
