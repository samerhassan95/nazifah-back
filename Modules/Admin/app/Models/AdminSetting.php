<?php

namespace Modules\Admin\Models;

use App\Cache\CacheManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Cache\AdminSettingCacheKey;

class AdminSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'type',
        'value',
    ];

    // Setting types
    const TYPE_TEXT = 'text';

    const TYPE_NUMBER = 'number';

    const TYPE_BOOLEAN = 'boolean';

    const TYPE_JSON = 'json';

    const TYPE_IMAGE = 'image';

    /**
     * Get setting value by key with caching
     */
    public static function getValue(string $key, $default = null)
    {
        return CacheManager::remember(
            key: AdminSettingCacheKey::byKey($key),
            ttl: CacheManager::TTL_LONG,
            callback: function () use ($key, $default) {
                $setting = self::where('key', $key)->first();

                if (! $setting) {
                    return $default;
                }

                return $setting->getTypedValue();
            },
            tags: ['admin_settings']
        );
    }

    /**
     * Set setting value by key
     */
    public static function setValue(string $key, $value, string $type = self::TYPE_TEXT): self
    {
        $setting = self::firstOrNew(['key' => $key]);
        $setting->type = $type;

        // Convert value based on type
        if ($type === self::TYPE_BOOLEAN) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        } elseif ($type === self::TYPE_JSON) {
            $value = is_string($value) ? $value : json_encode($value);
        } else {
            $value = (string) $value;
        }

        $setting->value = $value;
        $setting->save();

        // Observer will handle cache cleanup
        return $setting;
    }

    /**
     * Get value converted to appropriate type
     */
    public function getTypedValue()
    {
        if ($this->value === null) {
            return null;
        }

        switch ($this->type) {
            case self::TYPE_NUMBER:
                return is_numeric($this->value) ? (float) $this->value : 0;

            case self::TYPE_BOOLEAN:
                return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);

            case self::TYPE_JSON:
                return json_decode($this->value, true);

            case self::TYPE_TEXT:
            case self::TYPE_IMAGE:
            default:
                return $this->value;
        }
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache(): void
    {
        CacheManager::forgetByTags(['admin_settings']);
    }
}
