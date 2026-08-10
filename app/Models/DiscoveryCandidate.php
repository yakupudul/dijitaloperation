<?php

namespace App\Models;

use Database\Factories\DiscoveryCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_id',
    'digital_asset_id',
    'run_id',
    'evidence_id',
    'fingerprint',
    'candidate_kind',
    'candidate_type',
    'target_field',
    'proposed_value',
    'support_json',
    'support_label',
    'status',
    'reviewed_by_id',
    'reviewed_at',
    'accepted_value',
    'was_edited',
])]
class DiscoveryCandidate extends Model
{
    /** @use HasFactory<DiscoveryCandidateFactory> */
    use HasFactory;

    public const string KIND_FACT = 'fact';

    public const string KIND_INFERENCE = 'inference';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_IGNORED = 'ignored';

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

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
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'support_json' => 'array',
            'reviewed_at' => 'datetime',
            'was_edited' => 'boolean',
        ];
    }
}
