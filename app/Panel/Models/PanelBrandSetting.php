<?php

namespace App\Panel\Models;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** The panel switch of a brand and the secret address its site form posts to. */
class PanelBrandSetting extends Model
{
    protected $fillable = ['brand_id', 'is_enabled', 'form_token'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public static function forBrand(int $brandId): self
    {
        return self::query()->firstOrCreate(['brand_id' => $brandId], ['is_enabled' => true, 'form_token' => Str::random(48)]);
    }
}
