<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OTP / verification challenge for a share grant (Prompt 60).
 */
class ReportShareVerificationChallenge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'share_grant_id',
        'code_hash',
        'expires_at',
        'consumed_at',
        'attempts',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
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
}
