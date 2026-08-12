<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'name',
    'type',
    'legal_name',
    'status',
    'industry',
    'hq_country',
    'hq_city',
    'primary_email',
    'primary_phone',
    'service_started_at',
    'services_received',
    'services',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->isDirty('services') && is_array($customer->services)) {
                $customer->services_received = AgencyServiceOptions::toLegacyText($customer->services);
            }
        });
    }

    /**
     * @return HasMany<CustomerContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function responsibleUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasManyThrough<DigitalAsset, Brand, $this>
     */
    public function digitalAssets(): HasManyThrough
    {
        return $this->hasManyThrough(DigitalAsset::class, Brand::class);
    }

    public function industryLabel(): string
    {
        return IndustryOptions::label($this->industry);
    }

    public function hqDisplay(): string
    {
        return CountryOptions::formatHq($this->hq_city, $this->hq_country);
    }

    /**
     * @return list<string>
     */
    public function serviceLabels(): array
    {
        return AgencyServiceOptions::labels($this->services);
    }

    /**
     * Sync structured services and keep legacy text column readable.
     *
     * @param  list<string>  $codes
     */
    public function syncServices(array $codes): void
    {
        $codes = array_values(array_unique(array_filter($codes)));
        $this->services = $codes;
        $this->services_received = AgencyServiceOptions::toLegacyText($codes);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'service_started_at' => 'date',
            'services' => 'array',
        ];
    }
}
