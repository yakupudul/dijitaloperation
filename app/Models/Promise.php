<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Söz defteri (Günlük Düzen 4.2): one promise the operator made in a message he sent ("perşembe gönderirim"). Asked
 * once ("işe ekle?"), then it is a task on his list or dismissed.
 *
 * @property int $user_id
 * @property string $text
 * @property string $status asked | added | rejected
 */
class Promise extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['text' => 'encrypted', 'due_on' => 'date'];
    }
}
