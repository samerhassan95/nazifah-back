<?php

namespace App\Traits;

use App\Models\FcmToken;
use App\Support\NotificationLocale;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasFcmTokens
{
    /**
     * Upper bound on how many FCM token rows one person can accumulate. The
     * device_id-based cleanup in addFcmToken() only removes a stale row when
     * the new registration's device_id matches an old one exactly — if the
     * app ever sends a different/inconsistent device_id (observed in
     * production: emulator build-fingerprint-style ids that change between
     * sessions), old rows never get cleaned up and keep receiving every
     * future push, making one server-side notification look like several
     * duplicates to whoever holds the device. This caps the damage
     * regardless of why device_id drifted, while still allowing a few real
     * simultaneous devices (phone + tablet, etc.) per person.
     */
    private const MAX_FCM_TOKENS_PER_TOKENABLE = 3;

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

            $result = $existingToken;
        } else {
            // 4. Create new token
            $result = $this->fcmTokens()->create([
                'token' => $token,
                'device_id' => $deviceId,
                'device_type' => $deviceType,
                'lang' => $lang,
            ]);
        }

        $this->pruneExcessFcmTokens();

        return $result;
    }

    /**
     * Keep only the most recently active MAX_FCM_TOKENS_PER_TOKENABLE tokens
     * for this person, so a drifting/inconsistent device_id from the app
     * can't let stale rows accumulate forever and fan one push out to many.
     */
    private function pruneExcessFcmTokens(): void
    {
        $staleIds = $this->fcmTokens()
            ->orderByDesc('updated_at')
            ->skip(self::MAX_FCM_TOKENS_PER_TOKENABLE)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            FcmToken::whereIn('id', $staleIds)->delete();
        }
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
