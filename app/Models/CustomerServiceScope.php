<?php

namespace App\Models;

use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceCadence;
use App\Enums\ServiceScopeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerServiceScope extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'service_definition_id',
        'status',
        'brand_applicability_mode',
        'owner_user_id',
        'cadence',
        'reporting_cadence',
        'started_at',
        'paused_at',
        'ended_at',
        'note',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<ServiceDefinition, $this>
     */
    public function serviceDefinition(): BelongsTo
    {
        return $this->belongsTo(ServiceDefinition::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(
            Brand::class,
            'customer_service_scope_brands',
            'customer_service_scope_id',
            'brand_id'
        )->withTimestamps();
    }

    /**
     * @return HasMany<CustomerServiceScopeInclusion, $this>
     */
    public function inclusions(): HasMany
    {
        return $this->hasMany(CustomerServiceScopeInclusion::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<CustomerServiceScopeExclusion, $this>
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(CustomerServiceScopeExclusion::class)->orderBy('sort_order');
    }

    public function appliesToBrand(Brand $brand): bool
    {
        if ((int) $brand->customer_id !== (int) $this->customer_id) {
            return false;
        }

        if ($this->brand_applicability_mode === ServiceBrandApplicabilityMode::CustomerWide) {
            return true;
        }

        return $this->brands->contains('id', $brand->id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceScopeStatus::class,
            'brand_applicability_mode' => ServiceBrandApplicabilityMode::class,
            'cadence' => ServiceCadence::class,
            'reporting_cadence' => ServiceCadence::class,
            'started_at' => 'date',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
