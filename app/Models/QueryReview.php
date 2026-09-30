<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Filter / matching keyword rescan result waiting for the operator: queries that contain a filter term (to delete) and
 * queries whose service would change. Nothing is applied before "Onayla".
 */
class QueryReview extends Model
{
    public const string RUNNING = 'running';

    public const string READY = 'ready';

    public const string APPLIED = 'applied';

    public const string FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = ['status', 'created_by', 'deletions', 'changes', 'applied_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['deletions' => 'integer', 'changes' => 'integer', 'applied_at' => 'immutable_datetime'];
    }

    /** @return HasMany<QueryReviewItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QueryReviewItem::class);
    }
}
