<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One website URL, latest version only: main content text (no header / footer / head), title, meta description,
 * H1–H3, canonical, language, category, content summary (for AI), content hash and changed_at. Re-analyzed only
 * when the hash changes.
 */
class Page extends Model
{
    public const array CATEGORIES = ['hizmet', 'blog', 'kurumsal', 'sss', 'lokasyon', 'diger'];

    /** @var list<string> */
    protected $fillable = [
        'website_asset_id',
        'url',
        'url_hash',
        'path',
        'category',
        'language',
        'title',
        'meta_description',
        'canonical',
        'h1',
        'headings',
        'content_text',
        'content_summary',
        'content_hash',
        'word_count',
        'wp_post_id',
        'wp_post_type',
        'is_indexable',
        'changed_at',
        'analyzed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headings' => 'array',
            'word_count' => 'integer',
            'wp_post_id' => 'integer',
            'is_indexable' => 'boolean',
            'changed_at' => 'immutable_datetime',
            'analyzed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }
}
