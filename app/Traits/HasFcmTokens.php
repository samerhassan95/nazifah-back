<?php

namespace App\Traits;

use App\Models\FcmToken;
use App\Support\NotificationLocale;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasFcmTokens
{
    /**
     * Get all FCM tokens for this model
     */
    public function fcmTokens(): MorphMany
    {
        return $this->morphMany(FcmToken::class, 'tokenable');
    }

    /**
     * Add or update FCM token
     */
    public function addFcmToken(
        string $token,
        ?string $deviceId = null,
        ?string $deviceType = null,
        ?string $lang = null
    ): FcmToken {
        $lang = NotificationLocale::normalize($lang ?? app()->getLocale());

        FcmToken::where('token', $token)
            ->where(function ($query) {
                $query->where('tokenable_id', '!=', $this->getKey())
                    ->orWhere('tokenable_type', '!=', $this->getMorphClass());
            })
            ->delete();

        if ($deviceId) {
            $this->fcmTokens()
                ->where('device_id', $deviceId)
                ->where('token', '!=', $token)
                ->delete();
        }

        // 3. Check if the token already exists for this user
        $existingToken = $this->fcmTokens()->where('token', $token)->first();

        if ($existingToken) {
            // Update device info
            $existingToken->update([
                'device_id' => $deviceId ?? $existingToken->device_id,
                'device_type' => $deviceType ?? $existingToken->device_type,
                'lang' => $lang,
            ]);

            return $existingToken;
        }

        // 4. Create new token
        return $this->fcmTokens()->create([
            'token' => $token,
            'device_id' => $deviceId,
            'device_type' => $deviceType,
            'lang' => $lang,
        ]);
    }

    public function getNotificationLang(?string $deviceId = null): string
    {
        $query = $this->fcmTokens();

        if ($deviceId) {
            $token = (clone $query)->where('device_id', $deviceId)->first();
            if ($token?->lang) {
                return NotificationLocale::normalize($token->lang);
            }
        }

        $token = $query->latest('updated_at')->first();

        return NotificationLocale::normalize($token?->lang);
    }

    public function updateFcmTokenLang(string $deviceId, string $lang): ?FcmToken
    {
        $token = $this->fcmTokens()->where('device_id', $deviceId)->first();

        if (! $token) {
            return null;
        }

        $token->update([
            'lang' => NotificationLocale::normalize($lang),
        ]);

        return $token->fresh();
    }

    /**
     * Remove FCM token
     */
    public function removeFcmToken(string $token): bool
    {
        return $this->fcmTokens()->where('token', $token)->delete() > 0;
    }

    /**
     * Remove FCM token by device ID
     */
    public function removeFcmTokenByDevice(string $deviceId): bool
    {
        return $this->fcmTokens()->where('device_id', $deviceId)->delete() > 0;
    }

    /**
     * Get all FCM token strings
     */
    public function getFcmTokenStrings(): array
    {
        return $this->fcmTokens()->pluck('token')->toArray();
    }

    /**
     * Check if model has any FCM tokens
     */
    public function hasFcmTokens(): bool
    {
        return $this->fcmTokens()->exists();
    }
}
