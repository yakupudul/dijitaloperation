<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerServiceScopeInclusion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_service_scope_id',
        'text',
        'sort_order',
    ];

    /**
     * @return BelongsTo<CustomerServiceScope, $this>
     */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(CustomerServiceScope::class, 'customer_service_scope_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
