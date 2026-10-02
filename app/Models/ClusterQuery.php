<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of one query in one cluster (a query belongs to at most one cluster). is_suggested marks AI-generated queries ("önerilen").
 */
class ClusterQuery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'cluster_id',
        'query_id',
        'is_suggested',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_suggested' => 'boolean',
        ];
    }

    /**
     * Inserts memberships whose query still exists. Clustering waits minutes on the AI between reading queries and
     * writing their memberships; a filter-scan approval may delete some of them meanwhile (foreign key violation on
     * `query_id`). The surviving rows are share-locked so a delete cannot slip in before the insert commits.
     *
     * @param  list<array{cluster_id: int, query_id: int, is_suggested: bool, created_at: mixed, updated_at: mixed}>  $rows
     * @return int rows inserted
     */
    public static function insertExisting(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $live = Query::query()->whereIn('id', array_column($rows, 'query_id'))->sharedLock()->pluck('id')
            ->map(fn ($id): int => (int) $id)->flip();
        $rows = array_values(array_filter($rows, fn (array $row): bool => isset($live[(int) $row['query_id']])));
        if ($rows !== []) {
            static::query()->insert($rows);
        }

        return count($rows);
    }

    /** @return BelongsTo<Cluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** @return BelongsTo<Query, $this> */
    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }
}
