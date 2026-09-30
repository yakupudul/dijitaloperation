<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One laravel/ai agent call as the operator sees it live: running → done | failed, with duration, cost and a short
 * subject / error. Written by AiLiveOperations; kept 7 days.
 */
class AiLiveOperation extends Model
{
    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

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
        ];
    }

    public function isRunning(): bool
    {
        return $this->status === self::RUNNING;
    }

    /** Turkish status label. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::RUNNING => 'Çalışıyor',
            self::DONE => 'Bitti',
            default => 'Başarısız',
        };
    }

    /** Final duration, or the elapsed time while running: "4,2 sn" / "1 dk 5 sn". */
    public function durationLabel(): string
    {
        $ms = $this->duration_ms ?? ($this->started_at !== null ? (int) abs($this->started_at->diffInMilliseconds(now())) : null);
        if ($ms === null) {
            return '—';
        }
        if ($ms < 60000) {
            return number_format($ms / 1000, 1, ',', '.').' sn';
        }

        return intdiv($ms, 60000).' dk '.intdiv($ms % 60000, 1000).' sn';
    }
}
