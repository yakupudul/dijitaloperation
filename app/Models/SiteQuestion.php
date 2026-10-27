<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question of an assigned page of a site (Sorular ve yorumlar, yakup 2026-10-27): asked by a visitor on the site
 * (`site`; the name and contact stay on the site) or written from the page's search demand (`data`). The answer goes to
 * the site only after the operator approved its Onarım masası row.
 */
class SiteQuestion extends Model
{
    public const string NEW = 'new';

    /** The answer waits on the Onarım masası. */
    public const string READY = 'ready';

    public const string PUBLISHED = 'published';

    /** A personal or commercial question: answered by the business directly, never on the page. */
    public const string PRIVATE = 'private';

    public const string SPAM = 'spam';

    /** Rejected on the desk or taken off the site by the operator. */
    public const string HIDDEN = 'hidden';

    public const string SOURCE_SITE = 'site';

    public const string SOURCE_DATA = 'data';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['asked_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'position' => 'integer', 'local_id' => 'integer'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }
}
