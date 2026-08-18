<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectReportShareGrant extends Model
{
    protected $fillable = [
        'prospect_report_snapshot_id',
        'locator_token_hash',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProspectReportSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ProspectReportSnapshot::class, 'prospect_report_snapshot_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
