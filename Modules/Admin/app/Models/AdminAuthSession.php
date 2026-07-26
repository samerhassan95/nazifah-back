<?php

namespace Modules\Admin\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminAuthSession extends Model
{
    use Cacheable;

    protected $table = 'admin_auth_sessions';

    protected $fillable = [
        'session_key',
        'phone',
        'admin_id',
        'otp_code',
        'otp_expires_at',
        'otp_attempts',
        'rate_limit_reset_at',
        'expires_at',
    ];

    protected $hidden = [
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'otp_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'rate_limit_reset_at' => 'datetime',
        'otp_attempts' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const MAX_OTP_ATTEMPTS = 3;

    const RATE_LIMIT_DURATION = 60; // seconds

    /**
     * Get the admin that owns the session
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Generate a unique session key
     */
    public static function generateSessionKey(): string
    {
        do {
            $sessionKey = Str::random(64);
        } while (self::where('session_key', $sessionKey)->exists());

        return $sessionKey;
    }

    /**
     * Check if session can send OTP (rate limit check)
     */
    public function canSendOtp(): bool
    {
        $this->refresh();

        if (! $this->rate_limit_reset_at) {
            return true;
        }

        if ($this->rate_limit_reset_at->isPast()) {
            return true;
        }

        return $this->otp_attempts < self::MAX_OTP_ATTEMPTS;
    }

    /**
     * Get remaining OTP attempts
     */
    public function getRemainingAttempts(): int
    {
        $this->refresh();

        if (! $this->rate_limit_reset_at || $this->rate_limit_reset_at->isPast()) {
            return self::MAX_OTP_ATTEMPTS;
        }

        return max(0, self::MAX_OTP_ATTEMPTS - $this->otp_attempts);
    }

    /**
     * Get seconds until rate limit reset
     */
    public function getSecondsUntilReset(): int
    {
        $this->refresh();

        if (! $this->rate_limit_reset_at || $this->rate_limit_reset_at->isPast()) {
            return 0;
        }

        return max(0, now()->diffInSeconds($this->rate_limit_reset_at));
    }

    /**
     * Check if OTP is valid
     */
    public function isOtpValid(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isFuture();
    }

    /**
     * Check if session is valid
     */
    public function isValid(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Generate and save OTP
     */
    public function generateOtp(int $length = 5): string
    {
        $this->refresh();

        if ($this->rate_limit_reset_at && $this->rate_limit_reset_at->isPast()) {
            $this->update([
                'otp_attempts' => 0,
                'rate_limit_reset_at' => null,
            ]);
            $this->refresh();
        }

        if (! $this->canSendOtp()) {
            throw new \Exception('Rate limit exceeded. Please wait before requesting another OTP.');
        }

        if (! app()->environment('production')) {
            $otp = '12345';
        } else {
            $otp = str_pad((string) random_int(0, 99999), $length, '0', STR_PAD_LEFT);
        }

        if (! $this->rate_limit_reset_at) {
            $attempts = 1;
            $resetAt = now()->addSeconds(self::RATE_LIMIT_DURATION);
        } else {
            $attempts = $this->otp_attempts + 1;
            $resetAt = $this->rate_limit_reset_at;
        }

        $this->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
            'otp_attempts' => $attempts,
            'rate_limit_reset_at' => $resetAt,
        ]);

        return $otp;
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(string $code): bool
    {
        if (! $this->isOtpValid()) {
            return false;
        }

        if ($this->otp_code === $code) {
            $this->update([
                'otp_code' => null,
                'otp_expires_at' => null,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Clean up expired sessions
     */
    public static function cleanupExpiredSessions(): int
    {
        return self::where('expires_at', '<', now())->delete();
    }

    /**
     * Create or get existing session for a phone number
     */
    public static function createForPhone(string $phone): self
    {
        $phone = normalizePhone($phone);

        $existingSession = self::where('phone', $phone)
            ->where('expires_at', '>', now())
            ->first();

        if ($existingSession) {
            return $existingSession;
        }

        self::where('phone', $phone)
            ->where('expires_at', '<=', now())
            ->delete();

        $admin = Admin::where('phone', $phone)->first();

        return self::create([
            'session_key' => self::generateSessionKey(),
            'phone' => $phone,
            'admin_id' => $admin ? $admin->id : null,
            'expires_at' => now()->addHours(24),
        ]);
    }
}
