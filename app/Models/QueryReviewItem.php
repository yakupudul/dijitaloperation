<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a rescan review: `delete` (the query contains `term`) or `service` (from → to, null = unassigned). */
class QueryReviewItem extends Model
{
    public const string DELETE = 'delete';

    public const string SERVICE = 'service';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['query_review_id', 'query_id', 'kind', 'term', 'from_service_id', 'to_service_id'];

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
