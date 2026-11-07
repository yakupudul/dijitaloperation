<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What was done about one risk signal of a brand for one period: put off until a day, or handled (Aşama 3). */
class BrandSignal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snoozed_until' => 'datetime', 'handled_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
