<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's goal (Aşama 5): `monthly_leads` and its `split` per channel once approved; `proposed_*` is Claude's latest
 * proposal (refreshed nightly, never overwriting an approved goal). `basis`: the months it was read from, this month so
 * far, and the brand's leads per organic click.
 */
class BrandTarget extends Model
{
    public const string PROPOSED = 'proposed';

    public const string APPROVED = 'approved';

    /** No measured demand to propose from. */
    public const string UNMEASURED = 'unmeasured';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'monthly_leads' => 'integer',
            'proposed_leads' => 'integer',
            'split' => 'array',
            'proposed_split' => 'array',
            'basis' => 'array',
            'proposed_at' => 'datetime',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED && $this->monthly_leads !== null;
    }

    /** The goal in use: the approved one. */
    public function goal(): ?int
    {
        return $this->isApproved() ? $this->monthly_leads : null;
    }
}
