<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hedef motoru (Backlink hizmeti, 2026-11-06): a page of the brand that needs link strength now: a main service page
 * Google shows on its page 1–2 but not at the top, with real impressions and content that already answers the search.
 * Keeps the numbers of the day it was picked (`before`) for the 60 / 90 day comparison, and the link text plan: most
 * links carry the brand name or the address, a keyword text stays under 10 %.
 */
class LinkTarget extends Model
{
    public const string ACTIVE = 'aktif';

    public const string DONE = 'tamam';

    public const string RELEASED = 'cikti';

    /** @var list<string> */
    protected $fillable = [
        'brand_id', 'page_id', 'cluster_id', 'url', 'url_key', 'title', 'query', 'reason', 'status', 'rank', 'position', 'impressions',
        'clicks', 'referring_domains', 'anchor_plan', 'before', 'after', 'picked_at', 'released_at', 'kind', 'impact',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'anchor_plan' => 'array', 'before' => 'array', 'after' => 'array', 'impact' => 'array', 'picked_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime',
            'position' => 'float', 'impressions' => 'integer', 'clicks' => 'integer', 'referring_domains' => 'integer', 'rank' => 'integer',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return HasMany<BacklinkSource, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BacklinkSource::class, 'link_target_id');
    }

    public function path(): string
    {
        $path = (string) (parse_url($this->url, PHP_URL_PATH) ?: '/');

        return rawurldecode($path);
    }
}
