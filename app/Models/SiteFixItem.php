<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A proposed website fix (ADR-070). Applied only through an Admin-approved ExternalWriteAction. */
class SiteFixItem extends Model
{
    public const array TYPES = [
        'seo_title' => ['SEO başlığı', 1],
        'seo_description' => ['Meta açıklama', 1],
        'alt_text' => ['Görsel alt metni', 1],
        'schema' => ['Yapılandırılmış veri', 1],
        'redirect' => ['301 yönlendirme', 2],
        'noindex' => ['Dizine ekleme (noindex)', 2],
        'canonical' => ['Canonical', 2],
        'internal_link' => ['İç bağlantı', 2],
        'content_update' => ['İçerik güncelleme', 3],
        'new_page' => ['Yeni sayfa', 3],
    ];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['current' => 'array', 'proposed' => 'array'];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<ExternalWriteAction, $this> */
    public function writeAction(): BelongsTo
    {
        return $this->belongsTo(ExternalWriteAction::class, 'write_action_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    /** The value that goes to WordPress (proposed.value). */
    public function value(): mixed
    {
        return is_array($this->proposed) ? ($this->proposed['value'] ?? null) : null;
    }
}
