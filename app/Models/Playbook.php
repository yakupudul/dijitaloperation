<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A book's stored state (Marka Düzeni 2.4): the operator's edit of the text shipped with the app (body null = the
 * shipped text stands) and the lessons added to it.
 *
 * @property string $key
 * @property string $title
 */
class Playbook extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lessons' => 'array'];
    }
}
