<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['search_demand_page_ownership_id', 'version', 'change_type', 'snapshot', 'created_by', 'created_at'])]
class SearchDemandPageOwnershipVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function ownership(): BelongsTo
    {
        return $this->belongsTo(SearchDemandPageOwnership::class, 'search_demand_page_ownership_id');
    }
}
