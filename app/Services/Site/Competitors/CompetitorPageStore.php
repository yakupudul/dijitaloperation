<?php

namespace App\Services\Site\Competitors;

use App\Models\CompetitorPage;
use App\Services\Website\PageFetcher;
use App\Services\Website\Pages\MainContentExtractor;
use Throwable;

/**
 * Competitor page contents: fetched with the safe public fetcher, main content only (header / footer / nav removed),
 * text trimmed; an unreachable page is stored as "eksik". A page younger than `refetch_days` is not fetched again.
 */
final class CompetitorPageStore
{
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly MainContentExtractor $extractor,
    ) {}

    public static function hash(string $url): string
    {
        return hash('sha256', trim($url));
    }

    public function ensure(string $url, string $domain, ?string $class): CompetitorPage
    {
        $existing = CompetitorPage::query()->where('url_hash', self::hash($url))->first();
        $fresh = $existing?->fetched_at !== null && $existing->fetched_at->greaterThan(now()->subDays((int) config('moxdop-site.competitors.refetch_days', 30)));
        if ($existing !== null && $fresh) {
            if ($existing->class !== $class) {
                $existing->forceFill(['class' => $class])->save();
            }

            return $existing;
        }
        $values = ['url' => $url, 'domain' => $domain, 'class' => $class, 'fetched_at' => now()];
        try {
            $response = $this->fetcher->fetch($url);
            $html = $response['html'] ?? null;
            if (! is_string($html) || trim($html) === '') {
                $values += ['status' => CompetitorPage::MISSING, 'error' => mb_substr((string) ($response['error'] ?? 'boş yanıt'), 0, 240), 'title' => null, 'headings' => null, 'content_text' => null];
            } else {
                $content = $this->extractor->fromDocument($html, $url);
                $text = trim((string) $content['content_text']);
                $values += [
                    'status' => $text === '' ? CompetitorPage::MISSING : CompetitorPage::OK,
                    'error' => $text === '' ? 'ana içerik yok' : null,
                    'title' => $content['title'] !== null ? mb_substr((string) $content['title'], 0, 500) : null,
                    'headings' => array_slice(array_values(array_filter($content['headings'], fn (array $h): bool => $h['level'] <= 3)), 0, 40),
                    'content_text' => mb_substr($text, 0, (int) config('moxdop-site.competitors.content_chars', 6000)),
                ];
            }
        } catch (Throwable $error) {
            $values += ['status' => CompetitorPage::MISSING, 'error' => mb_substr($error->getMessage(), 0, 240), 'title' => null, 'headings' => null, 'content_text' => null];
        }

        return CompetitorPage::query()->updateOrCreate(['url_hash' => self::hash($url)], $values);
    }
}
