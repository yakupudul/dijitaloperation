<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One rebuild of a website's topic map (Faz 3). */
class TopicMapBuild extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
