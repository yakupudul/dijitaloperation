<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message of a social inbox thread (a DM, a comment or a reply to it). */
class SocialMessage extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SocialThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(SocialThread::class, 'social_thread_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
