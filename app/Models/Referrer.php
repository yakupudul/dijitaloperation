<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone outside the customer list who sends us prospects (Satış › Ayarlar › Referanslar). */
class Referrer extends Model
{
    protected $guarded = ['id'];

    /** @return HasMany<ReferralReward, $this> */
    public function rewards(): HasMany
    {
        return $this->hasMany(ReferralReward::class);
    }
}
