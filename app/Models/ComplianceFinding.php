<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceFinding extends Model
{
    public const string STATUS_OPEN = 'open';

    public const string STATUS_RESOLVED = 'resolved';

    public const string STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'brand_id', 'digital_asset_id', 'compliance_rule_id', 'source', 'subject_ref', 'subject_label', 'matched', 'excerpt',
        'fingerprint', 'status', 'note', 'status_changed_by', 'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<ComplianceRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'compliance_rule_id');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
