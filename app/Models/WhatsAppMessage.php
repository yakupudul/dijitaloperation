<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'sent_at' => 'datetime', 'is_history' => 'boolean'];
    }
}
