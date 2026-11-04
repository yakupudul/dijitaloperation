<?php

namespace App\Models;

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

    public const array STAGES = [
        'new' => 'Yeni', 'contacted' => 'İlk mesaj gönderildi', 'priced' => 'Fiyat konuşuldu', 'won' => 'Kazanıldı', 'lost' => 'Kaybedildi',
    ];

    public const array LOST_REASONS = [
        'silent' => 'Sessiz kaldı', 'price' => 'Fiyat', 'other_agency' => 'Başka ajans', 'not_fit' => 'Uygun değil', 'later' => 'Sonra', 'other' => 'Diğer',
    ];

    protected function casts(): array
    {
        return [
            'next_step_at' => 'datetime', 'last_contact_at' => 'datetime', 'last_incoming_at' => 'datetime',
            'won_at' => 'datetime', 'lost_at' => 'datetime', 'findings_at' => 'datetime', 'findings' => 'array',
            'followup_step' => 'integer', 'site_scan' => 'array', 'draft_send_on' => 'date', 'draft_at' => 'datetime',
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
