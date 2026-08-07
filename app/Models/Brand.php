<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
