<?php

namespace App\Models;

use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'digital_asset_id',
    'connection_id',
    'status',
    'started_at',
    'finished_at',
    'meta',
])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<CoreConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CoreConnection::class, 'connection_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
