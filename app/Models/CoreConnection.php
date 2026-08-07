<?php

namespace App\Models;

use Database\Factories\CoreConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'digital_asset_id',
    'type',
    'name',
    'config',
    'enabled',
    'last_success_at',
    'last_error',
])]
class CoreConnection extends Model
{
    /** @use HasFactory<CoreConnectionFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'last_success_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return HasOne<CoreConnectionCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(CoreConnectionCredential::class, 'connection_id');
    }
}
