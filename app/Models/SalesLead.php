<?php

namespace App\Models;

use App\Services\Sales\SalesAnalysisWriter;
use App\Support\Sales\LeadKinds;
use App\Support\Sales\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sales record (Satış): a business or a person who may buy — of any kind (Tür: klinik, muayenehane, hekim, fabrika,
 * mağaza…; the record itself is never named after one), with its source, stage, next step and the timeline of what
 * happened. Its lifecycle runs aday → müşteri → eski müşteri; the money lives in its opportunities (SalesOpportunity)
 * and, once won, in the brand's agreement. A card linked to a customer stays alive for the next sale.
 */
class SalesLead extends Model
{
    protected $table = 'sales_leads';

    protected $guarded = ['id'];

    public const array SOURCES = [
        'whatsapp' => 'WhatsApp', 'form' => 'Web site formu', 'afc' => 'All for Clinics', 'link' => 'Paylaşılan site',
        'phone' => 'Çağrı', 'referral' => 'Referans', 'meta_form' => 'Meta formu',
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'other' => 'Diğer',
    ];

    /**
     * Where the prospect stands with us (yakup 2026-11-05: a category is the prospect's status, not their field). The open
     * steps move forward by themselves from what is seen (a message, a reply, a meeting, a price, "düşüneyim"); the
     * operator can set any of them, and that choice wins.
     */
    public const array STAGES = [
        'new' => 'Yeni', 'contacted' => 'İlk mesaj atıldı', 'talking' => 'Konuşuluyor', 'meeting' => 'Görüşme planlandı',
        'priced' => 'Teklif verildi', 'thinking' => 'Düşünüyor', 'later' => 'Sonra', 'won' => 'Kazanıldı', 'lost' => 'Kaybedildi',
    ];

    /**
     * The six statuses the Satış table filters by (yakup 2026-11-08). Only "Güçlü aday" is stored as its own mark
     * (is_strong, set by the operator; Claude may suggest it in strong_hint); the others are read from the stage and
     * the lifecycle, so the funnel, the reports and the inbox keep working on the same fields.
     */
    public const array STATUSES = [
        'devam' => 'Devam eden', 'guclu' => 'Güçlü aday', 'kararsiz' => 'Kararsız', 'olumsuz' => 'Olumsuz',
        'aday_degil' => 'Aday olmayan', 'musteri' => 'Müşteri',
    ];

    /** Kimden: whether the record came through Moximu's own channels or by someone's recommendation. */
    public const array CAME_VIA = ['moximu' => 'Moximu', 'referral' => 'Referans'];

    /** The open steps in order; an automatic move only goes forward along this line ("Sonra" is set by hand). */
    public const array FLOW = ['new', 'contacted', 'talking', 'meeting', 'priced', 'thinking'];

    /** Where they heard of us, apart from the channel they wrote on (a Google search, then a WhatsApp message). */
    public const array HEARD_FROM = [
        'google' => 'Google araması', 'maps' => 'Google Haritalar', 'google_ads' => 'Google reklamı', 'meta_ads' => 'Instagram / Facebook reklamı',
        'instagram' => 'Instagram', 'youtube' => 'YouTube', 'referral' => 'Tavsiye', 'afc' => 'All for Clinics', 'other' => 'Diğer',
    ];

    /** What was offered (Moximu's packages, as on the site's price list; generic names, the site's "Klinik SEO" is its clinic-facing name). */
    public const array SERVICES = [
        'seo_gbp' => 'SEO + İşletme Profili', 'seo' => 'SEO', 'gbp' => 'İşletme Profili', 'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads', 'web' => 'Web sitesi', 'other' => 'Diğer',
    ];

    public const array LOST_REASONS = [
        'silent' => 'Sessiz kaldı', 'price' => 'Fiyat', 'other_agency' => 'Başka ajans', 'not_fit' => 'Uygun değil', 'later' => 'Sonra',
        'irrelevant' => 'İlgisiz (aday değildi)', 'other' => 'Diğer',
    ];

    /**
     * Where the record stands in its life: aday (prospect), müşteri (customer: at least one won sale, the card stays
     * open for upsells), eski müşteri (former), ilgisiz (a chat that was never a prospect: a supplier, spam, a friend;
     * left out of every count and report).
     */
    public const array LIFECYCLES = ['prospect' => 'Aday', 'customer' => 'Müşteri', 'former' => 'Eski müşteri', 'irrelevant' => 'İlgisiz'];

    /** A prospect who really talks (or a form whose site was just read) gets the full analysis once, by itself. */
    protected static function booted(): void
    {
        // How far the card got (the month report's funnel; a lost card keeps where it was lost).
        static::saving(function (SalesLead $lead): void {
            // Since when the card is in its stage ("12 gündür Düşünüyor").
            if (! $lead->exists || $lead->isDirty('stage')) {
                $lead->stage_at = now();
            }
            $at = array_search($lead->stage, self::FLOW, true);
            $peak = $lead->peak_stage !== null ? array_search($lead->peak_stage, self::FLOW, true) : false;
            if ($at !== false && ($peak === false || $at > $peak)) {
                $lead->peak_stage = $lead->stage;
            }
            // The lifecycle follows the sale: a won prospect is a customer; a card closed as "ilgisiz" leaves the counts.
            if ($lead->stage === 'won' && in_array($lead->lifecycle, [null, 'prospect', 'former'], true)) {
                $lead->lifecycle = 'customer';
            } elseif ($lead->stage === 'lost' && $lead->lost_reason === 'irrelevant') {
                $lead->lifecycle = 'irrelevant';
            } elseif ($lead->lifecycle === null) {
                $lead->lifecycle = 'prospect';
            }
        });
        static::saved(function (SalesLead $lead): void {
            $talking = in_array($lead->stage, ['talking', 'meeting', 'priced', 'thinking'], true);
            $inbound = in_array($lead->source, ['form', 'meta_form'], true);
            if (($lead->wasChanged('stage') && $talking) || ($lead->wasChanged('findings_status') && $lead->findings_status === 'ready' && ($talking || $inbound))) {
                try {
                    SalesAnalysisWriter::queueIfWorth($lead);
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'next_step_at' => 'datetime', 'last_contact_at' => 'datetime', 'last_incoming_at' => 'datetime',
            'won_at' => 'datetime', 'lesson_at' => 'datetime', 'lost_at' => 'datetime', 'findings_at' => 'datetime', 'findings' => 'array',
            'followup_step' => 'integer', 'site_scan' => 'array', 'origin' => 'array', 'draft_send_on' => 'date', 'draft_at' => 'datetime', 'analysis_at' => 'datetime',
            'offer_amount' => 'integer', 'offer_confirmed' => 'boolean', 'stage_at' => 'datetime', 'classification' => 'array',
            'research' => 'array', 'research_at' => 'datetime', 'is_strong' => 'boolean', 'name_checked_at' => 'datetime',
            'history_only' => 'boolean', 'archived_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesLeadEvent::class, 'lead_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function sectorCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'sector_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(SalesOpportunity::class, 'lead_id')->orderByDesc('id');
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', ['won', 'lost']);
    }

    /** @param  Builder<self>  $query records that count as sales work: not the irrelevant ones, not the archived history */
    public function scopeRelevant(Builder $query): Builder
    {
        return $query->where('lifecycle', '!=', 'irrelevant')->whereNull('archived_at');
    }

    /** @param  Builder<self>  $query the cards put aside as old history (Satış › Arşiv) */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** Why a card sits in the archive (SalesTidy). */
    public const array ARCHIVE_REASONS = [
        'history' => 'Geçmişten geldi, 14 günden uzun sessiz',
        'manual' => 'Elle arşive alındı',
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archiveLabel(): string
    {
        return self::ARCHIVE_REASONS[$this->archive_reason] ?? 'Arşivde';
    }

    public function isCustomer(): bool
    {
        return $this->lifecycle === 'customer';
    }

    public function isIrrelevant(): bool
    {
        return $this->lifecycle === 'irrelevant';
    }

    public function lifecycleLabel(): string
    {
        return self::LIFECYCLES[$this->lifecycle] ?? (string) $this->lifecycle;
    }

    public function kindLabel(): ?string
    {
        return LeadKinds::label($this->kind);
    }

    /** The kind Claude suggested but the operator has not confirmed yet (null when none or already confirmed). */
    public function suggestedKind(): ?string
    {
        $c = (array) ($this->classification ?? []);

        return ($c['confirmed'] ?? false) === true ? null : (isset($c['kind']) && is_string($c['kind']) ? $c['kind'] : null);
    }

    /** The one open opportunity (the sale being pursued now), when there is one. */
    public function openOpportunity(): ?SalesOpportunity
    {
        return $this->opportunities()->where('status', 'open')->orderByDesc('id')->first();
    }

    /** What the card is called in lists: the person, else the business, else the phone or site. */
    public function title(): string
    {
        foreach ([$this->name, $this->company, $this->website_host, $this->phone] as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return 'Aday #'.$this->id;
    }

    /** The second line: business · city · branch (whatever is known, without the title's own value). */
    public function subtitle(): string
    {
        $parts = [];
        foreach ([$this->company, $this->city, $this->branch] as $part) {
            if (filled($part) && $part !== $this->title()) {
                $parts[] = $part;
            }
        }

        return implode(' · ', $parts);
    }

    /** Where the record stands in the six-status table. */
    public function status(): string
    {
        if ($this->lifecycle === 'irrelevant') {
            return 'aday_degil';
        }
        if (in_array($this->lifecycle, ['customer', 'former'], true) || $this->stage === 'won') {
            return 'musteri';
        }
        if ($this->stage === 'lost') {
            return 'olumsuz';
        }
        if ($this->is_strong) {
            return 'guclu';
        }

        return in_array($this->stage, ['thinking', 'later'], true) ? 'kararsiz' : 'devam';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status()];
    }

    /** Kimden, in one line: "Moximu", "Referans · Dr. Ayşe" or '' when not chosen yet. */
    public function cameViaLabel(): string
    {
        if ($this->came_via === 'referral') {
            $who = $this->referrer_customer_id !== null ? Customer::query()->whereKey($this->referrer_customer_id)->value('name')
                : ($this->referrer_id !== null ? Referrer::query()->whereKey($this->referrer_id)->value('name') : null);

            return 'Referans'.($who ? ' · '.$who : '');
        }

        return $this->came_via === 'moximu' ? 'Moximu' : '';
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['won', 'lost'], true);
    }

    public function isDue(): bool
    {
        return $this->isOpen() && $this->next_step_at !== null && $this->next_step_at->lte(now()->endOfDay());
    }

    /**
     * A real offer on the table: the operator entered the amount, or the prospect's own move put the card at "teklif"
     * (they asked the price or answered it). An amount only read from our own message is not one (Satış Düzeni 1.1).
     *
     * @param  Builder<self>  $query
     */
    public function scopeRealOffers(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('offer_confirmed', true)->orWhereIn('stage', ['priced', 'thinking']));
    }

    public function hasRealOffer(): bool
    {
        return (bool) $this->offer_confirmed || in_array($this->stage, ['priced', 'thinking'], true);
    }

    /** Moves the stage forward to $stage when it is further along the open line; never back, never out of won/lost. */
    public function advanceTo(string $stage): bool
    {
        // A card put off for later moves again as soon as the prospect writes or a step is taken.
        $from = $this->stage === 'later' ? -1 : array_search($this->stage, self::FLOW, true);
        $to = array_search($stage, self::FLOW, true);
        if ($from === false || $to === false || $to <= $from) {
            return false;
        }
        $this->stage = $stage;

        return true;
    }

    /**
     * The ball is ours: they wrote last and we have not answered (in WhatsApp, a call or a sent text). A card known only
     * from imported history never owes an answer: whether it was answered on the phone is not known (yakup 2026-11-09).
     */
    public function awaitsUs(): bool
    {
        return ! $this->history_only && $this->last_incoming_at !== null && ($this->last_contact_at === null || $this->last_incoming_at->gt($this->last_contact_at));
    }

    /** The last time either side spoke (a message either way, a call, a meeting), or null when nothing yet. */
    public function lastTouchAt(): ?CarbonInterface
    {
        $at = $this->last_contact_at;
        if ($this->last_incoming_at !== null && ($at === null || $this->last_incoming_at->gt($at))) {
            $at = $this->last_incoming_at;
        }

        return $at;
    }

    /** Whole days the card has been in its stage. */
    public function stageDays(): int
    {
        $since = $this->stage_at ?? $this->created_at;

        return $since === null ? 0 : (int) max(0, $since->copy()->startOfDay()->diffInDays(now()->startOfDay()));
    }

    public function serviceLabel(): ?string
    {
        return $this->offer_service !== null ? (self::SERVICES[$this->offer_service] ?? $this->offer_service) : null;
    }

    /**
     * The monthly price in a text we sent ("14.000 TL + KDV/ay"): a price next to "ay/aylık" wins, else the first one.
     * Null when the text names no price.
     */
    public static function priceIn(string $text): ?int
    {
        if (preg_match_all('/(\d{1,3}(?:[.\s]\d{3})+|\d{4,6})\s*(?:tl|₺)([^\n]{0,24})/iu', $text, $m, PREG_SET_ORDER) === 0) {
            return null;
        }
        $pick = $m[0];
        foreach ($m as $match) {
            if (preg_match('/\/\s*ay|aylık|ayda/iu', $match[2]) === 1) {
                $pick = $match;
                break;
            }
        }
        $value = (int) preg_replace('/\D+/', '', $pick[1]);

        return $value >= 1000 && $value <= 999_999 ? $value : null;
    }

    /** Which package a text talks about, when it is clear. */
    public static function serviceIn(string $text): ?string
    {
        $t = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $text));
        $seo = str_contains($t, 'seo');
        $gbp = str_contains($t, 'işletme profili') || str_contains($t, 'harita') || str_contains($t, 'gbp');

        return match (true) {
            $seo && $gbp => 'seo_gbp',
            $seo => 'seo',
            $gbp => 'gbp',
            str_contains($t, 'google ads') || str_contains($t, 'google reklam') => 'google_ads',
            str_contains($t, 'meta') || str_contains($t, 'instagram reklam') => 'meta_ads',
            str_contains($t, 'web sitesi') || str_contains($t, 'site yap') => 'web',
            default => null,
        };
    }

    /** A price seen in a sent text fills the offer as a suggestion; an offer the operator confirmed is never touched. */
    public function suggestOfferFrom(string $text): bool
    {
        $price = self::priceIn($text);
        if ($price === null || $this->offer_confirmed) {
            return false;
        }
        $this->offer_amount = $price;
        $this->offer_service = self::serviceIn($text) ?? $this->offer_service;

        return true;
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /** The phone in international digits for wa.me; null when the typed number cannot be right (see phoneIssue()). */
    public function waNumber(): ?string
    {
        return PhoneNumber::check($this->phone)['digits'];
    }

    /** Why the number is wrong or doubtful ("Numara 9 haneli…", "Sabit hat…"); null when it is fine or empty. */
    public function phoneIssue(): ?string
    {
        return PhoneNumber::check($this->phone)['issue'];
    }
}
