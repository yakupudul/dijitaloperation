<?php

namespace App\Models;

use Database\Factories\LeadOutcomeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client-side lead and the outcome the clinic reported back (lead quality loop — not a CRM). Keyed by
 * (brand, lead_source, lead_ref); status `new` means no outcome yet. Contact details are never stored.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $lead_source
 * @property string $lead_ref
 * @property string $status
 */
class LeadOutcome extends Model
{
    /** @use HasFactory<LeadOutcomeFactory> */
    use HasFactory;

    public const string STATUS_NEW = 'new';

    /** status => label; `new` = no outcome marked yet */
    public const array STATUSES = [
        'new' => 'Sonuç girilmedi',
        'contacted' => 'Ulaşıldı',
        'appointment' => 'Randevu',
        'sale' => 'Satış',
        'junk' => 'Geçersiz / spam',
        'unreachable' => 'Ulaşılamadı',
    ];

    /** Outcomes counted as a qualified lead. */
    public const array QUALIFIED = ['appointment', 'sale'];

    public const array SOURCES = [
        'meta_lead_form' => 'Meta form reklamı',
        'website_form' => 'Web sitesi formu',
        'whatsapp' => 'WhatsApp',
        'phone_call' => 'Telefon araması',
        'other' => 'Diğer',
    ];

    protected $guarded = ['id'];

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<User, $this> */
    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->lead_source] ?? $this->lead_source;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'lead_received_at' => 'datetime',
            'marked_at' => 'datetime',
            'value_try' => 'decimal:2',
        ];
    }
}
