<?php

namespace App\Models;

use App\Enums\ProspectIdentityStatus;
use App\Enums\ProspectSource;
use App\Enums\ProspectStatus;
use Database\Factories\ProspectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_name',
    'website_url',
    'source',
    'inquiry',
    'contact_name',
    'contact_email',
    'contact_phone',
    'country',
    'city',
    'identity_status',
    'status',
    'owner_user_id',
    'converted_customer_id',
    'converted_brand_id',
    'converted_at',
])]
class Prospect extends Model
{
    /** @use HasFactory<ProspectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ProspectStatus::class,
            'identity_status' => ProspectIdentityStatus::class,
            'source' => ProspectSource::class,
            'converted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function convertedBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'converted_brand_id');
    }

    /**
     * @return HasMany<ProspectResearchRun, $this>
     */
    public function researchRuns(): HasMany
    {
        return $this->hasMany(ProspectResearchRun::class);
    }

    /**
     * @return HasMany<ProspectEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(ProspectEvidence::class);
    }

    /**
     * @return HasMany<ProspectDiscoveryCandidate, $this>
     */
    public function discoveryCandidates(): HasMany
    {
        return $this->hasMany(ProspectDiscoveryCandidate::class);
    }

    /**
     * @return HasMany<ProspectSalesIntelligence, $this>
     */
    public function salesIntelligence(): HasMany
    {
        return $this->hasMany(ProspectSalesIntelligence::class);
    }

    /**
     * @return HasOne<ProspectSalesIntelligence, $this>
     */
    public function latestSalesIntelligence(): HasOne
    {
        return $this->hasOne(ProspectSalesIntelligence::class)->latestOfMany();
    }

    /**
     * @return HasOne<ProspectResearchRun, $this>
     */
    public function latestResearchRun(): HasOne
    {
        return $this->hasOne(ProspectResearchRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<ProspectActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ProspectActivity::class);
    }
}
