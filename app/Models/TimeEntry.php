<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Time spent on a customer / brand (for profitability against the monthly fee). */
class TimeEntry extends Model
{
    public const array CATEGORIES = ['seo' => 'SEO', 'ads' => 'Google Ads', 'meta' => 'Meta', 'gbp' => 'İşletme Profili', 'web' => 'Web sitesi', 'content' => 'İçerik',
        'report' => 'Rapor', 'meeting' => 'Toplantı / iletişim', 'other' => 'Diğer'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['worked_on' => 'date', 'minutes' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
