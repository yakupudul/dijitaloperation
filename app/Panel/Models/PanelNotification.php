<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;

/** A line under the bell of one user of one brand. */
class PanelNotification extends Model
{
    use BelongsToBrand;

    protected $fillable = ['panel_user_id', 'type', 'title', 'url', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
