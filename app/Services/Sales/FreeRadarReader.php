<?php

namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use RuntimeException;

final class FreeRadarReader
{
    public function __construct(
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
    ) {}

    public function read(string $url): string
    {
        $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
        $robots = Cache::remember('free-radar:robots:'.hash('sha256', $origin), 3600, function () use ($origin): array {
            $r = $this->fetcher->fetch($origin.'/robots.txt', 65536);
            return ['status' => $r['status_code'] ?? 0, 'body' => $r['body'] ?? ''];
        });
        if (! in_array($robots['status'], [200, 404, 410], true)) {
            throw new RuntimeException('robots_unavailable');
        }
        if ($robots['status'] === 200 && ! $this->robotsAllow($robots['body'], $url)) {
            throw new RuntimeException('robots_disallowed');
        }
        $r = $this->fetcher->fetch($url, 1500000);
        if (! ($r['ok'] ?? false)) {
            throw new RuntimeException('source_http_'.($r['status_code'] ?? 'unavailable'));
        }
        if (parse_url($r['final_url'] ?? '', PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST)) {
            throw new RuntimeException('source_redirected');
        }
        if (! $this->robotsAllow($robots['body'], $r['final_url'] ?? $url)) {
            throw new RuntimeException('robots_disallowed');
        }
        $body = (string) ($r['body'] ?? '');
        if (preg_match('/<title[^>]*>[^<]*(just a moment|access denied|attention required)/iu', $body)) {
            throw new RuntimeException('source_access_blocked');
        }
        return $body;
    }

    /** @return list<array{url: string, title: string, published_at: ?CarbonImmutable}> */
    public function listing(string $body, string $base, string $format): array
    {
        $dom = $this->document($body, $format === 'rss');
        $xp = new DOMXPath($dom);
        $rows = [];
        $topicLinks = 0;
        $nodes = $format === 'rss'
            ? $xp->query('//*[local-name()="item" or local-name()="entry"]')
            : $xp->query('//a[@href]');
        foreach ($nodes ?: [] as $node) {
            if ($format === 'rss') {
                $title = $xp->evaluate('string(./*[local-name()="title"][1])', $node);
                $link = $xp->evaluate('string(./*[local-name()="link"][not(@rel) or @rel="alternate"][1]/@href)', $node)
                    ?: $xp->evaluate('string(./*[local-name()="link"][1])', $node);
                $date = $xp->evaluate('string(./*[local-name()="pubDate" or local-name()="published"][1])', $node);
            } else {
                $title = $node->textContent;
                $link = $node instanceof DOMElement ? $node->getAttribute('href') : '';
                $date = '';
            }
            $title = $this->text($title, 255);
            $url = $this->urls->resolve($base, $link);
            if (! $url || strlen($url) > 255 || mb_strlen($title) < 10
                || parse_url($url, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) {
                continue;
            }
            if ($format === 'html' && parse_url($base, PHP_URL_HOST) === 'wmaraci.com'
                && ! preg_match('~/forum/[^/]+/[^/?]+-\d+\.html$~', $url)) {
                continue;
            }
            if ($format === 'html' && parse_url($base, PHP_URL_HOST) === 'www.r10.net'
                && ! preg_match('~^https://www\.r10\.net/[^/]+/\d+-[^/?]+\.html$~', $url)) {
                continue;
            }
            $topicLinks++;
            if ($format === 'html' && ! FreeRadarMatcher::hasDemand($title)) {
                continue;
            }
            $rows[$url] = ['url' => $url, 'title' => $title, 'published_at' => $this->date($date)];
            if (count($rows) >= 100) {
                break;
            }
        }
        if ($format === 'rss' && ($nodes === false || $nodes->length === 0)) {
            throw new RuntimeException('feed_items_missing');
        }
        if ($format === 'html' && ($nodes === false || $nodes->length < 5 || (parse_url($base, PHP_URL_HOST) === 'wmaraci.com' && $topicLinks === 0))) {
            throw new RuntimeException('listing_unreadable');
        }
        return array_values($rows);
    }

    /** @return array{excerpt: ?string, published_at: ?CarbonImmutable, author: ?string} */
    public function detail(string $body): array
    {
        $xp = new DOMXPath($this->document($body));
        $excerpt = null;
        $date = '';
        $author = '';
        foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode($script->textContent, true);
            $objects = is_array($data) ? (isset($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data])) : [];
            foreach ($objects as $object) {
                if (! is_array($object) || ! in_array($object['@type'] ?? '', ['DiscussionForumPosting', 'Article', 'BlogPosting', 'SocialMediaPosting'], true)) {
                    continue;
                }
                $raw = $object['articleBody'] ?? $object['text'] ?? null;
                if (is_string($raw) && mb_strlen($raw) >= 20) {
                    $excerpt = $this->text($raw, 4000);
                    $date = is_string($object['datePublished'] ?? null) ? $object['datePublished'] : '';
                    $author = is_string(data_get($object, 'author.name')) ? data_get($object, 'author.name') : '';
                    break 2;
                }
            }
        }
        if ($excerpt === null) {
            $node = $xp->query('(//*[@itemprop="articleBody"] | //*[starts-with(@id,"post_message_")] | //*[contains(concat(" ", normalize-space(@class), " "), " message-body ")])[1]')?->item(0);
            if ($node) {
                foreach (iterator_to_array($xp->query('.//blockquote | .//script | .//style | .//*[contains(@class,"signature")]', $node)) as $remove) {
                    $remove->parentNode?->removeChild($remove);
                }
                $excerpt = $this->text($node->textContent, 4000);
            }
            $date = $xp->evaluate('string((//*[@itemprop="datePublished"]/@content | //*[@itemprop="datePublished"]/@datetime | //meta[@property="article:published_time"]/@content)[1])');
            $author = $xp->evaluate('string((//*[@itemprop="author"]//*[@itemprop="name"])[1])');
        }
        return [
            'excerpt' => $excerpt !== null && mb_strlen($excerpt) >= 20 ? $excerpt : null,
            'published_at' => $this->date($date),
            'author' => $author !== '' ? $this->text($author, 120) : null,
        ];
    }

    private function document(string $body, bool $xml = false): DOMDocument
    {
        if (stripos($body, '<!ENTITY') !== false || ($xml && stripos($body, '<!DOCTYPE') !== false)) {
            throw new RuntimeException('unsupported_document');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            $ok = $xml ? $dom->loadXML($body, LIBXML_NONET) : $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET);
            if (! $ok) {
                throw new RuntimeException('parse_failed');
            }
            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(string $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 0, $limit);
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '' || ! preg_match('/\d{4}/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value, 'UTC');
            return $date->greaterThan(now()->addDay()) ? null : $date;
        } catch (\Throwable) {
            return null;
        }
    }

    private function robotsAllow(string $body, string $url): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $flush = function () use (&$groups, &$agents, &$rules): void {
            if ($agents !== []) {
                $groups[] = ['agents' => $agents, 'rules' => $rules];
            }
            $agents = [];
            $rules = [];
        };
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            if (strtolower($key) === 'user-agent') {
                if ($rules !== []) {
                    $flush();
                }
                $agents[] = strtolower($value);
            } elseif (in_array(strtolower($key), ['allow', 'disallow'], true) && $agents !== []) {
                $rules[] = [strtolower($key), $value];
            }
        }
        $flush();
        $ua = strtolower(\MoxDop\Website\Discovery\DiscoveryConfig::USER_AGENT);
        $specific = array_filter($groups, fn ($g) => count(array_filter($g['agents'], fn ($a) => $a !== '*' && $a !== '' && str_contains($ua, $a))) > 0);
        $selected = $specific ?: array_filter($groups, fn ($g) => in_array('*', $g['agents'], true));
        $path = (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '');
        $best = -1;
        $allowed = true;
        foreach ($selected as $group) {
            foreach ($group['rules'] as [$type, $pattern]) {
                if ($pattern === '') {
                    continue;
                }
                $end = str_ends_with($pattern, '$');
                $pattern = $end ? substr($pattern, 0, -1) : $pattern;
                $regex = '~^'.str_replace('\*', '.*', preg_quote($pattern, '~')).($end ? '$' : '').'~';
                $length = strlen(str_replace('*', '', $pattern));
                if (preg_match($regex, $path) && ($length > $best || ($length === $best && $type === 'allow'))) {
                    $best = $length;
                    $allowed = $type === 'allow';
                }
            }
        }
        return $allowed;
    }
}
