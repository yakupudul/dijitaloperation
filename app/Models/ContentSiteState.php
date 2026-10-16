<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A website's content cycle (yakup, 2026-10-16): the one-off back-dated fill (eligible → done, or off), the writing
 * rhythm of its stage and the services whose topic maps are written now.
 */
class ContentSiteState extends Model
{
    public const string BACKFILL_ELIGIBLE = 'eligible';

    public const string BACKFILL_DONE = 'done';

    public const string BACKFILL_OFF = 'off';

    /** @var list<string> */
    protected $fillable = ['digital_asset_id', 'backfill', 'backfill_done_at', 'stage', 'new_per_week', 'updates_per_week', 'active_service_ids', 'week_planned'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['backfill_done_at' => 'immutable_datetime', 'active_service_ids' => 'array', 'week_planned' => 'immutable_date', 'new_per_week' => 'integer', 'updates_per_week' => 'integer'];
    }

    public static function for(DigitalAsset|int $site): self
    {
        return self::query()->firstOrNew(['digital_asset_id' => $site instanceof DigitalAsset ? (int) $site->id : $site]);
    }

    public function inBackfill(): bool
    {
        return $this->backfill === self::BACKFILL_ELIGIBLE;
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'digital_asset_id');
    }
}
