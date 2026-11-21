<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use App\Panel\Support\PhoneKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One chat of a brand with one contact on one channel. An old chat stays a chat until someone turns it into a lead. */
class PanelConversation extends Model
{
    use BelongsToBrand;

    protected $fillable = ['channel', 'contact_key', 'contact_name', 'contact_phone', 'person_id', 'origin', 'last_preview', 'last_outgoing', 'last_message_at', 'last_incoming_at', 'handled_at', 'message_count', 'meta'];

    protected function casts(): array
    {
        return ['last_preview' => 'encrypted', 'last_outgoing' => 'boolean', 'last_message_at' => 'datetime', 'last_incoming_at' => 'datetime', 'handled_at' => 'datetime', 'message_count' => 'integer', 'meta' => 'array'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(PanelPerson::class, 'person_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PanelMessage::class, 'conversation_id');
    }

    /** The name on the list: the person's, the contact's own, else the number. */
    public function title(bool $showPhone): string
    {
        $name = $this->person?->name ?: $this->contact_name;
        if ($name && $name !== $this->contact_phone) {
            return $name;
        }

        return $this->contact_phone ? ($showPhone ? $this->contact_phone : PhoneKey::mask($this->contact_phone)) : 'Numarası gizli kişi';
    }
}
