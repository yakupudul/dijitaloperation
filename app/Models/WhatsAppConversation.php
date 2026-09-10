<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime', 'suggested_at' => 'datetime',
            'revision' => 'integer', 'suggested_revision' => 'integer',
            'context_truncated' => 'boolean', 'suggestion' => 'encrypted',
            'rationale' => 'encrypted', 'summary' => 'encrypted',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }
}
