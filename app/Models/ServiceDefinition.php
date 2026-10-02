<?php

namespace App\Models;

use App\Enums\ServiceCatalogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceDefinition extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'catalog_status',
        'sort_order',
    ];

    /**
     * @return HasMany<CustomerServiceScope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(CustomerServiceScope::class);
    }

    public function isAssignable(): bool
    {
        return $this->catalog_status === ServiceCatalogStatus::Available;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'catalog_status' => ServiceCatalogStatus::class,
            'sort_order' => 'integer',
        ];
    }
}
