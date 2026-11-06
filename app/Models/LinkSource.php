<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ortak kaynak havuzu (Backlink hizmeti, 2026-11-06): one place that can give a link (a directory, an association, a
 * local news site …), bound to a sector and a city instead of a brand, so a source that worked for one dental clinic
 * in Ankara is offered to the next one by itself. It keeps how to apply, a quality verdict (a source that sells posts
 * or lists hundreds of unrelated links is red and never offered) and what it brought: applied, won and the days until
 * the link came.
 */
class LinkSource extends Model
{
    public const array KINDS = [
        'dizin' => 'dizin', 'rehber' => 'şehir rehberi', 'saglik' => 'sağlık platformu', 'dernek' => 'dernek', 'oda' => 'meslek odası',
        'yerel_haber' => 'yerel haber', 'universite' => 'üniversite', 'tedarikci' => 'tedarikçi / üretici', 'etkinlik' => 'etkinlik / sponsorluk',
        'harita' => 'harita servisi', 'diger' => 'diğer',
    ];

    public const array APPLY_VIA = ['form' => 'form', 'eposta' => 'e-posta', 'telefon' => 'telefon', 'uyelik' => 'üyelik', 'bilinmiyor' => 'bilinmiyor'];

    public const array QUALITY = ['bekliyor' => 'denetlenmedi', 'uygun' => 'uygun', 'red' => 'reddedildi'];

    /** Kinds that are a listing of the business (künye / citation). */
    public const array CITATION_KINDS = ['dizin', 'rehber', 'saglik', 'harita'];

    /** Kinds that need a person: membership, a call, a visit, a sponsorship. */
    public const array PERSON_KINDS = ['dernek', 'oda', 'universite', 'tedarikci', 'etkinlik'];

    /** @var list<string> */
    protected $fillable = [
        'domain', 'name', 'url', 'kind', 'sector_id', 'city', 'apply_via', 'apply_url', 'contact_email', 'fee', 'fee_evidence_url',
        'quality', 'quality_note', 'authority', 'applied_count', 'won_count', 'days_total', 'origin', 'checked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['checked_at' => 'immutable_datetime', 'authority' => 'integer', 'applied_count' => 'integer', 'won_count' => 'integer', 'days_total' => 'integer'];
    }

    /** @return HasMany<BacklinkSource, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BacklinkSource::class, 'link_source_id');
    }

    /** @return BelongsTo<ServiceCategory, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'sector_id');
    }

    /** Share of applications that brought a link, 0–100; null before the first application. */
    public function winRate(): ?int
    {
        return $this->applied_count > 0 ? (int) round(100 * $this->won_count / $this->applied_count) : null;
    }

    /** Average days from the application to the link; null before the first link. */
    public function averageDays(): ?int
    {
        return $this->won_count > 0 ? (int) round($this->days_total / $this->won_count) : null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? (string) $this->kind;
    }
}
