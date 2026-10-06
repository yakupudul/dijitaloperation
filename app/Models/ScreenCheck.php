<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The latest render of one operator screen (ScreenChecker): HTTP status, time, query count, database time, the slowest
 * query patterns, error and a text outline.
 *
 * @property list<array{sql: string, count: int, ms: float, frame: ?string}>|null $slow_queries
 */
class ScreenCheck extends Model
{
    /** @var list<string> */
    protected $fillable = ['path', 'label', 'status', 'duration_ms', 'queries', 'db_ms', 'slow_queries', 'error', 'outline', 'release', 'checked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => 'integer', 'duration_ms' => 'integer', 'queries' => 'integer', 'db_ms' => 'integer', 'slow_queries' => 'array', 'checked_at' => 'datetime'];
    }

    public function failed(): bool
    {
        return $this->status >= 400 || $this->error !== null;
    }
}
