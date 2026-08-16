<?php

namespace App\Models;

use App\Enums\ReportShareAccessEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only access audit event for a share grant (Prompt 60).
 */
class ReportShareAccessEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'share_grant_id',
        'event_type',
        'share_session_id',
        'ip_hash',
        'user_agent_hash',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => ReportShareAccessEventType::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportShareGrant, $this>
     */
    public function shareGrant(): BelongsTo
    {
        return $this->belongsTo(ReportShareGrant::class, 'share_grant_id');
    }

    /**
     * @return BelongsTo<ReportShareSession, $this>
     */
    public function shareSession(): BelongsTo
    {
        return $this->belongsTo(ReportShareSession::class, 'share_session_id');
    }
}
