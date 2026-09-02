<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'source_type',
    'original_filename',
    'status',
    'total_rows',
    'accepted_rows',
    'skipped_rows',
    'failed_rows',
    'error_summary',
    'created_by',
    'completed_at',
])]
class SearchQueryLibraryImport extends Model
{
    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
            'total_rows' => 'integer',
            'accepted_rows' => 'integer',
            'skipped_rows' => 'integer',
            'failed_rows' => 'integer',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(SearchQueryLibrarySourceRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
