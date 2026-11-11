<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A brand's start numbers (Marka Düzeni 2.3): one row per brand, taken once at its setup. */
class BrandBaseline extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_from' => 'immutable_date', 'period_to' => 'immutable_date', 'numbers' => 'array'];
    }
}
