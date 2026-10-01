<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How one brand's website answers a pool idea: the page and its state (İçerik fikirleri). A row with a page = "uses" it. */
class BrandContentIdea extends Model
{
    public const array STATE_LABELS = ['sufficient' => 'Karşılıyor', 'improve' => 'Geliştirilmeli', 'no_page' => 'Sayfa yok', 'technical' => 'Teknik sorun'];

    /** @var list<string> */
    protected $fillable = ['brand_id', 'content_idea_id', 'website_asset_id', 'page_id', 'state', 'coverage', 'gaps', 'reason', 'locked', 'audited_at', 'recipe', 'recipe_at', 'rediscovered_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['gaps' => 'array', 'locked' => 'boolean', 'audited_at' => 'immutable_datetime', 'recipe' => 'array', 'recipe_at' => 'immutable_datetime', 'rediscovered_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ContentIdea, $this> */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class, 'content_idea_id');
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

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }
}
