<?php

namespace MoxDop\Website\Standards;

use Illuminate\Support\Str;

/**
 * Faz 6: doorway / near-duplicate page groups. Pages whose slug, after removing location words (provinces,
 * the brand's districts) and generic modifiers ("merkezi", "klinigi", "yapan-yerler", "dis"), leaves the same
 * service head ("implant") are one group; e.g. /ankara-implant-merkezi/, /ankara-implant-klinigi/,
 * /ankara-dis-implanti/. Distinct services keep distinct heads and are never grouped. Separately, pages whose
 * titles (or H1s) are near-identical once location words and the brand suffix are removed form title groups.
 * Pure; no I/O.
 */
final class DoorwayGroupDetector
{
    /**
     * @param  list<string>  $locations  folded location words
     * @param  list<string>  $modifiers  folded generic modifier words
     */
    public function __construct(
        private readonly array $locations,
        private readonly array $modifiers,
        private readonly int $minGroup = 3,
        private readonly float $titleSimilarity = 0.88,
    ) {}

    /**
     * @param  array<string, array{path: string, title: ?string, h1: ?string, clicks: ?int, impressions: ?int, is_service: bool, inlinks: ?int}>  $pages  eligible pages only
     * @return list<array{key: string, kind: string, head: string, members: list<string>, keeper: string}>
     */
    public function groups(array $pages): array
    {
        $bySlug = [];
        foreach ($pages as $key => $page) {
            $segments = array_values(array_filter(explode('/', trim($page['path'], '/')), fn (string $s): bool => $s !== ''));
            if ($segments === []) {
                continue;
            }
            $words = $this->words(urldecode((string) end($segments)));
            $head = $this->head($words);
            // A group needs at least one location or modifier word: "/implant/" alone is the plain service page.
            if ($head === '' || count($words) < 2 && ! $this->varied($words)) {
                $bySlug[$head.'|plain'][] = $key;

                continue;
            }
            $bySlug[$head][] = $key;
        }
        $groups = [];
        $grouped = [];
        foreach ($bySlug as $head => $members) {
            if (str_ends_with((string) $head, '|plain')) {
                continue;
            }
            // The plain service page ("/implant/") belongs to its variants' group as the natural keeper.
            $members = array_values(array_unique([...$members, ...($bySlug[$head.'|plain'] ?? [])]));
            if (count($members) < $this->minGroup) {
                continue;
            }
            sort($members);
            $groups[] = ['key' => 'slug:'.hash('xxh3', $head), 'kind' => 'slug', 'head' => (string) $head, 'members' => $members, 'keeper' => $this->keeper($members, $pages)];
            foreach ($members as $member) {
                $grouped[$member] = true;
            }
        }

        // Title / H1 near-duplicates among the pages not already in a slug group.
        $titles = [];
        foreach ($pages as $key => $page) {
            if (isset($grouped[$key])) {
                continue;
            }
            $text = $this->titleCore((string) ($page['title'] ?? '')) ?: $this->titleCore((string) ($page['h1'] ?? ''));
            if (mb_strlen($text) >= 8) {
                $titles[$key] = $text;
            }
        }
        $parent = [];
        $find = function (string $key) use (&$parent, &$find): string {
            return ($parent[$key] ?? $key) === $key ? $key : ($parent[$key] = $find($parent[$key]));
        };
        // Near-identical titles share their first word; comparing within those buckets keeps this linear-ish.
        $buckets = [];
        foreach ($titles as $key => $text) {
            $buckets[mb_substr($text, 0, 4)][] = $key;
        }
        foreach ($buckets as $bucket) {
            $count = min(count($bucket), 400);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($this->similar($titles[$bucket[$i]], $titles[$bucket[$j]])) {
                        $parent[$find($bucket[$j])] = $find($bucket[$i]);
                    }
                }
            }
        }
        $keys = array_keys($titles);
        $clusters = [];
        foreach ($keys as $key) {
            $clusters[$find($key)][] = $key;
        }
        foreach ($clusters as $members) {
            if (count($members) < 2) {
                continue;
            }
            sort($members);
            $groups[] = ['key' => 'title:'.hash('xxh3', implode('|', $members)), 'kind' => 'title', 'head' => $titles[$members[0]],
                'members' => $members, 'keeper' => $this->keeper($members, $pages)];
        }

        return $groups;
    }

    /** @return list<string> folded slug / title words */
    public function words(string $text): array
    {
        $folded = mb_strtolower(Str::ascii(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'tr'));
        $parts = preg_split('/[^a-z0-9]+/', $folded) ?: [];

        return array_values(array_filter($parts, fn (string $w): bool => $w !== '' && ! ctype_digit($w)));
    }

    /** @param  list<string>  $words */
    private function head(array $words): string
    {
        $stems = [];
        foreach ($words as $word) {
            if (in_array($word, $this->locations, true) || in_array($word, $this->modifiers, true) || mb_strlen($word) < 3) {
                continue;
            }
            $stems[] = mb_substr($word, 0, 5);
        }
        $stems = array_values(array_unique($stems));
        sort($stems);

        return implode(' ', $stems);
    }

    /** @param  list<string>  $words */
    private function varied(array $words): bool
    {
        foreach ($words as $word) {
            if (in_array($word, $this->locations, true) || in_array($word, $this->modifiers, true)) {
                return true;
            }
        }

        return false;
    }

    private function titleCore(string $title): string
    {
        // Drop the brand suffix ("… | Klinik Adı", "… - Klinik Adı").
        $title = preg_split('/\s[|\-–—]\s/u', $title)[0] ?? $title;
        $words = array_filter($this->words($title), fn (string $w): bool => ! in_array($w, $this->locations, true));

        return implode(' ', $words);
    }

    private function similar(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        similar_text($a, $b, $percent);

        return $percent / 100 >= $this->titleSimilarity;
    }

    /**
     * Keeper: most Google clicks, then impressions, then a service page, then most internal links, then shortest path.
     *
     * @param  list<string>  $members
     * @param  array<string, array<string, mixed>>  $pages
     */
    private function keeper(array $members, array $pages): string
    {
        usort($members, function (string $a, string $b) use ($pages): int {
            $pa = $pages[$a];
            $pb = $pages[$b];

            return [(int) ($pb['clicks'] ?? 0), (int) ($pb['impressions'] ?? 0), (int) $pb['is_service'], (int) ($pb['inlinks'] ?? 0), -mb_strlen($pb['path'])]
                <=> [(int) ($pa['clicks'] ?? 0), (int) ($pa['impressions'] ?? 0), (int) $pa['is_service'], (int) ($pa['inlinks'] ?? 0), -mb_strlen($pa['path'])];
        });

        return $members[0];
    }
}
