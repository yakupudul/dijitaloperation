<?php

namespace App\Models;

use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'digital_asset_id',
    'customer_id',
    'brand_id',
    'source_module',
    'origin',
    'rule_id',
    'rule_version',
    'fingerprint',
    'semantic_fingerprint',
    'subject_kind',
    'subject_id',
    'brand_goal_id',
    'brand_offering_id',
    'category',
    'severity',
    'title',
    'summary',
    'confidence',
    'status',
    'condition_state',
    'first_seen_at',
    'last_seen_at',
    'last_run_id',
    'resolved_at',
    'latest_evaluation_id',
])]
class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_ACKNOWLEDGED = 'acknowledged';

    public const string STATUS_RESOLVED = 'resolved';

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

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
     * @return BelongsTo<Run, $this>
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'last_run_id');
    }

    /**
     * @return BelongsTo<FindingEvaluation, $this>
     */
    public function latestEvaluation(): BelongsTo
    {
        return $this->belongsTo(FindingEvaluation::class, 'latest_evaluation_id');
    }

    /**
     * @return HasMany<FindingEvaluation, $this>
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(FindingEvaluation::class);
    }

    /**
     * @return HasMany<Recommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /**
     * @return HasManyThrough<Task, Recommendation, $this>
     */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Recommendation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'rule_version' => 'integer',
        ];
    }
}
