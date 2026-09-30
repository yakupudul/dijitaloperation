<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand targeting of one cluster on one website and language: the page that should answer it (one target URL by
 * default; the operator may add more in extra_page_ids), and the state of that mapping (7 states). One row per site
 * language. locked = the operator chose the page(s).
 */
class BrandClusterPage extends Model
{
    public const array STATES = ['no_page', 'thin_coverage', 'weak_performance', 'possible_conflict', 'wrong_page', 'sufficient', 'insufficient_data'];

    public const array STATE_LABELS = ['no_page' => 'uygun sayfa yok', 'thin_coverage' => 'kapsam yetersiz', 'weak_performance' => 'performans zayıf', 'possible_conflict' => 'çakışma olabilir', 'wrong_page' => 'yanlış sayfa görünüyor', 'sufficient' => 'yeterli', 'insufficient_data' => 'veri yetersiz'];

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'cluster_id',
        'website_asset_id',
        'page_id',
        'state',
        'target_query',
        'language',
        'clicks_28d',
        'impressions_28d',
        'position_28d',
        'reason',
        'decided_by',
        'refreshed_at',
        'locked',
        'extra_page_ids',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'extra_page_ids' => 'array',
            'clicks_28d' => 'integer',
            'impressions_28d' => 'integer',
            'position_28d' => 'float',
            'refreshed_at' => 'immutable_datetime',
        ];
    }

    /** @return list<int> target page + operator-added pages */
    public function pageIds(): array
    {
        return array_values(array_unique(array_filter([$this->page_id !== null ? (int) $this->page_id : null, ...array_map('intval', (array) $this->extra_page_ids)])));
    }

    public function stateLabel(): string
    {
        return self::STATE_LABELS[$this->state] ?? $this->state;
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
