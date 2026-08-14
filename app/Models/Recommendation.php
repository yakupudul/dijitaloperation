<?php

namespace App\Models;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Services\Recommendations\RecommendationSourceGuard;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_kind',
    'finding_id',
    'opportunity_id',
    'digital_asset_id',
    'source_module',
    'origin',
    'idempotency_key',
    'title',
    'action',
    'rationale',
    'priority',
    'effort',
    'status',
])]
class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_DISMISSED = 'dismissed';

    public const string STATUS_CONVERTED = 'converted';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACCEPTED,
        self::STATUS_DISMISSED,
        self::STATUS_CONVERTED,
    ];

    protected static function booted(): void
    {
        static::saving(static function (Recommendation $recommendation): void {
            app(RecommendationSourceGuard::class)->normalize($recommendation);
        });
    }

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function sourceKind(): ?RecommendationSourceKind
    {
        return RecommendationSourceKind::tryFrom((string) $this->source_kind);
    }

    public function origin(): ?RecommendationOrigin
    {
        return RecommendationOrigin::tryFrom((string) $this->origin);
    }

    public function isFindingSourced(): bool
    {
        return $this->sourceKind() === RecommendationSourceKind::Finding;
    }

    public function isOpportunitySourced(): bool
    {
        return $this->sourceKind() === RecommendationSourceKind::Opportunity;
    }

    public function sourceId(): ?int
    {
        return match ($this->sourceKind()) {
            RecommendationSourceKind::Finding => $this->finding_id === null ? null : (int) $this->finding_id,
            RecommendationSourceKind::Opportunity => $this->opportunity_id === null ? null : (int) $this->opportunity_id,
            default => null,
        };
    }
}
