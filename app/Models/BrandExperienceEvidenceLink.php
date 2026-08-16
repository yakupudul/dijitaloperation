<?php

namespace App\Models;

use App\Enums\BrandExperienceEvidenceRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_experience_revision_id',
    'evidence_id',
    'evidence_fingerprint',
    'role',
])]
class BrandExperienceEvidenceLink extends Model
{
    /**
     * @return BelongsTo<BrandExperienceRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(BrandExperienceRevision::class, 'brand_experience_revision_id');
    }

    /**
     * @return BelongsTo<Evidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => BrandExperienceEvidenceRole::class,
        ];
    }
}
