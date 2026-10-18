<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a brand's epikriz: a fact (layer 1), an evidence line (2), a synthesis line (3), a lesson (4) or an open
 * question to the operator (5), with where it comes from, how sure it is and until when it holds.
 */
class BrandEpicrisisItem extends Model
{
    public const string FACT = 'fact';

    public const string EVIDENCE = 'evidence';

    public const string SYNTHESIS = 'synthesis';

    public const string LESSON = 'lesson';

    public const string QUESTION = 'question';

    public const array LAYERS = [self::FACT, self::EVIDENCE, self::SYNTHESIS, self::LESSON, self::QUESTION];

    /** Confidence: the operator (or two sources) said so · one source · AI's reading. */
    public const string VERIFIED = 'verified';

    public const string DATA = 'data';

    public const string INFERRED = 'inferred';

    public const array CONFIDENCE_LABELS = [self::VERIFIED => 'Doğrulandı', self::DATA => 'Veriden', self::INFERRED => 'Çıkarım'];

    public const string ACTIVE = 'active';

    public const string CONFLICT = 'conflict';

    public const string EXPIRED = 'expired';

    public const string DROPPED = 'dropped';

    public const string OPEN = 'open';

    public const string ANSWERED = 'answered';

    public const string DISMISSED = 'dismissed';

    /** Sources. */
    public const array SOURCES = [
        'operator' => 'Operatör', 'chat' => 'Operatör (sohbetten)', 'moxdop' => 'MoxDOP kayıtları', 'site' => 'Web sitesi', 'gbp' => 'İşletme Profili',
        'ads' => 'Google Ads', 'meta' => 'Meta', 'reviews' => 'Yorumlar', 'search' => 'Search Console', 'ai' => 'AI sentezi', 'rules' => 'Kurallar',
    ];

    /** @var list<string> */
    protected $fillable = ['brand_id', 'layer', 'field', 'scope', 'value', 'detail', 'source_kind', 'source_ref', 'source_text', 'confidence', 'status', 'locked',
        'fingerprint', 'seen_at', 'expires_at', 'used_count', 'last_used_at', 'answered_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['detail' => 'array', 'locked' => 'boolean', 'seen_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'last_used_at' => 'immutable_datetime',
            'used_count' => 'integer'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    public function fieldLabel(): string
    {
        $group = match ($this->layer) {
            self::EVIDENCE => 'evidence',
            self::SYNTHESIS => 'synthesis',
            self::LESSON => 'lessons',
            default => 'fields',
        };

        return (string) (config('moxdop-epicrisis.'.$group.'.'.$this->field.'.label') ?? config('moxdop-epicrisis.fields.'.$this->field.'.label') ?? $this->field);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source_kind] ?? $this->source_kind;
    }

    /** The line as AI work reads it: "Garanti · İmplant: 10 yıl (doğrulandı)". */
    public function line(): string
    {
        return $this->fieldLabel().($this->scope ? ' · '.$this->scope : '').': '.$this->value;
    }
}
