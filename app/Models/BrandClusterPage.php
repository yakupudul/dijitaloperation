<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand targeting of one cluster on one website: the page that should answer it (one target URL per cluster by default,
 * language versions separate) and the state of that mapping (7 states). locked = the operator chose the page.
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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'clicks_28d' => 'integer',
            'impressions_28d' => 'integer',
            'position_28d' => 'float',
            'refreshed_at' => 'immutable_datetime',
        ];
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
