<?php

namespace App\Models\IntelligenceCore;

use App\Enums\IntelligenceCore\BusinessActionSignalClass;
use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'business_action_identity_id',
    'source_digital_asset_id',
    'external_resource_id',
    'collection_run_id',
    'dataset_run_id',
    'source_fingerprint',
    'provider_or_source',
    'source_class',
    'source_semantic',
    'signal_class',
    'source_dataset_id',
    'source_record_key',
    'provider_action_id',
    'observed_name',
    'normalized_name',
    'match_method',
    'resolution_status',
    'source_timezone',
    'market_code',
    'language_code',
    'first_observed_at',
    'last_observed_at',
    'metadata',
])]
class IntelligenceBusinessActionAlias extends Model
{
    protected function casts(): array
    {
        return [
            'source_class' => IntelligenceSourceClass::class,
            'signal_class' => BusinessActionSignalClass::class,
            'match_method' => IdentityMatchMethod::class,
            'resolution_status' => IdentityResolutionStatus::class,
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<IntelligenceBusinessActionIdentity, $this> */
    public function businessActionIdentity(): BelongsTo
    {
        return $this->belongsTo(IntelligenceBusinessActionIdentity::class, 'business_action_identity_id');
    }
}
