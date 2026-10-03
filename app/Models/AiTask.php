<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI iş kuyruğu: one agent call delegated to Claude over MCP (pending → claimed → done → consumed, or failed). Holds
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
        self::PENDING => 'Claude bekleniyor',
        self::CLAIMED => 'Claude çalışıyor',
        self::DONE => 'Sonuç geldi',
        self::CONSUMED => 'Uygulandı',
        self::FAILED => 'Claude yapamadı',
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
            'completed_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Waiting for Claude: pending, or claimed but not answered. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::CLAIMED], true);
    }
}
