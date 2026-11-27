<?php

namespace App\Panel\Models;

use App\Models\User;
use App\Panel\Support\BelongsToBrand;
use App\Panel\Support\RichText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One message of a support request, written by the brand's user or by the agency. */
class PanelTicketMessage extends Model
{
    use BelongsToBrand;

    protected $fillable = ['ticket_id', 'panel_user_id', 'operator_id', 'body', 'body_html', 'attachment_path', 'attachment_name'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PanelTicket::class, 'ticket_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PanelDocument::class, 'ticket_message_id');
    }

    /** The message as safe HTML: the editor's cleaned markup, or the plain text of an older message as paragraphs. */
    public function html(): string
    {
        return filled($this->body_html) ? RichText::clean((string) $this->body_html) : RichText::fromPlain((string) $this->body);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
