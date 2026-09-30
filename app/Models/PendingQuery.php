<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sorgular › Bekleyenler: a normalized query seen after the first import that is not in the library yet (pending), or
 * one the operator dismissed ("Yoksay") or deleted — those never come back. `filter_term` = a filter term it contains
 * (silinecek), null = temiz; `service_id` = the service its matching keywords suggest.
 */
class PendingQuery extends Model
{
    public const string PENDING = 'pending';

    public const string DISMISSED = 'dismissed';

    public const string DELETED = 'deleted';

    /** @var list<string> */
    protected $fillable = [
        'text',
        'text_hash',
        'status',
        'external_resource_id',
        'brand_id',
        'digital_asset_id',
        'sector_id',
        'service_id',
        'filter_term',
        'impressions',
        'clicks',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['impressions' => 'integer', 'clicks' => 'integer'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id');
    }

    /** @return BelongsTo<ServiceCatalogItem, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_id');
    }
}
