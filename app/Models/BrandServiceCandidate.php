<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faz 2 "Hizmet keşfi": a service proposed from the brand's own pages (one AI call), matched to a catalog item of the
 * brand's sector when one fits (else flagged as a new catalog item). Approval creates a locked brand offering.
 */
class BrandServiceCandidate extends Model
{
    public const string PROPOSED = 'proposed';

    public const string APPROVED = 'approved';

    public const string SKIPPED = 'skipped';

    protected $fillable = ['brand_id', 'name', 'normalized_key', 'service_catalog_item_id', 'new_catalog_item', 'page_ids', 'status', 'brand_offering_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['page_ids' => 'array', 'new_catalog_item' => 'boolean'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<ServiceCatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }
}
