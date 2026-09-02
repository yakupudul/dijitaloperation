<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'uuid',
    'sector',
    'description',
    'status',
    'created_by',
    'updated_by',
])]
class ServiceCatalogItem extends Model
{
    public function names(): HasMany
    {
        return $this->hasMany(ServiceCatalogName::class);
    }

    public function primaryName(): HasOne
    {
        return $this->hasOne(ServiceCatalogName::class)
            ->where('is_primary', true)
            ->where('is_active', true);
    }

    public function brandOfferings(): HasMany
    {
        return $this->hasMany(BrandOffering::class);
    }

    public function searchQueries(): BelongsToMany
    {
        return $this->belongsToMany(
            SearchQueryLibraryItem::class,
            'search_query_library_item_service',
        )->withPivot(['is_primary', 'provenance'])->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
