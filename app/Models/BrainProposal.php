<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One prepared change waiting for an operator: approve → the system applies it; reject → nothing happens. */
class BrainProposal extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPLIED = 'applied';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_STALE = 'stale';

    /** The system / AI looked and found nothing to propose; kept so the same subject is not asked again. */
    public const string STATUS_NOTHING = 'nothing';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'current' => 'array',
            'proposed' => 'array',
            'confidence' => 'float',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
