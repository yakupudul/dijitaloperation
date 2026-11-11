<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's access link (Bağlantı Düzeni 4.1): the channels whose access is asked from the customer and what the
 * customer marked as given on the public page.
 *
 * @property int $brand_id
 * @property string $token
 */
class AccessLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['asked' => 'array', 'done' => 'array', 'start' => 'array', 'opened_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
