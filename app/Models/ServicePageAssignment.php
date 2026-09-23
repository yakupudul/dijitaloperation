<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand offering ↔ website page mapping. Auto-filled by the SEO plan; operator answers are final.
 */
class ServicePageAssignment extends Model
{
    public const string STATUS_ASSIGNED = 'assigned';

    public const string STATUS_NONE = 'none';

    public const string STATUS_PENDING_QUESTION = 'pending_question';

    public const string SOURCE_AUTO = 'auto';

    public const string SOURCE_OPERATOR = 'operator';

    protected $guarded = [];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    public function isOperatorDecision(): bool
    {
        return $this->decision_source === self::SOURCE_OPERATOR;
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'candidates' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
