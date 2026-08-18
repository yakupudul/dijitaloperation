<?php

namespace App\Models;

use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceStatus;
use Database\Factories\BrandExperienceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'brand_id',
    'status',
    'current_revision_id',
    'origin',
    'recorded_by',
    'idempotency_key',
    'supersedes_experience_id',
    'superseded_by_experience_id',
])]
class BrandExperience extends Model
{
    /** @use HasFactory<BrandExperienceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<BrandExperienceRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(BrandExperienceRevision::class);
    }

    /**
     * @return BelongsTo<BrandExperienceRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(BrandExperienceRevision::class, 'current_revision_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<BrandExperience, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_experience_id');
    }

    /**
     * @return BelongsTo<BrandExperience, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_experience_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BrandExperienceStatus::class,
            'origin' => BrandExperienceOrigin::class,
        ];
    }
}
