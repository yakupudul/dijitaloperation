<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's monthly session (Marka Düzeni 2.6): Claude's summary of the month, at most five jobs for the next one and
 * the lines of the one-page customer report. The operator approves once; the jobs then become findings in Claude's hands.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $month
 * @property string $status
 * @property string $token
 */
class BrandSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'encrypted', 'jobs' => 'encrypted:array', 'client_note' => 'encrypted', 'report_lines' => 'encrypted:array',
            'numbers' => 'array', 'approved_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function monthLabel(): string
    {
        return CarbonImmutable::parse($this->month.'-01')->locale('tr')->isoFormat('MMMM YYYY');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
