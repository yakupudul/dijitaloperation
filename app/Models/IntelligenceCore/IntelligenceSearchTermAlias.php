<?php

namespace App\Models\IntelligenceCore;

use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Enums\IntelligenceCore\SearchTermKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_term_identity_id',
    'source_digital_asset_id',
    'external_resource_id',
    'collection_run_id',
    'dataset_run_id',
    'source_fingerprint',
    'provider_or_source',
    'source_class',
    'term_kind',
    'source_dataset_id',
    'source_record_key',
    'observed_text',
    'normalized_text',
    'folded_text',
    'match_method',
    'resolution_status',
    'source_timezone',
    'market_code',
    'language_code',
    'first_observed_at',
    'last_observed_at',
    'metadata',
])]
class IntelligenceSearchTermAlias extends Model
{
    protected function casts(): array
    {
        return [
            'source_class' => IntelligenceSourceClass::class,
            'term_kind' => SearchTermKind::class,
            'match_method' => IdentityMatchMethod::class,
            'resolution_status' => IdentityResolutionStatus::class,
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<IntelligenceSearchTermIdentity, $this> */
    public function searchTermIdentity(): BelongsTo
    {
        return $this->belongsTo(IntelligenceSearchTermIdentity::class, 'search_term_identity_id');
    }
}
