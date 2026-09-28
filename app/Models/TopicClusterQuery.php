<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A hub query inside a topic cluster; `pinned` = placed by the operator, kept on every rebuild. */
class TopicClusterQuery extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pinned' => 'bool', 'is_head' => 'bool', 'position' => 'float', 'demand' => 'float'];
    }

    /** @return BelongsTo<TopicCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(TopicCluster::class, 'topic_cluster_id');
    }
}
