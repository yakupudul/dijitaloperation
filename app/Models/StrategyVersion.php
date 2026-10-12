<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a strategy's text (Markdown) with what changed and the data it was based on (`evidence`: list of
 * {label, detail}). Versions are never edited: a change is a new version.
 */
class StrategyVersion extends Model
{
    /** @var list<string> */
    protected $fillable = ['strategy_id', 'version', 'body', 'change_note', 'evidence', 'author'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }
}
