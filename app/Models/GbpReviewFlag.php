<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A review reported to Google for removal (Yorumlar › "Kaldırılmasını iste"). The report is made in Google's review
 * management tool; MoxDOP keeps the reason and follows the review until Google removes it (it disappears from a full
 * review collection) or the operator closes it.
 */
class GbpReviewFlag extends Model
{
    public const string DRAFT = 'draft';

    public const string REPORTED = 'reported';

    public const string REMOVED = 'removed';

    public const string KEPT = 'kept';

    /** Google's prohibited review content (the reasons its review management tool offers), Turkish labels. */
    public const array REASONS = [
        'spam' => 'Spam / sahte yorum',
        'off_topic' => 'Konu dışı (işletmedeki deneyimle ilgili değil)',
        'conflict' => 'Çıkar çatışması (rakip, eski çalışan)',
        'profanity' => 'Küfür veya müstehcen dil',
        'harassment' => 'Zorbalık veya taciz',
        'hate' => 'Ayrımcılık veya nefret söylemi',
        'personal' => 'Kişisel bilgi içeriyor',
        'illegal' => 'Yasa dışı içerik',
        'impersonation' => 'Kimliğe bürünme',
    ];

    public const array STATUS_LABELS = [
        self::DRAFT => 'Bildirilecek',
        self::REPORTED => 'Google’a bildirildi',
        self::REMOVED => 'Kaldırıldı',
        self::KEPT => 'Google kaldırmadı',
    ];

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
