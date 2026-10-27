<?php

namespace App\Models;

use App\Services\AiTasks\AiWorkers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI iş kuyruğu: one agent call delegated to the AI workers (Claude / ChatGPT over MCP, or an API; AiWorkers) (pending → claimed → done → consumed, or failed). Holds
 * what the provider would have received (rendered instructions, DATA_JSON input, output JSON schema), Claude's
 * output once submitted, and the serialized job that asked, dispatched again when its run has every answer.
 */
class AiTask extends Model
{
    public const string PENDING = 'pending';

    public const string CLAIMED = 'claimed';

    public const string DONE = 'done';

    public const string CONSUMED = 'consumed';

    public const string FAILED = 'failed';

    /** Turkish status labels. */
    public const array STATUS_LABELS = [
        self::PENDING => 'Sırada',
        self::CLAIMED => 'Yapılıyor',
        self::DONE => 'Sonuç geldi',
        self::CONSUMED => 'Uygulandı',
        self::FAILED => 'Yapılamadı',
    ];

    /** @var list<string> */
    protected $fillable = [
        'operation',
        'brand_id',
        'subject',
        'resume_key',
        'sequence',
        'input_hash',
        'status',
        'prompt_version_id',
        'instructions',
        'input',
        'output_schema',
        'output',
        'resume',
        'error',
        'attempts',
        'claimed_at',
        'claimed_by',
        'done_by',
        'done_by_token_id',
        'cost_usd',
        'completed_at',
        'consumed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['resume'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'output_schema' => 'array',
            'output' => 'array',
            'sequence' => 'integer',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'cost_usd' => 'float',
            'completed_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Who answered it, as Ayarlar › AI names the worker ("ChatGPT MCP", "Claude API · model"); null while open. */
    public function doneByLabel(): ?string
    {
        if ($this->done_by !== null) {
            return AiWorkers::label((string) $this->done_by);
        }

        // Answers from before 2026-10-27 carry no worker: they were all Claude's.
        return in_array($this->status, [self::DONE, self::CONSUMED, self::FAILED], true) ? AiWorkers::LABELS[AiWorkers::CLAUDE_MCP] : null;
    }

    /** Waiting for a worker: pending, or claimed but not answered. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::CLAIMED], true);
    }
}
