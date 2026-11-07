<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The "rapor gönderildi" mark: the brand's monthly report for a month went to the customer (Aşama 3). */
class BrandReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function monthLabel(): string
    {
        return CarbonImmutable::parse($this->month.'-01')->locale('tr')->isoFormat('MMMM YYYY');
    }
}
