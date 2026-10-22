<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A website's score of one night (Aşama 7): `score` 0-100, `layers` the four 25-point parts (teknik, seo, aeo, kurgu)
 * and `problems` the problem types counted that night.
 */
class SiteScore extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'digital_asset_id' => 'integer',
            'brand_id' => 'integer',
            'day' => 'date',
            'score' => 'integer',
            'layers' => 'array',
            'problems' => 'array',
            'pages' => 'integer',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id');
    }
}
