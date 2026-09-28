<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One query in a brand's query hub (see BrandDemandBuilder / BrandQueryHub): every source the brand sees it in, its
 * metrics, the service it belongs to (with method and confidence), area, sector, intent and relevance.
 * Gold data: never deleted. Operator decisions (assignment_source / relevance_source = operator) win over rebuilds.
 */
class BrandDemandQuery extends Model
{
    public const string SOURCE_AUTO = 'auto';

    public const string SOURCE_OPERATOR = 'operator';

    public const string RELEVANT = 'relevant';

    /** "Belirsiz": no service found and nothing marks it off-topic; needs an operator look. */
    public const string UNCLEAR = 'unclear';

    /** "Alakasız": outside the brand's sector or an excluded expression. Kept (never deleted), only flagged. */
    public const string IRRELEVANT = 'irrelevant';

    public const string METHOD_OPERATOR = 'operator';

    public const string METHOD_RULE = 'rule';

    public const string METHOD_PORTFOLIO = 'portfolio';

    public const string METHOD_LIBRARY = 'library';

    public const string METHOD_EMBEDDING = 'embedding';

    /** Source bits of source_mask; the same keys as the `sources` list. */
    public const array SOURCE_BITS = [
        'search_console' => 1,
        'google_ads' => 2,
        'google_business_profile' => 4,
        'portfolio' => 8,
        'competitor' => 16,
        'area_serp' => 32,
    ];

    /** Operator-facing source labels. */
    public const array SOURCE_LABELS = [
        'search_console' => 'Search Console',
        'google_ads' => 'Google Ads',
        'google_business_profile' => 'İşletme Profili',
        'portfolio' => 'Sorgu portföyü',
        'competitor' => 'Rakip',
        'area_serp' => 'Bölge SERP',
    ];

    public const array METHOD_LABELS = [
        'operator' => 'Operatör',
        'rule' => 'Kural',
        'portfolio' => 'Portföy',
        'library' => 'Kütüphane',
        'embedding' => 'Anlam benzerliği',
    ];

    public const array RELEVANCE_LABELS = [
        'relevant' => 'İlgili',
        'unclear' => 'Belirsiz',
        'irrelevant' => 'Alakasız',
    ];

    protected $guarded = [];

    /** @param  list<string>  $sources */
    public static function maskOf(array $sources): int
    {
        $mask = 0;
        foreach ($sources as $source) {
            $mask |= self::SOURCE_BITS[$source] ?? 0;
        }

        return $mask;
    }

    /**
     * Rows seen in the given source (bit test; works on SQLite and PostgreSQL).
     *
     * @param  Builder<self>  $query
     */
    public function scopeFromSource(Builder $query, string $source): void
    {
        $query->whereRaw('(source_mask & ?) <> 0', [self::SOURCE_BITS[$source] ?? 0]);
    }

    /** "rising" / "falling" / "flat" / "new": last 28 days vs the 28 before (impressions); null without data. */
    public function trend(): ?string
    {
        $recent = (int) $this->recent_impressions;
        $previous = (int) $this->previous_impressions;
        if ($recent === 0 && $previous === 0) {
            return null;
        }
        if ($previous === 0) {
            return 'new';
        }
        $change = ($recent - $previous) / $previous;

        return $change >= 0.2 ? 'rising' : ($change <= -0.2 ? 'falling' : 'flat');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return BelongsTo<BrandServiceArea, $this> */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(BrandServiceArea::class, 'brand_service_area_id');
    }

    /** @return BelongsTo<SearchQueryLibraryItem, $this> */
    public function libraryItem(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryItem::class, 'search_query_library_item_id');
    }

    /** @return BelongsTo<BrandQueryPortfolioItem, $this> */
    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(BrandQueryPortfolioItem::class, 'brand_query_portfolio_item_id');
    }

    /** @return HasMany<BrandDemandQueryAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(BrandDemandQueryAsset::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locations' => 'array',
            'sources' => 'array',
            'is_branded' => 'boolean',
            'ads_cost' => 'float',
            'ads_conversions' => 'float',
            'value_score' => 'float',
            'gsc_position' => 'float',
            'assignment_confidence' => 'float',
            'source_mask' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'built_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'first_observed_on' => 'date',
            'last_observed_on' => 'date',
        ];
    }
}
