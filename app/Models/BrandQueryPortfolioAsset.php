<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_query_portfolio_item_id',
    'digital_asset_id',
    'status',
    'query_text_override',
    'demand_family_override',
    'updated_by',
])]
class BrandQueryPortfolioAsset extends Model
{
    protected $table = 'brand_query_portfolio_assets';

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(BrandQueryPortfolioItem::class, 'brand_query_portfolio_item_id');
    }

    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
