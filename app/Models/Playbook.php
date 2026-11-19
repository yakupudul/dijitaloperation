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
    /** Not `playbooks`: that name still holds the old Prompt 45 table on the live database (see the 2026_11_18 migration). */
    protected $table = 'brain_playbooks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lessons' => 'array'];
    }
}
