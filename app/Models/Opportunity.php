<?php

namespace App\Models;

use Database\Factories\OpportunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'brand_id',
    'digital_asset_id',
    'origin',
    'rule_id',
    'rule_version',
    'fingerprint',
    'semantic_fingerprint',
    'subject_kind',
    'subject_id',
    'category',
    'status',
    'detection_state',
    'qualitative_priority',
    'service_definition_code',
    'commercial_scope_state',
    'title',
    'description',
    'brand_goal_id',
    'brand_offering_id',
    'market_location',
    'market_language',
    'first_detected_at',
    'last_detected_at',
    'closed_at',
    'latest_evaluation_id',
])]
class Opportunity extends Model
{
    /** @use HasFactory<OpportunityFactory> */
    use HasFactory;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_REVIEWING = 'reviewing';

    public const string STATUS_DEFERRED = 'deferred';

    public const string STATUS_CONVERTED = 'converted';

    public const string STATUS_DISMISSED = 'dismissed';

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<BrandGoal, $this>
     */
    public function brandGoal(): BelongsTo
    {
        return $this->belongsTo(BrandGoal::class);
    }

    /**
     * @return BelongsTo<BrandOffering, $this>
     */
    public function brandOffering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class);
    }

    /**
     * @return BelongsTo<OpportunityEvaluation, $this>
     */
    public function latestEvaluation(): BelongsTo
    {
        return $this->belongsTo(OpportunityEvaluation::class, 'latest_evaluation_id');
    }

    /**
     * @return HasMany<OpportunityEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(OpportunityEvaluation::class);
    }

    /**
     * @return HasMany<Recommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'closed_at' => 'datetime',
            'rule_version' => 'integer',
        ];
    }
}
