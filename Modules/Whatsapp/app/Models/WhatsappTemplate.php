<?php

namespace Modules\Whatsapp\Models;

use App\Traits\Cacheable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappTemplate extends Model
{
    use Cacheable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'content',
        'variables',
        'category',
        'language',
        'status',
        'whatsapp_template_id',
        'header_type',
        'header_content',
        'footer',
        'buttons',
    ];

    protected $casts = [
        'variables' => 'array',
        'buttons' => 'array',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(WhatsappCampaign::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
