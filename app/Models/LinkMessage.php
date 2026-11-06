<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An e-mail that left the Bağlantı masası with the operator's click: the first one or the follow-up. */
class LinkMessage extends Model
{
    /** @var list<string> */
    protected $fillable = ['backlink_source_id', 'kind', 'to', 'subject', 'body', 'sent_by', 'sent_at', 'error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BacklinkSource, $this> */
    public function row(): BelongsTo
    {
        return $this->belongsTo(BacklinkSource::class, 'backlink_source_id');
    }
}
