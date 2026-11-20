<?php

namespace App\Panel\Models;

use App\Models\User;
use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message of a support request, written by the brand's user or by the agency. */
class PanelTicketMessage extends Model
{
    use BelongsToBrand;

    protected $fillable = ['ticket_id', 'panel_user_id', 'operator_id', 'body', 'attachment_path', 'attachment_name'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PanelTicket::class, 'ticket_id');
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
