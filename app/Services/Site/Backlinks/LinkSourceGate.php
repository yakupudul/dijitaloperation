<?php

namespace App\Services\Site\Backlinks;

use App\Ai\Agents\Site\LinkQualityAgent;
use App\Jobs\Site\CheckLinkSourcesJob;
use App\Models\BacklinkSource;
use App\Models\LinkSource;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Sales\SalesSubscription;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteDomains;
use App\Services\Website\PageFetcher;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kalite kapısı (Backlink hizmeti mimarisi, katman 2): a new pool source is offered to no brand before it is checked.
 * Its page is opened and read by rules first: words that sell links ("tanıtım yazısı", "dofollow", "backlink paketi" …)
 * or a page that links to more than MAX_EXTERNAL other sites is refused at once; a site that does not open three
 * times is refused. Claude judges the rest in batches (uygun / red, the real kind, city and way to apply), in the
 * subscription's next round when it is connected. Sources the operator added by hand are trusted.
 */
final class LinkSourceGate
{
    public const int BATCH = 20;

    public const int MAX_EXTERNAL = 150;

    public const int MAX_FAILS = 3;

    /** Folded phrases that mean the site sells links or posts. */
    public const array SELLS_LINKS = ['tanitim yazisi', 'misafir yazi', 'guest post', 'dofollow', 'do follow', 'backlink paket', 'backlink sat', 'link satin al',
        'makale yayinlat', 'reklamli yazi', 'sponsorlu makale', 'pbn', 'casino', 'bahis', 'iddaa', 'escort'];

    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly SalesSubscription $subscription,
        private readonly AiTaskQueue $queue,
    ) {}

    /** @return Collection<int, LinkSource> sources waiting for the gate, oldest first */
    public static function waiting(int $limit = self::BATCH): Collection
    {
        return LinkSource::query()->where('quality', 'bekliyor')
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subDays(2)))
            ->orderBy('id')->limit($limit)->get();
    }

    /**
     * Checks one batch. With the job that asked the subscription, its answer is applied instead.
     *
     * @param  list<int>  $ids
     * @return array{refused: int, judged: int, waiting: bool}
     */
    public function check(array $ids, ?CheckLinkSourcesJob $job = null): array
    {
        $out = ['refused' => 0, 'judged' => 0, 'waiting' => false];
        if ($job !== null && ($answer = $this->subscription->answered(new CheckLinkSourcesJob($ids), LinkQualityAgent::ROUTE)) !== null) {
            $out['judged'] = $this->apply((array) ($answer['sources'] ?? []));

            return $out;
        }
        $sources = LinkSource::query()->whereIn('id', $ids)->where('quality', 'bekliyor')->orderBy('id')->get();
        $ask = [];
        foreach ($sources as $source) {
            $signals = $this->signals($source);
            if ($signals === null) {
                $fails = self::fails($source) + 1;
                $source->forceFill(['checked_at' => now(), 'quality' => $fails >= self::MAX_FAILS ? 'red' : 'bekliyor',
                    'quality_note' => $fails >= self::MAX_FAILS ? 'Site '.self::MAX_FAILS.' kez açılmadı' : 'Açılmadı ('.$fails.')'])->save();
                $out['refused'] += $fails >= self::MAX_FAILS ? $this->refuse($source) : 0;

                continue;
            }
            $source->forceFill(['checked_at' => now(), 'linked_domains' => $signals['linked']])->save();
            unset($signals['linked']); // kept for the rival gap, not sent to Claude
            if ($signals['sells'] !== [] || $signals['external_sites'] > self::MAX_EXTERNAL) {
                $source->forceFill(['quality' => 'red', 'quality_note' => $signals['sells'] !== []
                    ? 'Sayfada link satışı: '.implode(', ', array_slice($signals['sells'], 0, 3))
                    : 'Sayfa '.$signals['external_sites'].' farklı siteye link veriyor'])->save();
                $out['refused'] += $this->refuse($source);

                continue;
            }
            $ask[] = ['id' => (int) $source->id, 'name' => $source->name, 'url' => $source->apply_url ?: $source->url, 'domain' => $source->domain,
                'kind' => $source->kind, 'sector' => $source->sector?->name, 'city' => $source->city] + $signals;
        }
        if ($ask === []) {
            return $out;
        }
        $payload = ['sources' => $ask];
        if ($this->subscription->canWait()) {
            $this->queue->begin(new CheckLinkSourcesJob(array_column($ask, 'id')), null, 'Kaynak kalite kapısı: '.count($ask).' kaynak');
            try {
                $this->queue->answer(new LinkQualityAgent, $payload, 'quality', 'SOURCES_JSON');
            } finally {
                $this->queue->settle();
            }
            $out['waiting'] = true;

            return $out;
        }
        try {
            $route = $this->routes->resolve(LinkQualityAgent::ROUTE);
            if ($route->isEmpty()) {
                return $out;
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $answer = (new LinkQualityAgent)->prompt("SOURCES_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 170)->toArray();
            $out['judged'] = $this->apply((array) ($answer['sources'] ?? []));
        } catch (Throwable $error) {
            Log::warning('Link quality gate failed.', ['error' => $error->getMessage()]);
        }

        return $out;
    }

    /**
     * Claude's verdicts; ids outside the batch or no longer waiting are ignored.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function apply(array $rows): int
    {
        $done = 0;
        foreach ($rows as $row) {
            $source = LinkSource::query()->whereKey((int) ($row['id'] ?? 0))->where('quality', 'bekliyor')->first();
            $verdict = $row['verdict'] ?? null;
            if ($source === null || ! in_array($verdict, ['uygun', 'red'], true)) {
                continue;
            }
            $source->forceFill(array_filter([
                'quality' => $verdict,
                'quality_note' => mb_substr('Claude: '.trim((string) ($row['note'] ?? '')), 0, 300),
                'kind' => isset(LinkSource::KINDS[$row['kind'] ?? '']) ? $row['kind'] : null,
                'city' => $source->city ?? (trim((string) ($row['city'] ?? '')) !== '' ? LinkDesk::city((string) $row['city']) : null),
                'apply_via' => isset(LinkSource::APPLY_VIA[$row['apply_via'] ?? '']) && $row['apply_via'] !== 'bilinmiyor' ? $row['apply_via'] : null,
                'checked_at' => now(),
            ], fn ($v): bool => $v !== null))->save();
            if ($verdict === 'red') {
                $this->refuse($source);
            }
            $done++;
        }

        return $done;
    }

    /**
     * What the page shows, by rules: title, description, the start of its text, how many other sites it links to and
     * the link-selling phrases on it; null when it does not open.
     *
     * @return array{title: string, description: string, text: string, external_sites: int, sells: list<string>, linked: list<string>}|null
     */
    public function signals(LinkSource $source): ?array
    {
        try {
            $html = $this->fetcher->fetch($source->apply_url ?: $source->url)['html'] ?? null;
        } catch (Throwable) {
            $html = null;
        }
        if (! is_string($html) || trim($html) === '') {
            return null;
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // A space after every tag, so the words of two elements never run together in the text.
        $document->loadHTML('<?xml encoding="UTF-8">'.str_replace('>', '> ', $html), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        foreach (['//script', '//style', '//noscript'] as $drop) {
            foreach (iterator_to_array($xpath->query($drop) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $own = SiteDomains::registrable($source->domain);
        $external = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if (preg_match('#^(https?:)?//#i', $href) !== 1) {
                continue;
            }
            $host = SiteDomains::host(str_starts_with($href, '//') ? 'https:'.$href : $href);
            if ($host !== null && SiteDomains::registrable($host) !== $own) {
                $external[SiteDomains::registrable($host)] = true;
            }
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $document->textContent));
        $folded = SeoText::fold($text);
        $description = '';
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (mb_strtolower((string) $meta->getAttribute('name')) === 'description') {
                $description = trim((string) $meta->getAttribute('content'));
            }
        }

        return [
            'title' => mb_substr(trim((string) ($document->getElementsByTagName('title')->item(0)?->textContent ?? '')), 0, 200),
            'description' => mb_substr($description, 0, 300),
            'text' => mb_substr($text, 0, 1200),
            'external_sites' => count($external),
            'linked' => array_slice(array_keys($external), 0, 300),
            'sells' => array_values(array_filter(self::SELLS_LINKS, fn (string $p): bool => preg_match('/(^|\W)'.preg_quote($p, '/').'/u', $folded) === 1)),
        ];
    }

    /** A refused source leaves every desk where nothing was sent yet. */
    private function refuse(LinkSource $source): int
    {
        BacklinkSource::query()->where('link_source_id', $source->id)->where('status', BacklinkSource::NONE)
            ->update(['status' => BacklinkSource::DROPPED, 'note' => mb_substr('Kalite kapısı: '.$source->quality_note, 0, 240)]);

        return 1;
    }

    private static function fails(LinkSource $source): int
    {
        return preg_match('/^Açılmadı \((\d+)\)$/u', (string) $source->quality_note, $m) === 1 ? (int) $m[1] : 0;
    }
}
