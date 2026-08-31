<?php

namespace App\Models\IntelligenceCore;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'brand_id',
    'identity_hash',
    'action_key',
    'action_kind',
    'display_name',
    'semantic_definition',
    'status',
    'definition_version',
])]
class IntelligenceBusinessActionIdentity extends Model
{
    protected function casts(): array
    {
        return [
            'definition_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<IntelligenceBusinessActionAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(IntelligenceBusinessActionAlias::class, 'business_action_identity_id');
    }
}
