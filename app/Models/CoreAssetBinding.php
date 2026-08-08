<?php

namespace App\Models;

use Database\Factories\CoreAssetBindingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'digital_asset_id',
    'external_resource_id',
    'capability',
    'status',
    'configuration',
])]
class CoreAssetBinding extends Model
{
    /** @use HasFactory<CoreAssetBindingFactory> */
    use HasFactory;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_DISABLED = 'disabled';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'configuration' => 'array',
    ];

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<CoreExternalResource, $this>
     */
    public function externalResource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }
}
