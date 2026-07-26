<?php

namespace Modules\Owner\Models;

use App\Traits\Cacheable;
use App\Traits\HasSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Translatable\HasTranslations;

class Owner extends Authenticatable
{
    use Cacheable;
    use HasApiTokens, HasFactory, HasTranslations, Notifiable;
    use HasSoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp',
        'id_image',
        'password',
        'otp_code',
        'otp_expires_at',
        'is_verified',
    ];

    public $translatable = ['name'];

    protected $hidden = [
        'password',
        'otp_code',
        'otp_expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'otp_expires_at' => 'datetime',
        'is_verified' => 'boolean',
        'password' => 'hashed',
    ];

    /**
     * Get the full URL for the id_image attribute.
     */
    public function getIdImageAttribute($value)
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return config('app.url').$value;
    }

    public function isOtpValid(): bool
    {
        return $this->otp_expires_at && $this->otp_expires_at->isFuture();
    }

    public function generateOtp(int $length = 5): string
    {
        $otp = str_pad((string) random_int(0, 999999), $length, '0', STR_PAD_LEFT);

        $this->update([
            'otp_code' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        return $otp;
    }

    public function verifyOtp(string $code): bool
    {
        if (! $this->isOtpValid()) {
            return false;
        }

        if ($this->otp_code === $code) {
            $this->update([
                'otp_code' => null,
                'otp_expires_at' => null,
                'is_verified' => true,
            ]);

            return true;
        }

        return false;
    }
}
