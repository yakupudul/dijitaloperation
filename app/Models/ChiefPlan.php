<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Şef's weekly plan: a headline and at most 10 lines (brand, task, why) across the active brands. */
class ChiefPlan extends Model
{
    /** @var list<string> */
    protected $fillable = ['week_start', 'headline', 'plan', 'status', 'error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['week_start' => 'immutable_date', 'plan' => 'array'];
    }
}
