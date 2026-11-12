<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Finans belgesi: a file with what was read from it. out = an invoice written to a payer, in = an expense document,
 * receipt = a dekont a payer sent. It comes from an upload, from Gmail or from WhatsApp, and is "new" until the
 * operator has seen where it was filed.
 */
class FinanceDocument extends Model
{
    protected $guarded = ['id'];

    public const string OUT = 'out';

    public const string IN = 'in';

    public const string RECEIPT = 'receipt';

    public const array DIRECTIONS = [self::OUT => 'Kestiğim fatura', self::IN => 'Gider', self::RECEIPT => 'Dekont'];

    public const array SOURCES = ['upload' => 'Yükleme', 'gmail' => 'Gmail', 'whatsapp' => 'WhatsApp', 'manual' => 'Elle'];

    /** new: read, waiting for a look. filed: where it belongs is settled. ignored: not a finance document. */
    public const array STATUSES = ['new' => 'Yeni', 'filed' => 'Yerinde', 'ignored' => 'Yok sayıldı'];

    public const array CATEGORIES = [
        'yazilim' => 'Yazılım ve abonelik', 'sunucu' => 'Sunucu ve alan adı', 'reklam' => 'Reklam', 'yapay_zeka' => 'Yapay zekâ',
        'dis_hizmet' => 'Dış hizmet', 'iletisim' => 'Telefon ve internet', 'ofis' => 'Ofis', 'vergi' => 'Vergi ve muhasebe', 'diger' => 'Diğer',
    ];

    protected function casts(): array
    {
        return ['doc_date' => 'date', 'amount' => 'decimal:2', 'vat' => 'decimal:2', 'rebill' => 'boolean', 'meta' => 'array', 'size' => 'integer'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(AgreementPayment::class, 'payment_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function hasFile(): bool
    {
        return filled($this->path);
    }

    public function directionLabel(): string
    {
        return self::DIRECTIONS[$this->direction] ?? $this->direction;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Diğer';
    }

    /** "1.250,00 ₺", "20,00 $". */
    public function amountLabel(): string
    {
        if ($this->amount === null) {
            return '—';
        }
        $sign = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$this->currency] ?? $this->currency;

        return number_format((float) $this->amount, 2, ',', '.').' '.$sign;
    }

    /** The name the file takes inside a ZIP: date, who, number. */
    public function archiveName(): string
    {
        $ext = strtolower(pathinfo((string) ($this->original_name ?: $this->path), PATHINFO_EXTENSION)) ?: 'pdf';
        $who = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_substr((string) ($this->counterparty ?: $this->agreement?->payer() ?: 'belge'), 0, 50));
        $parts = array_filter([$this->doc_date?->format('Y-m-d'), trim((string) $who, '-'), $this->doc_no ? preg_replace('/[^A-Za-z0-9]+/', '', (string) $this->doc_no) : null]);

        return implode('_', $parts).'-'.$this->id.'.'.$ext;
    }
}
