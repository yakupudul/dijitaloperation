<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authenticated share grant for a Report Snapshot (Prompt 60).
 */
class ReportShareGrant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'report_snapshot_id',
        'recipient_email',
        'recipient_name',
        'permissions',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'created_by',
        'locator_token_hash',
        'last_successful_access_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_successful_access_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportSnapshot, $this>
     */
    public function reportSnapshot(): BelongsTo
    {
        return $this->belongsTo(ReportSnapshot::class);
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function allowsHtml(): bool
    {
        return (bool) ($this->permissions['html_view'] ?? false);
    }

    public function allowsPdf(): bool
    {
        return (bool) ($this->permissions['pdf_download'] ?? false);
    }
}
