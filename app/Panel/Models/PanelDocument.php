<?php

namespace App\Panel\Models;

use App\Models\User;
use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A file the brand and the agency share: sent on a support message or put on the brand's shelf. Its lasting home is the brand's Drive folder. */
class PanelDocument extends Model
{
    use BelongsToBrand;

    protected $fillable = ['ticket_id', 'ticket_message_id', 'panel_user_id', 'operator_id', 'name', 'mime', 'size', 'path', 'drive_file_id', 'drive_synced_at', 'drive_error'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'drive_synced_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PanelTicket::class, 'ticket_id');
    }

    public function inDrive(): bool
    {
        return filled($this->drive_file_id);
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->size;

        return $bytes >= 1_048_576 ? number_format($bytes / 1_048_576, 1, ',', '.').' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
