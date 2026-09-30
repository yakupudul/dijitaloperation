<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A page / site linking to the brand ("Bağlantı verenler"): imported from Search Console, or added manually. */
class Backlink extends Model
{
    public const array SOURCES = ['gsc_import' => 'GSC', 'manual' => 'Elle'];

    /** @var list<string> */
    protected $fillable = ['brand_id', 'source_url', 'source_domain', 'target_url', 'link_hash', 'first_seen', 'source', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['first_seen' => 'immutable_date'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
