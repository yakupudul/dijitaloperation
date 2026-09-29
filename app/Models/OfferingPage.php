<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faz 4a AI adım 1: one brand service ↔ one page of its website (hizmet / lokasyon pages). source rule | ai | manual;
 * locked = the operator set it and no automatic pass changes it.
 */
class OfferingPage extends Model
{
    /** @var list<string> */
    protected $fillable = ['brand_offering_id', 'page_id', 'source', 'locked'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['locked' => 'boolean'];
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
