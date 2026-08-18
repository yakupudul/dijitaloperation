<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'agency_name',
    'portal_name',
    'locale',
    'timezone',
    'display_currency',
    'week_starts_on',
    'analytical_date_range',
    'logo_path',
    'favicon_path',
])]
class AgencySetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agency_name' => 'string',
            'portal_name' => 'string',
            'locale' => 'string',
            'timezone' => 'string',
            'display_currency' => 'string',
            'week_starts_on' => 'string',
            'analytical_date_range' => 'string',
        ];
    }
}
