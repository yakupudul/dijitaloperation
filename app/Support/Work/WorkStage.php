<?php

namespace App\Support\Work;

use App\Models\ExternalWriteAction;
use App\Models\Suggestion;

/**
 * Tek iş yapısı (Aşama 4, 2026-10-22): every piece of work of the four channels walks the same five stages —
 * Tespit (a finding), Çözüm (its prepared fix, waiting for a decision), İş (the write on the way or done), Doğrulama
 * (the site or profile shows it), Sonuç (measured 30 / 90 days later). Existing rows are read into it: a suggestion is
 * the finding and the fix, its write the work, its verification and outcome the last two.
 */
final class WorkStage
{
    public const string FOUND = 'tespit';

    public const string SOLUTION = 'cozum';

    public const string WORK = 'is';

    public const string VERIFY = 'dogrulama';

    public const string RESULT = 'sonuc';

    public const array LABELS = [
        self::FOUND => 'Tespit',
        self::SOLUTION => 'Çözüm',
        self::WORK => 'İş',
        self::VERIFY => 'Doğrulama',
        self::RESULT => 'Sonuç',
    ];

    /** One line per stage, for the "Neden?" text of the stage table. */
    public const array HINTS = [
        self::FOUND => 'Sistemin bulduğu, açık duran bulgular.',
        self::SOLUTION => 'Çözümü hazırlanmış, senin kararını bekleyen işler.',
        self::WORK => 'Son 14 günde siteye ya da profile yazılan (ya da yolda olan) işler.',
        self::VERIFY => 'Son 14 günde sitede ya da profilde göründüğü doğrulanan işler.',
        self::RESULT => 'Son 4 ayda 30 / 90 gün sonra sonucu ölçülen işler.',
    ];

    public const array CHANNELS = [
        'web' => 'Web sitesi',
        'gbp' => 'İşletme profili',
        'google_ads' => 'Google Ads',
        'meta' => 'Meta',
    ];

    public static function ofSuggestion(Suggestion $suggestion): string
    {
        return match (true) {
            $suggestion->measured_at !== null => self::RESULT,
            in_array($suggestion->verification, [Suggestion::VERIFY_CONFIRMED, Suggestion::VERIFY_AUTO], true) => self::VERIFY,
            $suggestion->status === Suggestion::APPLIED || $suggestion->status === Suggestion::APPROVED => self::WORK,
            filled(data_get($suggestion->action, 'proposed')) || filled(data_get($suggestion->action, 'value')) || filled(data_get($suggestion->action, 'changes')) => self::SOLUTION,
            default => self::FOUND,
        };
    }

    /** A write is the work; once its row is verified or measured it is that stage. */
    public static function ofWrite(ExternalWriteAction $write, ?Suggestion $suggestion = null): string
    {
        if ($suggestion !== null && in_array($write->status, ['succeeded', 'partial', 'undo_failed'], true)) {
            $stage = self::ofSuggestion($suggestion);
            if (in_array($stage, [self::VERIFY, self::RESULT], true)) {
                return $stage;
            }
        }

        return self::WORK;
    }

    /** web | gbp | google_ads of a write's channel (wordpress = web). */
    public static function channelOfWrite(string $channel): string
    {
        return match ($channel) {
            ExternalWriteAction::CHANNEL_GBP => 'gbp',
            ExternalWriteAction::CHANNEL_GOOGLE_ADS => 'google_ads',
            default => 'web',
        };
    }

    /** Icon family of an Akış row: web | gbp | ads | icerik | ai. */
    public static function kindOfChannel(string $channel): string
    {
        return match ($channel) {
            'gbp' => 'gbp',
            'google_ads', 'meta' => 'ads',
            default => 'web',
        };
    }

    public static function kindOfOperation(string $operation): string
    {
        return match (true) {
            str_contains($operation, 'content') || str_contains($operation, 'article') || str_contains($operation, 'translat') => 'icerik',
            str_starts_with($operation, 'gbp') => 'gbp',
            str_contains($operation, 'ads') || str_contains($operation, 'meta') => 'ads',
            default => 'ai',
        };
    }
}
