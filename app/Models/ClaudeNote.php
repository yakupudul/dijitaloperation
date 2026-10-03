<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Claude's working note (MCP): what Claude saw, suspects or proposes about a brand (or the system), kept so the next
 * run or another Claude account continues from it. Append-only: a changed view is a new note that supersedes the old
 * one. Kept apart from facts (the brand file) and from the operator's notes; no AI operation reads it as input.
 */
class ClaudeNote extends Model
{
    public const array KINDS = ['observation', 'hypothesis', 'proposal', 'followup'];

    public const array KIND_LABELS = ['observation' => 'Gözlem', 'hypothesis' => 'Varsayım', 'proposal' => 'Öneri', 'followup' => 'Takip'];

    public const string OPEN = 'open';

    public const string DONE = 'done';

    public const string SUPERSEDED = 'superseded';

    /** @var list<string> */
    protected $fillable = ['brand_id', 'kind', 'text', 'refs', 'status', 'supersedes_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['refs' => 'array'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return array<string, mixed> how a note is shown to Claude */
    public function toTool(): array
    {
        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'kind' => $this->kind,
            'status' => $this->status,
            'text' => $this->text,
            'refs' => $this->refs ?? [],
            'supersedes_id' => $this->supersedes_id,
            'written_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
