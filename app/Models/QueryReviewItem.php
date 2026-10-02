<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sorgular › Silinecekler: one open proposal per query — `delete` (the query contains `term`) or `service` (from → to,
 * null = unassigned); the latest rescan wins. `kept_at` = "Tut": the same proposal is not offered again.
 */
class QueryReviewItem extends Model
{
    public const string DELETE = 'delete';

    public const string SERVICE = 'service';

    /** `reason` of a service line: two services' keywords hit the query (term = "a / b"). */
    public const string REASON_CONFLICT = 'conflict';

    /** `reason` of a service line: the current service belongs to another sector than the query. */
    public const string REASON_SECTOR = 'sector';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['query_review_id', 'query_id', 'kind', 'term', 'reason', 'from_service_id', 'to_service_id', 'kept_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kept_at' => 'immutable_datetime'];
    }

    /** Not named query(): that is Eloquent's static builder. @return BelongsTo<Query, $this> */
    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    /** @return BelongsTo<ServiceCatalogItem, $this> */
    public function fromService(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'from_service_id');
    }

    /** @return BelongsTo<ServiceCatalogItem, $this> */
    public function toService(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'to_service_id');
    }
}
