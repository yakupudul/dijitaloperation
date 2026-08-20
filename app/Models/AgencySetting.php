<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
    'mail_enabled',
    'mail_from_name',
    'mail_from_address',
    'mail_host',
    'mail_port',
    'mail_username',
    'mail_encryption',
    'mail_password',
])]
#[Hidden([
    'mail_password',
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
            'mail_enabled' => 'boolean',
            'mail_port' => 'integer',
            'mail_password' => 'encrypted',
        ];
    }
}
