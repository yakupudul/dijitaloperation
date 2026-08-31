<?php

namespace App\Models;

use App\Models\IntelligenceCore\IntelligenceBusinessActionIdentity;
use App\Models\IntelligenceCore\IntelligenceEntityIdentity;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'customer_id',
    'name',
    'sector',
    'primary_country',
    'target_markets',
    'languages',
    'description',
    'audience',
    'offerings',
    'competitors',
    'logo_url',
])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function responsibleUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<DigitalAsset, $this>
     */
    public function digitalAssets(): HasMany
    {
        return $this->hasMany(DigitalAsset::class);
    }

    /**
     * Structured factual Brand Intelligence Context (optional one-to-one).
     *
     * @return HasOne<BrandIntelligenceContext, $this>
     */
    public function intelligenceContext(): HasOne
    {
        return $this->hasOne(BrandIntelligenceContext::class);
    }

    /**
     * @return HasMany<BrandGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(BrandGoal::class);
    }

    /**
     * @return HasMany<BrandOffering, $this>
     */
    public function offerings(): HasMany
    {
        return $this->hasMany(BrandOffering::class);
    }

    /** @return HasMany<IntelligenceSearchTermIdentity, $this> */
    public function intelligenceSearchTerms(): HasMany
    {
        return $this->hasMany(IntelligenceSearchTermIdentity::class);
    }

    /** @return HasMany<IntelligenceEntityIdentity, $this> */
    public function intelligenceEntities(): HasMany
    {
        return $this->hasMany(IntelligenceEntityIdentity::class);
    }

    /** @return HasMany<IntelligenceBusinessActionIdentity, $this> */
    public function intelligenceBusinessActions(): HasMany
    {
        return $this->hasMany(IntelligenceBusinessActionIdentity::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_markets' => 'array',
            'languages' => 'array',
        ];
    }
}
