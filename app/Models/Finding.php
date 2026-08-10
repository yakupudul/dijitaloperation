<?php

namespace App\Models;

use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'digital_asset_id',
    'source_module',
    'fingerprint',
    'category',
    'severity',
    'title',
    'summary',
    'confidence',
    'status',
    'first_seen_at',
    'last_seen_at',
    'last_run_id',
    'resolved_at',
])]
class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    public const string STATUS_OPEN = 'open';

    public const string STATUS_ACKNOWLEDGED = 'acknowledged';

    public const string STATUS_RESOLVED = 'resolved';

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'last_run_id');
    }

    /**
     * @return HasMany<Recommendation, $this>
     */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /**
     * @return HasManyThrough<Task, Recommendation, $this>
     */
    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Recommendation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
