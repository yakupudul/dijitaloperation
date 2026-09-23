<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Otomatik kur" run for a Brand: proposed assets, resource bindings and services.
 * Nothing is applied until the operator approves (one click).
 */
class BrandSetupProposal extends Model
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_BUILDING = 'building';

    public const string STATUS_READY = 'ready';

    public const string STATUS_APPLIED = 'applied';

    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_BUILDING], true);
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'services' => 'array',
            'summary' => 'array',
            'apply_result' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
