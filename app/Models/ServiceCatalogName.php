<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_catalog_item_id',
    'raw_label',
    'normalized_key',
    'locale',
    'name_kind',
    'is_primary',
    'is_active',
    'provenance',
    'normalization_version',
])]
class ServiceCatalogName extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }
}
