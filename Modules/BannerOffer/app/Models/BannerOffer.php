<?php

namespace Modules\BannerOffer\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\BannerOffer\Enums\BannerDestinationType;
use Modules\Client\Models\Client;
use Modules\Driver\Models\Driver;
use Modules\Notification\Enums\UserTargetType;
use Modules\Vendor\Models\Vendor;
use Spatie\Translatable\HasTranslations;

class BannerOffer extends Model
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
        flushCacheTags(['banners']);
    }

    protected $fillable = [
        'title',
        'description',
        'image',
        'link',
        'destination_type',
        'destination_id',
        'user_target_type',
        'target_user_ids',
        'order',
        'is_active',
        'start_date',
        'end_date',
    ];

    public $translatable = ['title', 'description'];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'target_user_ids' => 'array',
        'destination_type' => BannerDestinationType::class,
    ];

    public function resolveDestinationName(?string $locale = null): ?string
    {
        if (! $this->destination_type) {
            return null;
        }

        if ($this->destination_type === BannerDestinationType::EXTERNAL_URL) {
            return $this->link;
        }

        if (! $this->destination_id) {
            return null;
        }

        $modelClass = $this->destination_type->getModelClass();
        if (! $modelClass) {
            return null;
        }

        $record = $modelClass::find($this->destination_id);
        if (! $record) {
            return null;
        }

        $locale = $locale ?? app()->getLocale();

        if (method_exists($record, 'getTranslation')) {
            return $record->getTranslation('name', $locale)
                ?: $record->getTranslation('title', $locale);
        }

        return $record->full_name ?? $record->name ?? null;
    }

    /**
     * @return array<int, array{id: int, name: string, label: string}>
     */
    public function resolveTargetUsers(?string $locale = null): array
    {
        $locale = $locale ?? app()->getLocale();
        $targetType = $this->user_target_type;

        if ($targetType === UserTargetType::ALL->value || $targetType === 'all' || empty($targetType)) {
            return [[
                'id' => 0,
                'name' => __('banner.all_users'),
                'label' => '1- '.__('banner.all_users'),
            ]];
        }

        $ids = $this->target_user_ids ?? [];
        if (empty($ids)) {
            return [];
        }

        $modelClass = match ($targetType) {
            UserTargetType::CLIENT->value, 'client' => Client::class,
            UserTargetType::VENDOR->value, 'vendor' => Vendor::class,
            UserTargetType::DRIVER->value, 'driver' => Driver::class,
            default => null,
        };

        if (! $modelClass) {
            return [];
        }

        $records = $modelClass::whereIn('id', $ids)->get()->keyBy('id');
        $users = [];

        foreach (array_values($ids) as $index => $id) {
            $record = $records->get($id);
            if (! $record) {
                continue;
            }

            $name = $this->resolveUserDisplayName($record, $locale);
            $position = $index + 1;
            $users[] = [
                'id' => (int) $id,
                'name' => $name,
                'label' => "{$position}- {$name}",
            ];
        }

        return $users;
    }

    private function resolveUserDisplayName(Model $record, string $locale): string
    {
        if ($record instanceof Client) {
            return $record->getTranslation('full_name', $locale) ?: 'Customer';
        }

        if (method_exists($record, 'getTranslation')) {
            return $record->getTranslation('name', $locale) ?: (string) ($record->name ?? 'User');
        }

        return (string) ($record->name ?? $record->full_name ?? 'User');
    }

    /**
     * Get the full URL for the image attribute.
     */
    public function getImageAttribute($value)
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return config('app.url').$value;
    }
}
