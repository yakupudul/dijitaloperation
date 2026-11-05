<?php

namespace App\Models;

use App\Services\Sales\SalesAnalysisWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One prospect card (Satış): a clinic or a person who may buy, with its source, stage, next step and the timeline of
 * what happened. A won lead becomes a customer and a brand; a lost one keeps its reason.
 */
class SalesLead extends Model
{
    protected $table = 'sales_leads';

    protected $guarded = ['id'];

    public const array SOURCES = [
        'whatsapp' => 'WhatsApp', 'form' => 'moximu.com formu', 'afc' => 'All for Clinics', 'link' => 'Paylaşılan site',
        'phone' => 'Telefon', 'referral' => 'Referans', 'meta_form' => 'Meta formu', 'other' => 'Diğer',
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

    /** The open steps in order; an automatic move only goes forward along this line ("Sonra" is set by hand). */
    public const array FLOW = ['new', 'contacted', 'talking', 'meeting', 'priced', 'thinking'];

    /** Where they heard of us, apart from the channel they wrote on (a Google search, then a WhatsApp message). */
    public const array HEARD_FROM = [
        'google' => 'Google araması', 'maps' => 'Google Haritalar', 'google_ads' => 'Google reklamı', 'meta_ads' => 'Instagram / Facebook reklamı',
        'instagram' => 'Instagram', 'youtube' => 'YouTube', 'referral' => 'Tavsiye', 'afc' => 'All for Clinics', 'other' => 'Diğer',
    ];

    public const array LOST_REASONS = [
        'silent' => 'Sessiz kaldı', 'price' => 'Fiyat', 'other_agency' => 'Başka ajans', 'not_fit' => 'Uygun değil', 'later' => 'Sonra', 'other' => 'Diğer',
    ];

    /** A prospect who really talks (or a form whose site was just read) gets the full analysis once, by itself. */
    protected static function booted(): void
    {
        // How far the card got (the month report's funnel; a lost card keeps where it was lost).
        static::saving(function (SalesLead $lead): void {
            $at = array_search($lead->stage, self::FLOW, true);
            $peak = $lead->peak_stage !== null ? array_search($lead->peak_stage, self::FLOW, true) : false;
            if ($at !== false && ($peak === false || $at > $peak)) {
                $lead->peak_stage = $lead->stage;
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
            'won_at' => 'datetime', 'lost_at' => 'datetime', 'findings_at' => 'datetime', 'findings' => 'array',
            'followup_step' => 'integer', 'site_scan' => 'array', 'origin' => 'array', 'draft_send_on' => 'date', 'draft_at' => 'datetime', 'analysis_at' => 'datetime',
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

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', ['won', 'lost']);
    }

    /** What the card is called in lists: the person, else the clinic, else the phone or site. */
    public function title(): string
    {
        foreach ([$this->name, $this->clinic, $this->website_host, $this->phone] as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return 'Aday #'.$this->id;
    }

    /** The second line: clinic · city · branch (whatever is known, without the title's own value). */
    public function subtitle(): string
    {
        $parts = [];
        foreach ([$this->clinic, $this->city, $this->branch] as $part) {
            if (filled($part) && $part !== $this->title()) {
                $parts[] = $part;
            }
        }

        return implode(' · ', $parts);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->stage, ['won', 'lost'], true);
    }

    public function isDue(): bool
    {
        return $this->isOpen() && $this->next_step_at !== null && $this->next_step_at->lte(now()->endOfDay());
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

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? $this->stage;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    /** The phone in international digits for wa.me (Turkish numbers get the country code). */
    public function waNumber(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10 && str_starts_with($digits, '5')) {
            return '90'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '05')) {
            return '9'.$digits;
        }

        return $digits;
    }
}
