<?php

namespace MoxDop\Website\Discovery;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MoxDop\Website\Diagnosis\DocumentHeadParser;
use Throwable;

/**
 * Deterministic public HTML fact extraction for Discovery Evidence.
 */
final class PublicPageExtractor
{
    public function __construct(
        private readonly DocumentHeadParser $documentHead = new DocumentHeadParser,
        private readonly PublicUrlNormalizer $normalizer = new PublicUrlNormalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function extract(string $finalUrl, string $html): array
    {
        $head = $this->documentHead->parse($html);
        $dom = $this->loadDom($html);
        $xpath = $dom instanceof DOMDocument ? new DOMXPath($dom) : null;

        $h1 = null;
        $navLabels = [];
        $sameSiteLinks = [];
        $phones = [];
        $emails = [];
        $social = [];
        $addresses = [];
        $services = [];
        $areas = [];
        $htmlLang = null;

        if ($xpath instanceof DOMXPath) {
            $htmlLang = $this->firstAttr($xpath, '//html/@lang') ?? $this->firstAttr($xpath, '//html/@xml:lang');
            $h1 = $this->firstText($xpath, '//h1');
            $navLabels = $this->texts($xpath, '//nav//a', 40);
            $sameSiteLinks = $this->collectSameSiteLinks($xpath, $finalUrl, 80);
            $phones = $this->collectHrefValues($xpath, 'tel:', 20);
            $emails = $this->collectHrefValues($xpath, 'mailto:', 20);
            $social = $this->collectSocialLinks($xpath, $finalUrl);
            $addresses = $this->collectAddressLikeText($xpath);
            [$services, $areas] = $this->structuredClaims($xpath);
            $path = (string) parse_url($finalUrl, PHP_URL_PATH);
            if ($h1 !== null && $this->serviceName($h1)
                && preg_match('~/(?:hizmetler?|services?|urunler?|products?|tedaviler|treatments)/[^/]+~iu', $path)
                && $this->firstText($xpath, '//main//p|//article//p|//body//p') !== null) {
                $services[] = ['name' => $h1, 'from' => 'service_page_heading'];
            }
        }

        return [
            'source_url' => $finalUrl,
            'title' => $head['title'] ?? null,
            'h1' => $h1,
            'meta_description' => $head['meta_description'] ?? null,
            'canonical_url' => $xpath ? $this->firstAttr($xpath, '//link[contains(concat(" ", normalize-space(@rel), " "), " canonical ")]/@href') : null,
            'html_lang' => $htmlLang,
            'hreflang' => $head['hreflang'] ?? [],
            'open_graph' => $head['open_graph'] ?? [],
            'json_ld' => $head['json_ld'] ?? [],
            'nav_labels' => $navLabels,
            'same_site_links' => $sameSiteLinks,
            'phones' => $phones,
            'emails' => $emails,
            'social_links' => $social,
            'address_candidates' => $addresses,
            'service_claims' => $services,
            'main_text_excerpt' => $xpath ? mb_substr($this->firstText($xpath, '//main//p|//article//p|//body//p') ?? '', 0, 1000) : '',
            'service_area_claims' => $areas,
            'normalization_version' => DiscoveryConfig::VERSION,
        ];
    }

    private function loadDom(string $html): ?DOMDocument
    {
        try {
            $dom = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $dom;
        } catch (Throwable) {
            return null;
        }
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $nodes->item(0)?->textContent) ?? '');

        return $text === '' ? null : $text;
    }

    private function firstAttr(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->nodeValue);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function texts(DOMXPath $xpath, string $query, int $limit): array
    {
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (count($out) >= $limit) {
                break;
            }
            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return list<array{url: string, label: ?string}>
     */
    private function collectSameSiteLinks(DOMXPath $xpath, string $baseUrl, int $limit): array
    {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }

            $href = trim((string) $node->getAttribute('href'));
            $resolved = $this->normalizer->resolve($baseUrl, $href);
            if ($resolved === null || ! $this->normalizer->sameSite($baseUrl, $resolved)) {
                continue;
            }

            if (isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;

            $label = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            $out[] = [
                'url' => $resolved,
                'label' => $label === '' ? null : $label,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function collectHrefValues(DOMXPath $xpath, string $prefix, int $limit): array
    {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (count($out) >= $limit) {
                break;
            }
            $href = trim((string) $node->getAttribute('href'));
            if (! str_starts_with(strtolower($href), $prefix)) {
                continue;
            }
            $value = trim(substr($href, strlen($prefix)));
            $value = explode('?', $value)[0] ?? $value;
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @return list<array{platform: string, url: string}>
     */
    private function collectSocialLinks(DOMXPath $xpath, string $baseUrl): array
    {
        $out = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $url = $this->normalizer->resolve($baseUrl, $node->getAttribute('href'));
            $profile = $url === null ? null : $this->normalizeSocialProfile($url);
            if ($profile !== null) {
                $out[$profile['url']] = $profile;
            }
            if (count($out) >= 20) {
                break;
            }
        }

        return array_values($out);
    }

    /** Only public profile URLs; posts, sharing, login and content URLs are not identities. */
    public function normalizeSocialProfile(string $url): ?array
    {
        $url = $this->normalizer->normalizeAbsolute($url);
        if ($url === null) {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        $handle = strtolower($parts[0]);
        $platform = match ($host) {
            'instagram.com' => 'instagram', 'facebook.com', 'fb.com' => 'facebook',
            'linkedin.com' => 'linkedin', 'youtube.com' => 'youtube',
            'twitter.com', 'x.com' => 'x', 'tiktok.com' => 'tiktok', default => null,
        };
        if ($platform === null || $path === '' || strlen($path) > 255) {
            return null;
        }
        $valid = match ($platform) {
            'instagram' => count($parts) === 1 && preg_match('/^[a-zA-Z0-9_.]+$/', $path)
                && ! in_array($handle, ['p', 'reel', 'reels', 'explore', 'accounts', 'stories', 'direct', 'about'], true),
            'facebook' => count($parts) === 1 && preg_match('/^[a-zA-Z0-9._-]+$/', $path)
                && ! in_array($handle, ['share', 'sharer', 'sharer.php', 'login', 'login.php', 'watch', 'reel', 'reels', 'groups', 'events', 'dialog', 'home.php'], true),
            'linkedin' => count($parts) === 2 && in_array($handle, ['company', 'in', 'school'], true),
            'youtube' => (count($parts) === 1 && str_starts_with($path, '@'))
                || (count($parts) === 2 && in_array($handle, ['channel', 'user', 'c'], true)),
            'x' => count($parts) === 1 && preg_match('/^[a-zA-Z0-9_]{1,15}$/', $path)
                && ! in_array($handle, ['intent', 'share', 'home', 'search', 'explore', 'settings', 'i'], true),
            'tiktok' => count($parts) === 1 && preg_match('/^@[a-zA-Z0-9_.]+$/', $path),
            default => false,
        };
        if (! $valid) {
            return null;
        }
        $query = '';
        if ($platform === 'facebook' && $handle === 'profile.php') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
            if (! is_string($params['id'] ?? null) || ! ctype_digit($params['id'])) {
                return null;
            }
            $query = '?id='.$params['id'];
        }
        $host = match ($platform) {
            'x' => 'x.com', 'facebook' => 'facebook.com', default => $host
        };

        return ['platform' => $platform, 'url' => 'https://'.$host.'/'.$path.$query];
    }

    /** @return array{0: list<array{name: string, from: string}>, 1: list<string>} */
    private function structuredClaims(DOMXPath $xpath): array
    {
        $services = [];
        $areas = [];
        $budget = 200;
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $index => $script) {
            if ($index >= 12 || strlen($script->textContent) > 100000) {
                continue;
            }
            $json = json_decode($script->textContent, true, 32);
            if (! is_array($json)) {
                continue;
            }
            $queue = array_is_list($json) ? $json : [$json];
            while ($queue !== [] && $budget-- > 0) {
                $node = array_shift($queue);
                if (! is_array($node)) {
                    continue;
                }
                foreach (['@graph', 'mainEntity'] as $child) {
                    if (is_array($node[$child] ?? null)) {
                        $queue = array_merge($queue, array_is_list($node[$child]) ? $node[$child] : [$node[$child]]);
                    }
                }
                $types = array_filter((array) ($node['@type'] ?? []), 'is_string');
                if (array_intersect($types, ['Service', 'Product']) !== [] && is_string($node['name'] ?? null)
                    && $this->serviceName($node['name'])) {
                    $services[] = ['name' => trim($node['name']), 'from' => 'structured_service'];
                }
                if (array_intersect($types, ['Article', 'BlogPosting', 'NewsArticle', 'Review']) !== []) {
                    continue;
                }
                foreach (['areaServed', 'serviceArea'] as $key) {
                    $values = $node[$key] ?? [];
                    $values = is_array($values) && array_is_list($values) ? $values : [$values];
                    foreach ($values as $value) {
                        $name = is_string($value) ? $value : (is_array($value) ? ($value['name'] ?? null) : null);
                        if (is_string($name) && mb_strlen(trim($name)) >= 2 && mb_strlen($name) <= 160) {
                            $areas[] = trim($name);
                        }
                    }
                }
            }
        }

        return [array_slice($services, 0, 40), array_slice(array_values(array_unique($areas)), 0, 40)];
    }

    private function serviceName(string $name): bool
    {
        $name = mb_strtolower(trim($name));

        return mb_strlen($name) >= 3 && mb_strlen($name) <= 160 && ! in_array($name, [
            'home', 'services', 'service', 'products', 'product', 'about', 'contact', 'blog', 'news',
            'ana sayfa', 'anasayfa', 'hizmetler', 'hizmetlerimiz', 'ürünler', 'ürünlerimiz',
            'hakkımızda', 'iletişim', 'kvkk', 'gizlilik', 'kampanyalar', 'tedaviler', 'tedavilerimiz',
        ], true);
    }

    /**
     * @return list<string>
     */
    private function collectAddressLikeText(DOMXPath $xpath): array
    {
        $out = [];
        foreach (['//address', '//*[contains(@class,"address") or contains(@id,"address")]'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
                if (mb_strlen($text) < 12 || mb_strlen($text) > 240) {
                    continue;
                }
                if (! in_array($text, $out, true)) {
                    $out[] = $text;
                }
                if (count($out) >= 8) {
                    return $out;
                }
            }
        }

        return $out;
    }
}
