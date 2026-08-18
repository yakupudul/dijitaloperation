<?php

namespace App\Models;

use App\Enums\IntentClassificationStatus;
use App\Enums\IntentPurchaseStage;
use App\Enums\IntentSignalStatus;
use App\Enums\IntentSourceVerificationState;
use App\Enums\ProspectIdentityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesIntentSignal extends Model
{
    protected $fillable = [
        'sales_search_profile_id',
        'sales_intent_radar_run_id',
        'source_type',
        'source_url',
        'source_title',
        'observed_snippet',
        'fetched_source_excerpt',
        'source_verification_state',
        'published_at',
        'discovered_at',
        'first_seen_at',
        'last_seen_at',
        'intent_category',
        'service_definition_code',
        'intent_confidence',
        'purchase_stage',
        'classification_status',
        'classification_reason',
        'negative_signals',
        'identity_status',
        'identity_confidence',
        'detected_company_name',
        'detected_domain',
        'prospect_id',
        'status',
        'fingerprint',
        'provenance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_verification_state' => IntentSourceVerificationState::class,
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'intent_confidence' => 'integer',
            'purchase_stage' => IntentPurchaseStage::class,
            'classification_status' => IntentClassificationStatus::class,
            'negative_signals' => 'array',
            'identity_status' => ProspectIdentityStatus::class,
            'identity_confidence' => 'integer',
            'status' => IntentSignalStatus::class,
            'provenance' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SalesSearchProfile, $this>
     */
    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SalesSearchProfile::class, 'sales_search_profile_id');
    }

    /**
     * @return BelongsTo<SalesIntentRadarRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SalesIntentRadarRun::class, 'sales_intent_radar_run_id');
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }
}
