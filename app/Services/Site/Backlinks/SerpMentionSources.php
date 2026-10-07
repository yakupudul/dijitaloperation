<?php

namespace App\Services\Site\Backlinks;

use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\BrandClusterSerp;
use App\Services\Site\SiteDomains;

/**
 * Anılma fırsatları (yakup, 2026-10-07, AI görünürlüğü 5. madde): directories and news sites that Google already shows
 * in the top 10 for the brand's own cluster searches (stored Rakipler SERPs, classes "dizin" / "haber") are the places
 * search engines and AI assistants read about this kind of business. Each one the brand is not on yet becomes a link
 * source (origin "serp", status "yok") with the searches it shows up for; the operator applies, MoxDOP writes nowhere.
 * Rules only, no AI. Social networks, video and encyclopedias are left out (nothing to apply to).
 */
final class SerpMentionSources
{
    public const string ORIGIN = 'serp';

    /** A domain must show up for at least this many of the brand's searches. */
    public const int MIN_SEARCHES = 1;

    private const int MAX_SOURCES = 20;

    private const array SKIP = ['google.com', 'google.com.tr', 'youtube.com', 'facebook.com', 'instagram.com', 'tiktok.com', 'x.com', 'twitter.com', 'linkedin.com', 'pinterest.com', 'wikipedia.org', 'wikiwand.com'];

    /** @return array{added: int, updated: int} */
    public function sync(Brand $brand): array
    {
        $own = SiteDomains::ownDomains($brand);
        $seen = [];
        BrandClusterSerp::query()->where('brand_id', $brand->id)->where('status', 'ready')->where('fetched_at', '>=', now()->subDays(60))
            ->get(['query', 'results'])
            ->each(function (BrandClusterSerp $serp) use (&$seen, $own): void {
                foreach ((array) $serp->results as $result) {
                    $domain = (string) ($result['domain'] ?? '');
                    $class = (string) ($result['class'] ?? '');
                    if ($domain === '' || ! in_array($class, ['dizin', 'haber'], true) || SiteDomains::isOwn($domain, $own) || SiteDomains::inList($domain, self::SKIP)) {
                        continue;
                    }
                    $row = $seen[$domain] ?? ['class' => $class, 'queries' => [], 'best' => 99, 'url' => '', 'title' => ''];
                    $row['queries'][(string) $serp->query] = true;
                    if ((int) $result['rank'] < $row['best']) {
                        [$row['best'], $row['url'], $row['title']] = [(int) $result['rank'], (string) $result['url'], (string) ($result['title'] ?? '')];
                    }
                    $seen[$domain] = $row;
                }
            });
        uasort($seen, fn (array $a, array $b): int => [count($b['queries']), $a['best']] <=> [count($a['queries']), $b['best']]);
        $linking = array_flip(Backlink::query()->where('brand_id', $brand->id)->distinct()->pluck('source_domain')->all());
        $existing = BacklinkSource::query()->where('brand_id', $brand->id)->get()->keyBy('domain');
        $out = ['added' => 0, 'updated' => 0];
        foreach (array_slice($seen, 0, self::MAX_SOURCES, true) as $domain => $row) {
            if (count($row['queries']) < self::MIN_SEARCHES || isset($linking[$domain])) {
                continue;
            }
            $reason = mb_substr(self::reason($row), 0, 240);
            $source = $existing->get($domain);
            if ($source !== null) {
                if ($source->origin === self::ORIGIN && $source->status === BacklinkSource::NONE && $source->reason !== $reason) {
                    $source->forceFill(['reason' => $reason])->save();
                    $out['updated']++;
                }

                continue;
            }
            BacklinkSource::query()->create(['brand_id' => $brand->id, 'name' => $domain, 'url' => 'https://'.$domain.'/', 'domain' => $domain,
                'kind' => $row['class'] === 'haber' ? 'yerel_haber' : 'dizin', 'fee' => 'teyit', 'reason' => $reason, 'origin' => self::ORIGIN, 'status' => BacklinkSource::NONE]);
            $out['added']++;
        }

        return $out;
    }

    /** @param  array{queries: array<string, true>, best: int, url: string}  $row */
    private static function reason(array $row): string
    {
        $queries = array_keys($row['queries']);

        return 'Google\'da '.count($queries).' aramanızda ilk 10\'da (en iyi '.$row['best'].'. sıra, «'.$queries[0].'»'.(count($queries) > 1 ? ' ve '.(count($queries) - 1).' arama daha' : '').'). Burada yer almak arama ve yapay zekâ yanıtlarında görünürlük sağlar.';
    }
}
