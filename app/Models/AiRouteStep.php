<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRouteStep extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'route_key',
        'provider',
        'model',
        'position',
        'enabled',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
        'enabled' => 'boolean',
    ];
}
