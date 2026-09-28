<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One topic cluster of a website's topic map (Faz 3): hub queries of one service that one page can answer, with the
 * page that covers them today (owner), coverage and one verdict (none | strengthen | new | merge).
 */
class TopicCluster extends Model
{
    public const array INTENT_LABELS = ['informational' => 'Bilgi', 'commercial' => 'Ticari', 'local' => 'Yerel', 'branded' => 'Marka'];

    public const array PAGE_TYPE_LABELS = ['service' => 'Hizmet sayfası', 'guide' => 'Blog rehberi', 'faq' => 'SSS', 'comparison' => 'Karşılaştırma', 'location' => 'Lokasyon'];

    public const array COVERAGE_LABELS = ['covered' => 'Karşılanıyor', 'weak' => 'Zayıf karşılanıyor', 'uncovered' => 'Karşılanmıyor'];

    public const array VERDICT_LABELS = ['none' => 'Gerek yok', 'strengthen' => 'Güçlendir', 'new' => 'Yeni içerik', 'merge' => 'Birleştir'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cannibal_urls' => 'array', 'similar_existing' => 'array', 'verdict_detail' => 'array', 'edited_at' => 'datetime',
            'demand_score' => 'float', 'owner_position' => 'float', 'cohesion' => 'float'];
    }

    /** @return HasMany<TopicClusterQuery, $this> */
    public function queries(): HasMany
    {
        return $this->hasMany(TopicClusterQuery::class)->orderByDesc('demand')->orderBy('id');
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    public function intentLabel(): string
    {
        return self::INTENT_LABELS[$this->intent] ?? '—';
    }

    public function pageTypeLabel(): string
    {
        return self::PAGE_TYPE_LABELS[$this->page_type] ?? '—';
    }

    public function coverageLabel(): string
    {
        return self::COVERAGE_LABELS[$this->coverage] ?? '—';
    }

    public function verdictLabel(): string
    {
        return self::VERDICT_LABELS[$this->verdict] ?? '—';
    }
}
