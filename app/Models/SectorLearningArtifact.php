<?php

namespace App\Models;

use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningArtifactStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sector_code',
    'stable_key',
    'artifact_kind',
    'status',
    'current_revision_id',
])]
class SectorLearningArtifact extends Model
{
    /**
     * @return HasMany<SectorLearningRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(SectorLearningRevision::class, 'artifact_id');
    }

    /**
     * @return BelongsTo<SectorLearningRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(SectorLearningRevision::class, 'current_revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'artifact_kind' => SectorLearningArtifactKind::class,
            'status' => SectorLearningArtifactStatus::class,
        ];
    }
}
