<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Faz 5 URL karnesi: refresh state, verdict counts and site-level checks of one website. */
class WebsiteUrlAudit extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'counts' => 'array', 'site_checks' => 'array', 'sources' => 'array', 'period' => 'array', 'groups' => 'array',
            'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }
}
