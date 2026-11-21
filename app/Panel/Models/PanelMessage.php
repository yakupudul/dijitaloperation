<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message of a conversation; the panel only shows it, nothing is sent from here. */
class PanelMessage extends Model
{
    use BelongsToBrand;

    protected $fillable = ['conversation_id', 'external_id', 'outgoing', 'type', 'body', 'sent_at', 'import_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'outgoing' => 'boolean', 'sent_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PanelConversation::class, 'conversation_id');
    }
}
