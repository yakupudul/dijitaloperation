<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The latest render of one operator screen (ScreenChecker): HTTP status, time, query count, error and a text outline. */
class ScreenCheck extends Model
{
    /** @var list<string> */
    protected $fillable = ['path', 'label', 'status', 'duration_ms', 'queries', 'error', 'outline', 'release', 'checked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => 'integer', 'duration_ms' => 'integer', 'queries' => 'integer', 'checked_at' => 'datetime'];
    }

    public function failed(): bool
    {
        return $this->status >= 400 || $this->error !== null;
    }
}
