<?php

namespace App\Services\Site;

use App\Models\Page;
use App\Services\SeoTasks\SeoText;

/**
 * Durum kuralları of the İçerik fikirleri tab (blueprint §5.4), in order: Teknik sorun (the matched page returned
 * 4xx/5xx, is noindex or canonicalised elsewhere) → Sayfa yok → Geliştirilmeli (coverage partial, or full with a
 * score below IMPROVE_BELOW that is not "veri az") → Karşılıyor. A row the AI has not read yet is "İncelenmedi".
 * Every state gets a reason line; wrong page / conflict from Search Console are added to it.
 */
final class ContentIdeaState
{
    public const int IMPROVE_BELOW = 50;

    public const array LABELS = ['technical' => 'Teknik sorun', 'no_page' => 'Sayfa yok', 'improve' => 'Geliştirilmeli', 'sufficient' => 'Karşılıyor', 'unchecked' => 'İncelenmedi', 'excluded' => 'Hariç'];

    /** Sort order of the list. */
    public const array ORDER = ['technical' => 0, 'no_page' => 1, 'improve' => 2, 'sufficient' => 3, 'unchecked' => 4, 'excluded' => 5];

    /**
     * @param  list<array{text: string, kind?: string}>|null  $gaps
     * @param  object|null  $score  cluster_page_scores row
     * @param  list<array{url: string, url_key: string, share: float}>  $shares
     * @param  array<string, mixed>|null  $technical  PageTechnical of the page, when already read
     * @return array{state: string, reason: string, technical: ?array<string, mixed>}
     */
    public static function resolve(?Page $page, ?string $coverage, ?array $gaps, ?object $score, array $shares = [], string $fallbackReason = '', ?array $technical = null): array
    {
        $parts = [];
        $lead = $shares[0] ?? null;
        if ($lead !== null && $lead['share'] >= ClusterPageShares::LEAD && ($page === null || $lead['url_key'] !== SeoText::urlKey((string) $page->url))) {
            $parts[] = 'Google bu kümede '.self::path($lead['url']).' sayfasını gösteriyor';
        }
        $competing = array_values(array_filter($shares, fn (array $s): bool => $s['share'] >= ClusterPageShares::CONFLICT));
        if (count($competing) >= 2) {
            $parts[] = self::path($competing[0]['url']).' ve '.self::path($competing[1]['url']).' aynı kümede yarışıyor';
        }
        if ($page === null) {
            return ['state' => 'no_page', 'reason' => implode(' · ', [$fallbackReason !== '' ? $fallbackReason : 'Bu ihtiyacı karşılayan sayfa yok', ...$parts]), 'technical' => null];
        }
        $technical ??= PageTechnical::of($page);
        if ($technical['issues'] !== []) {
            return ['state' => 'technical', 'reason' => implode(' · ', [...$technical['issues'], ...$parts]), 'technical' => $technical];
        }
        $gaps = array_values((array) $gaps);
        if ($gaps !== []) {
            array_unshift($parts, count($gaps).' eksik · '.$gaps[0]['text']);
        }
        if ($score !== null && $score->position !== null) {
            $parts[] = 'ort. sıra '.number_format((float) $score->position, 1, ',', '.');
        }
        $scored = $score !== null && $score->state === 'scored' && $score->score !== null;
        if ($scored) {
            $parts[] = 'puan '.(int) $score->score;
        } elseif ($score !== null && $score->state === 'low_data') {
            $parts[] = 'veri az';
        }
        if ($coverage === null) {
            return ['state' => 'unchecked', 'reason' => implode(' · ', ['İçerik henüz okunmadı — "Eşleştir"', ...$parts]), 'technical' => $technical];
        }
        $improve = $coverage === 'partial' || $coverage === 'none' || ($scored && (int) $score->score < self::IMPROVE_BELOW);
        if ($parts === [] && $fallbackReason !== '') {
            $parts[] = $fallbackReason;
        }

        return ['state' => $improve ? 'improve' : 'sufficient', 'reason' => implode(' · ', $parts), 'technical' => $technical];
    }

    private static function path(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    }
}
