<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of a setting (Ayarlar › Değişiklik kaydı): who, when, the value before and after. */
class SettingChange extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'encrypted:array', 'after' => 'encrypted:array', 'secret' => 'boolean', 'undone_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
