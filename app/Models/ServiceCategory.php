<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    protected $fillable = ['code', 'name', 'normalized_key'];

    public static function options(): array
    {
        return static::query()->orderBy('name')->pluck('name', 'code')->all();
    }
}
