<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One merge of a duplicate website asset into its keeper (Kopya web siteleri). `moved` / `dropped` are row counts per
 * table; `snapshot` keeps the names at merge time. Append-only.
 */
class AssetMerge extends Model
{
    public const ?string UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cross_customer' => 'boolean',
            'moved' => 'array',
            'dropped' => 'array',
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function movedTotal(): int
    {
        return (int) array_sum(array_map('intval', is_array($this->moved) ? $this->moved : []));
    }

    public function droppedTotal(): int
    {
        return (int) array_sum(array_map('intval', is_array($this->dropped) ? $this->dropped : []));
    }
}
