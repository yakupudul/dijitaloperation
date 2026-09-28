<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A concrete article idea of the content studio (Faz 4). */
class ContentIdea extends Model
{
    public const array SOURCE_LABELS = ['cluster' => 'Konu kümesi', 'location' => 'Hizmet bölgesi', 'ai' => 'AI boşluk önerisi', 'operator' => 'Operatör'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['queries' => 'array', 'outline' => 'array', 'faq' => 'array', 'internal_links' => 'array', 'similar_existing' => 'array', 'demand_score' => 'float'];
    }

    /** @return BelongsTo<TopicCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(TopicCluster::class, 'topic_cluster_id');
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return HasMany<ContentArticle, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(ContentArticle::class);
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? $this->source;
    }
}
