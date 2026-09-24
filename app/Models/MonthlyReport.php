<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One brand's monthly report (Faz 9): frozen numbers, optional AI commentary, operator note. */
class MonthlyReport extends Model
{
    protected $fillable = ['brand_id', 'month', 'payload', 'operator_note', 'commentary', 'commentary_status', 'status', 'created_by', 'published_at', 'emailed_at', 'emailed_to'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'commentary' => 'array',
            'published_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
