<?php

namespace App\Models;

use App\Enums\ProspectResearchRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'prospect_id',
    'status',
    'seed_url',
    'started_at',
    'finished_at',
    'metadata',
])]
class ProspectResearchRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ProspectResearchRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return HasMany<ProspectEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(ProspectEvidence::class);
    }

    /**
     * @return HasOne<ProspectSalesIntelligence, $this>
     */
    public function salesIntelligence(): HasOne
    {
        return $this->hasOne(ProspectSalesIntelligence::class);
    }
}
