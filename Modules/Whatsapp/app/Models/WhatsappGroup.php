<?php

namespace Modules\Whatsapp\Models;

use App\Models\User;
use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes; // Assumes default User model, replace with actual if different

class WhatsappGroup extends Model
{
    use Cacheable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'type',
        'filter_rules',
        'members_count',
    ];

    protected $casts = [
        'filter_rules' => 'array',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'whatsapp_group_user')
            ->withPivot('added_at')
            ->withTimestamps();
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(WhatsappCampaign::class);
    }

    public function syncMembersCount(): void
    {
        $this->update([
            'members_count' => $this->users()->count(),
        ]);
    }
}
