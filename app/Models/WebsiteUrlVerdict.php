<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faz 5 URL karnesi: exactly one primary verdict per document URL of a website with its Turkish reason, solution,
 * every finding (kural / bulgu / çözüm / aksiyon) and the joined facts. Precomputed by UrlAuditService.
 */
class WebsiteUrlVerdict extends Model
{
    public const string OK = 'ok';

    public const string FIX = 'fix';

    public const string STRENGTHEN = 'strengthen';

    public const string MERGE = 'merge';

    public const string DEINDEX = 'deindex';

    public const string CHECK = 'check';

    /** verdict => [label, tone] in display order. */
    public const array VERDICTS = [
        self::FIX => ['Düzelt', 'rose'],
        self::MERGE => ['Birleştir / yönlendir', 'violet'],
        self::STRENGTHEN => ['Güçlendir', 'amber'],
        self::DEINDEX => ['Dizinden çıkar', 'gray'],
        self::CHECK => ['Kontrol et', 'blue'],
        self::OK => ['Sorun yok — gerek yok', 'emerald'],
    ];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'findings' => 'array', 'facts' => 'array', 'indexed' => 'boolean', 'position' => 'float',
            'ads_cost' => 'float', 'ads_conversions' => 'float', 'computed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    public function verdictLabel(): string
    {
        return self::VERDICTS[$this->verdict][0] ?? $this->verdict;
    }
}
