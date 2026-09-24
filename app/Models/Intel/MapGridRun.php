<?php

namespace App\Models\Intel;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Google Maps grid scan of one keyword around the brand's location (Faz 8). */
class MapGridRun extends Model
{
    public const string STATUS_RUNNING = 'running';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_PARTIAL = 'partial';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'brand_id', 'keyword', 'center_lat', 'center_lng', 'grid_size', 'spacing_km', 'status', 'trigger', 'points_total',
        'points_done', 'arp', 'atrp', 'solv', 'cost_usd', 'requested_by', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
            'grid_size' => 'integer',
            'spacing_km' => 'float',
            'points_total' => 'integer',
            'points_done' => 'integer',
            'arp' => 'float',
            'atrp' => 'float',
            'solv' => 'float',
            'cost_usd' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<MapGridPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(MapGridPoint::class);
    }
}
