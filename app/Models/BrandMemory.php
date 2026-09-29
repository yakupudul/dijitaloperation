<?php

namespace App\Models;

use App\Services\Site\BrandMemoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE brand memory: approved brand info (profile), page summaries and relations (page), decision history (decision).
 * Only the relevant rows are sent to AI; on a site change only the affected rows are refreshed.
 */
class BrandMemory extends Model
{
    public const array KINDS = ['profile', 'page', 'decision'];

    protected $table = 'brand_memory';

    /** @var list<string> */
    protected $fillable = [
        'brand_id',
        'kind',
        'ref_type',
        'ref_id',
        'summary',
        'data',
        'updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'ref_id' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Only the memory relevant to these pages / clusters (see BrandMemoryService::contextFor()).
     *
     * @param  list<int>  $pageIds
     * @param  list<int>  $clusterIds
     * @return array<string, mixed>
     */
    public static function contextFor(Brand $brand, array $pageIds, array $clusterIds): array
    {
        return app(BrandMemoryService::class)->contextFor($brand, $pageIds, $clusterIds);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
