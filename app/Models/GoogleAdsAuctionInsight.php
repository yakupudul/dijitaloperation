<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Faz 14g: one competitor row of an uploaded Google Ads Auction Insights report. */
class GoogleAdsAuctionInsight extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'is_own' => 'boolean',
            'impression_share' => 'float',
            'overlap_rate' => 'float',
            'position_above_rate' => 'float',
            'top_of_page_rate' => 'float',
            'abs_top_rate' => 'float',
            'outranking_share' => 'float',
            'below_threshold' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id');
    }
}
