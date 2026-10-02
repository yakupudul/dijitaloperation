<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Faz 2: a proposed brand grouped from discovered websites and accounts (deterministic signals first, then one AI call
 * per batch). Proposes a sector from the most reliable signal (GBP primary category > site > ads). The operator
 * approves (customer → brand with sector → assets), edits or dismisses; approved groupings never change automatically.
 */
class BrandCandidate extends Model
{
    public const string PROPOSED = 'proposed';

    public const string APPROVED = 'approved';

    public const string DISMISSED = 'dismissed';

    public const array SIGNAL_LABELS = ['gbp_category' => 'İşletme kategorisi', 'site' => 'Site', 'ads' => 'Reklamlar', 'manual' => 'Elle'];

    protected $fillable = [
        'name', 'group_key', 'signals', 'sector_id', 'sector_signal', 'sector_reason', 'confidence', 'method',
        'status', 'brand_id', 'decided_by', 'decided_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['signals' => 'array', 'confidence' => 'float', 'decided_at' => 'immutable_datetime'];
    }

    /** @return HasMany<BrandCandidateResource, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(BrandCandidateResource::class);
    }

    /** @return BelongsTo<ServiceCategory, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'sector_id');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return array_values(array_filter((array) data_get($this->signals, 'hosts', []), 'is_string'));
    }
}
