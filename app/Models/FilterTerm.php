<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Filter basket: a plain list of words / phrases removed from queries (brand names, locations, whatever the operator or AI
 * adds). sector_id null = global; otherwise the term applies to that sector only.
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
