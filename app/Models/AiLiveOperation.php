<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AI işleri: one laravel/ai agent call (`call`: running → done | failed | cancelled) or one queued AI job (`job`:
 * queued → running → done | failed | cancelled) with duration, cost, a short subject / error, and for calls a capped
 * copy of the input and output. Calls made inside a tracked job point at it (`parent_id`). Written by AiLiveOperations
 * and AiJobTracker; kept `moxdop-retention.telemetry.ai_live_operations` days (30).
 */
class AiLiveOperation extends Model
{
    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string QUEUED = 'queued';

    public const string CANCELLED = 'cancelled';

    public const string KIND_CALL = 'call';

    public const string KIND_JOB = 'job';

    /** Turkish status labels (filter order). */
    public const array STATUS_LABELS = [
        self::RUNNING => 'Çalışıyor',
        self::QUEUED => 'Sırada',
        self::DONE => 'Bitti',
        self::FAILED => 'Hata',
        self::CANCELLED => 'Durduruldu',
    ];

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'invocation_id',
        'operation',
        'label',
        'agent',
        'status',
        'user_id',
        'subject',
        'error',
        'cost_usd',
        'duration_ms',
        'started_at',
        'finished_at',
        'kind',
        'parent_id',
        'job_uuid',
        'job_class',
        'queue_connection',
        'queue_name',
        'queue_job_id',
        'context',
        'link',
        'prompt_version_id',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'input_text',
        'output_text',
        'queued_at',
        'cancel_requested_at',
        'cancel_requested_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'cost_usd' => 'float',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'parent_id' => 'integer',
            'context' => 'array',
            'prompt_version_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'queued_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancel_requested_by' => 'integer',
        ];
    }

    /** @return BelongsTo<AiLiveOperation, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<AiLiveOperation, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<PromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'prompt_version_id');
    }

    public function isJob(): bool
    {
        return $this->kind === self::KIND_JOB;
    }

    public function isQueued(): bool
    {
        return $this->status === self::QUEUED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::RUNNING || $this->status === self::QUEUED;
    }

    public function cancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /** Turkish status label. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::RUNNING => $this->cancel_requested_at !== null ? 'Durduruluyor' : 'Çalışıyor',
            self::QUEUED => 'Sırada',
            self::DONE => 'Bitti',
            self::CANCELLED => 'Durduruldu',
            default => 'Başarısız',
        };
    }

    /** Final duration, or the elapsed time while running: "4,2 sn" / "1 dk 5 sn". */
    public function durationLabel(): string
    {
        if ($this->status === self::QUEUED) {
            return '—';
        }
        $ms = $this->duration_ms ?? ($this->started_at !== null && $this->isOpen() ? (int) abs($this->started_at->diffInMilliseconds(now())) : null);
        if ($ms === null) {
            return '—';
        }
        if ($ms < 60000) {
            return number_format($ms / 1000, 1, ',', '.').' sn';
        }

        return intdiv($ms, 60000).' dk '.intdiv($ms % 60000, 1000).' sn';
    }
}
