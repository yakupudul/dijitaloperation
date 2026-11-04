<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a prospect's timeline: a message in or out, a note, a finding, a report, a meeting, an offer, a stage
 * change, a task. Imported messages carry a fingerprint so the same export never doubles them.
 */
class SalesLeadEvent extends Model
{
    protected $table = 'sales_lead_events';

    protected $guarded = ['id'];

    public const array KINDS = [
        'message_in' => 'Mesaj geldi', 'message_out' => 'Mesaj gönderildi', 'note' => 'Not', 'call' => 'Arama',
        'finding' => 'Bulgu', 'report' => 'Rapor', 'meeting' => 'Görüşme', 'offer' => 'Teklif', 'stage' => 'Aşama',
        'task' => 'Görev', 'share' => 'Paylaşım', 'system' => 'Sistem',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime', 'meta' => 'array', 'body' => 'encrypted'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }
}
