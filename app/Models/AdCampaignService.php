<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One brand service an ad campaign serves (Kampanya → hizmet). `suggested` rows come from the rules (page, text, name)
 * or from AI and change with every pass; `confirmed`, `removed` and `excluded` ("hizmet dışı", no service) are the
 * operator's and stay.
 */
class AdCampaignService extends Model
{
    public const string SUGGESTED = 'suggested';

    public const string CONFIRMED = 'confirmed';

    public const string REMOVED = 'removed';

    public const string EXCLUDED = 'excluded';

    /** @var array<string, string> where a row came from, in evidence order */
    public const array SOURCES = ['page' => 'gidilen sayfa', 'text' => 'reklam metni', 'name' => 'kampanya adı', 'ai' => 'AI', 'operator' => 'sen'];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    public function isOperators(): bool
    {
        return $this->decided_by !== null || $this->source === 'operator';
    }
}
