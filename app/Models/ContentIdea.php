<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extra content idea of a cluster in the system-wide pool (the cluster itself is the main idea). Produced only on
 * the operator's "Yeni fikir üret"; origin_brand = the brand it was produced for (null: from Sorgular).
 */
class ContentIdea extends Model
{
    public const array TYPES = ['service', 'guide', 'faq', 'comparison', 'location'];

    public const array TYPE_LABELS = ['service' => 'hizmet', 'guide' => 'rehber (blog)', 'faq' => 'SSS', 'comparison' => 'karşılaştırma', 'location' => 'lokasyon'];

    /** @var list<string> */
    protected $fillable = ['cluster_id', 'title', 'title_key', 'type', 'angle', 'target_queries', 'outline', 'origin_brand_id', 'created_by', 'prompt_version_id', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['target_queries' => 'array', 'outline' => 'array'];
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function originBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'origin_brand_id');
    }

    /** @return HasMany<BrandContentIdea, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(BrandContentIdea::class);
    }
}
