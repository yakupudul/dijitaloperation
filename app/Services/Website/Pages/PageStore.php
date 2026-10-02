<?php

namespace App\Services\Website\Pages;

use App\Models\Page;
use App\Services\Site\BrandMemoryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Writes the `pages` table (one row per URL, latest version only). A row is rewritten only when its content hash
 * changes; then `changed_at` moves, `analyzed_at` and `content_summary` are cleared (Faz 4 re-analyses it). The
 * category is never set here (Faz 4 / operator decides). No HTML is stored. A URL change, a deletion and a shared
 * template change flag the suggestions based on the old version "yeniden kontrol gerekli".
 */
final class PageStore
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /**
     * @param  array{url: string, language?: ?string, title?: ?string, meta_description?: ?string, canonical?: ?string,
     *     h1?: ?string, headings?: list<array{level: int, text: string}>, content_text?: ?string, word_count?: int,
     *     is_indexable?: bool, wp_post_id?: ?int, wp_post_type?: ?string, changed_at?: ?string}  $fields
     */
    public function upsert(int $siteId, array $fields): string
    {
        $url = trim((string) $fields['url']);
        $row = $this->row($fields);
        $hash = self::contentHash($row);
        $urlHash = self::urlHash($url);
        $changedAt = $this->time($fields['changed_at'] ?? null);

        return DB::transaction(function () use ($siteId, $fields, $url, $row, $hash, $urlHash, $changedAt): string {
            $wpId = isset($fields['wp_post_id']) ? (int) $fields['wp_post_id'] : null;
            $page = $wpId !== null
                ? Page::query()->where('website_asset_id', $siteId)->where('wp_post_id', $wpId)->lockForUpdate()->first()
                : null;
            $page ??= Page::query()->where('website_asset_id', $siteId)->where('url_hash', $urlHash)->lockForUpdate()->first();

            if ($page === null) {
                Page::query()->create($row + [
                    'website_asset_id' => $siteId, 'url' => $url, 'url_hash' => $urlHash, 'path' => self::path($url),
                    'category' => null, 'content_hash' => $hash, 'content_summary' => null,
                    'wp_post_id' => $wpId, 'wp_post_type' => $fields['wp_post_type'] ?? null,
                    'changed_at' => $changedAt ?? now(), 'analyzed_at' => null,
                ]);

                return self::CREATED;
            }

            $urlChanged = $page->url_hash !== $urlHash;
            if ($urlChanged) {
                // URL changed (slug / parent / permalink): the row keeps its id; another row already at the new URL goes.
                self::deleteRows($siteId, Page::query()->where('website_asset_id', $siteId)->where('url_hash', $urlHash)->whereKeyNot($page->id));
                BrandMemoryService::recheck($siteId, [(int) $page->id]);
            }
            if (! $urlChanged && $page->content_hash === $hash) {
                if ($wpId !== null && ($page->wp_post_id !== $wpId || $page->wp_post_type !== ($fields['wp_post_type'] ?? null))) {
                    $page->forceFill(['wp_post_id' => $wpId, 'wp_post_type' => $fields['wp_post_type'] ?? null])->save();
                }

                return self::UNCHANGED;
            }

            $update = ['url' => $url, 'url_hash' => $urlHash, 'path' => self::path($url)];
            if ($wpId !== null) {
                $update += ['wp_post_id' => $wpId, 'wp_post_type' => $fields['wp_post_type'] ?? null];
            }
            if ($page->content_hash !== $hash) {
                $update += $row + ['content_hash' => $hash, 'content_summary' => null, 'analyzed_at' => null,
                    'changed_at' => $changedAt !== null && $changedAt->greaterThan($page->changed_at ?? CarbonImmutable::createFromTimestamp(0)) ? $changedAt : now()];
            } else {
                $update['changed_at'] = now();
            }
            $page->forceFill($update)->save();

            return self::UPDATED;
        });
    }

    /** Removes the page of a WordPress object (deleted, trashed, unpublished). */
    public function deleteWordPressObject(int $siteId, int $wpPostId): int
    {
        return self::deleteRows($siteId, Page::query()->where('website_asset_id', $siteId)->where('wp_post_id', $wpPostId));
    }

    /**
     * Deletes page rows; their open / approved-not-applied suggestions are flagged for a re-check first (the page id is
     * nulled by the deletion).
     *
     * @param  Builder<Page>  $rows
     */
    public static function deleteRows(int $siteId, Builder $rows): int
    {
        $ids = (clone $rows)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }
        BrandMemoryService::recheck($siteId, $ids);

        return Page::query()->whereIn('id', $ids)->delete();
    }

    /** A template / theme change: every page of the site is marked changed without refetching anything. */
    public function touchAll(int $siteId): int
    {
        BrandMemoryService::recheck($siteId, null);

        return Page::query()->where('website_asset_id', $siteId)->update(['changed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The fields that make up the stored version.
     *
     * @param  array<string, mixed>  $fields
     * @return array{language: ?string, title: ?string, meta_description: ?string, canonical: ?string, h1: ?string,
     *     headings: list<array{level: int, text: string}>, content_text: string, word_count: int, is_indexable: bool}
     */
    private function row(array $fields): array
    {
        $headings = array_values(array_filter(
            (array) ($fields['headings'] ?? []),
            static fn ($heading): bool => is_array($heading) && in_array((int) ($heading['level'] ?? 0), [1, 2, 3], true) && trim((string) ($heading['text'] ?? '')) !== '',
        ));

        return [
            'language' => self::short($fields['language'] ?? null, 8),
            'title' => self::short($fields['title'] ?? null, 500),
            'meta_description' => self::short($fields['meta_description'] ?? null, 1000),
            'canonical' => self::short($fields['canonical'] ?? null, 2048),
            'h1' => self::short($fields['h1'] ?? null, 500),
            'headings' => array_map(static fn (array $h): array => ['level' => (int) $h['level'], 'text' => mb_substr(trim((string) $h['text']), 0, 500)], $headings),
            'content_text' => (string) ($fields['content_text'] ?? ''),
            'word_count' => (int) ($fields['word_count'] ?? 0),
            'is_indexable' => (bool) ($fields['is_indexable'] ?? true),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function contentHash(array $row): string
    {
        return hash('sha256', json_encode([
            $row['language'] ?? null, $row['title'] ?? null, $row['meta_description'] ?? null, $row['canonical'] ?? null,
            $row['h1'] ?? null, $row['headings'] ?? [], $row['content_text'] ?? '', (bool) ($row['is_indexable'] ?? true),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    public static function urlHash(string $url): string
    {
        return hash('sha256', trim($url));
    }

    public static function path(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return mb_substr($path.(is_string($query) && $query !== '' ? '?'.$query : ''), 0, 2048);
    }

    private static function short(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
