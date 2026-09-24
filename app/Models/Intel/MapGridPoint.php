<?php

namespace App\Models\Intel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One point of a map grid scan: our rank (null = not in the top 20) and the top 20 businesses there. */
class MapGridPoint extends Model
{
    protected $fillable = ['map_grid_run_id', 'row', 'col', 'lat', 'lng', 'status', 'our_rank', 'results'];

    protected function casts(): array
    {
        return [
            'row' => 'integer',
            'col' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'our_rank' => 'integer',
            'results' => 'array',
        ];
    }

    /** @return BelongsTo<MapGridRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(MapGridRun::class, 'map_grid_run_id');
    }
}
