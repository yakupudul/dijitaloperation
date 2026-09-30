<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filter basket: a NEGATIVE list (like Google Ads negatives) — a query containing a term is deleted entirely, never
 * stripped. Organised per sector (sector_id null = general) but every term applies to every query.
 */
class FilterTerm extends Model
{
    public const array SOURCES = ['manual', 'ai'];

    /** @var list<string> */
    protected $fillable = [
        'sector_id',
        'term',
        'source',
        'created_by',
    ];

    /** @return BelongsTo<ServiceCategory, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'sector_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
