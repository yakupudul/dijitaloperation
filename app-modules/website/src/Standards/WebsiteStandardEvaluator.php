<?php

namespace MoxDop\Website\Standards;

use MoxDop\Website\Discovery\PublicUrlNormalizer;

/** Evaluates observations only. It neither collects data nor writes Findings. */
final class WebsiteStandardEvaluator
{
    /**
     * @param  array<string, mixed>  $standard
     * @param  array<string, mixed>  $page
     * @return array{state:string,reason:string,observed:mixed}
     */
    public function evaluate(array $standard, array $page): array
    {
        $method = $standard['method'];
        $site = $standard['applicability'] === 'site';
        if (! $site && ($page['excluded_kind'] ?? false)) {
            return $this->out('not_applicable', 'Sistem, medya veya sayfalama URL’si.');
        }
        if ($method === 'expert_review') {
            return $this->out('unknown', 'Bu kriter, seçilen sayfa ve sorgu kümesi üzerinde uzman incelemesi gerektirir.');
        }
        if (str_starts_with($method, 'wp_') || in_array($method, [
            'title_duplicate', 'description_duplicate', 'content_duplicate', 'title_multiple', 'description_multiple',
            'h1_multiple', 'language_missing', 'internal_broken', 'internal_redirect', 'empty_anchors',
            'canonical_target', 'image_alt', 'image_dimensions', 'mixed_resources', 'hreflang_target',
        ], true)) {
            return (new ExtendedWebsiteEvaluator)->evaluate($standard, $page);
        }
        $facts = $page['facts'] ?? [];
        $html = $page['stored_html'] ?? null;
        $sourceKey = match ($method) {
            'http' => 'http', 'h1' => 'headings', 'jsonld' => 'structured_data',
            'internal_links' => 'html', default => 'document_head',
        };
        $factObservedAt = data_get($facts, $sourceKey.'.observed_at');
        if ($html !== null && is_string($factObservedAt)
            && strtotime($factObservedAt) > strtotime($html['observed_at'] ?? '1970-01-01')) {
            $html = null;
        }
        $observedAt = $method !== 'http' ? ($html['observed_at'] ?? $factObservedAt) : $factObservedAt;
        if (! $site && is_string($observedAt) && isset($page['evaluated_at'])
            && (strtotime($observedAt) === false || strtotime($observedAt) < strtotime($page['evaluated_at'].' -30 days'))) {
            return $this->out('unknown', 'Gözlem 30 günden eski veya tarihi belirsiz. Entegrasyonlardan veriyi güncelleyin.', $observedAt);
        }
        $head = is_array($html) && ($html['head_complete'] ?? false) ? ($html['head'] ?? null) : null;
        $meta = $facts['document_head'] ?? null;
        $target = (bool) ($page['search_target'] ?? false);
        $value = null;
        $failed = null;
        $state = $standard['classification'] === 'verified' ? 'fail' : 'review';

        switch ($method) {
            case 'http':
                $value = data_get($facts, 'http.status_code');
                if (is_numeric($value) && (int) $value >= 200 && (int) $value < 600) {
                    $failed = (int) $value >= 400;
                    if ((int) $value >= 300 && (int) $value < 400) {
                        return $this->out($target ? 'fail' : 'review', 'Yönlendirme gözlendi; amaçlanan hedefi kontrol edin.', $value) + ['observed_at' => $observedAt];
                    }
                }
                break;
            case 'canonical':
                $value = $html !== null && ($html['head_complete'] ?? false) ? $html['canonical_hrefs'] : ($meta['canonical_hrefs'] ?? null);
                if (is_array($value)) {
                    $urls = new PublicUrlNormalizer;
                    $value = array_values(array_unique(array_map(fn ($href) => is_string($href) ? $urls->resolve($page['url'], $href) : null, $value)));
                    $failed = count($value) > 1 || (count($value) === 1 && $value[0] !== $urls->normalizeAbsolute($page['url']));
                    $state = $target ? 'fail' : 'review';
                    if ($value === []) {
                        return $this->out('not_applicable', 'Canonical bildirilmemiş; tek başına teknik hata değildir.');
                    }
                }
                break;
            case 'noindex':
                $value = $head !== null
                    ? implode(',', [...$head['robots_directives'], ...$head['googlebot_directives']])
                    : ($meta['robots'] ?? null);
                if (is_string($value)) {
                    $failed = preg_match('/(?:^|[\s,;:])(?:noindex|none)(?:$|[\s,;])/i', $value) === 1;
                    $state = $target ? 'fail' : 'review';
                }
                break;
            case 'title_missing':
            case 'title_empty':
            case 'title_length':
                $present = $head['title_present'] ?? $meta['title_present'] ?? null;
                $value = $head['title'] ?? $meta['title'] ?? null;
                if (is_bool($present)) {
                    if ($method === 'title_missing') {
                        $failed = ! $present;
                    } elseif (! $present) {
                        return $this->out('not_applicable', 'Title etiketi bulunmaması ayrı değerlendirilir.');
                    } elseif (is_string($value)) {
                        $failed = $method === 'title_empty' ? trim($value) === '' : mb_strlen($value) > 60;
                    }
                }
                break;
            case 'description_missing':
            case 'description_empty':
            case 'description_length':
                $value = $head['meta_description'] ?? $meta['meta_description'] ?? null;
                $present = $head['meta_description_present'] ?? (is_array($meta) ? array_key_exists('meta_description', $meta) && $value !== null : null);
                if (is_bool($present)) {
                    if ($method === 'description_missing') {
                        $failed = ! $present;
                    } elseif (! $present) {
                        return $this->out('not_applicable', 'Eksik meta açıklaması ayrı değerlendirilir.');
                    } elseif (is_string($value)) {
                        $failed = $method === 'description_empty' ? trim($value) === '' : (mb_strlen($value) < 50 || mb_strlen($value) > 160);
                    }
                }
                break;
            case 'charset':
            case 'viewport':
                if ($head !== null) {
                    $value = $head[$method];
                    $failed = ! $head[$method.'_present'];
                }
                break;
            case 'open_graph':
                if ($head !== null) {
                    $value = $head['open_graph'];
                    $failed = empty($value['title']) || empty($value['description']) || empty($value['image']);
                }
                break;
            case 'jsonld':
                $value = $head['json_ld']['malformed_count'] ?? data_get($facts, 'structured_data.malformed_blocks');
                $failed = is_numeric($value) ? $value > 0 : null;
                break;
            case 'h1':
                $value = $html['h1'] ?? data_get($facts, 'headings.h1');
                $known = $html !== null || is_bool(data_get($facts, 'headings.h1_present'));
                $failed = $known ? ! is_string($value) || trim($value) === '' : null;
                break;
            case 'internal_links':
                $value = $html['internal_link_count'] ?? null;
                $failed = is_numeric($value) ? (int) $value === 0 : null;
                break;
            case 'tls':
                $value = data_get($page, 'site_evidence.tls_info.payload.valid_to');
                if (is_string($value) && strtotime($value) !== false) {
                    $failed = strtotime($value) < strtotime($page['evaluated_at']);
                }
                break;
            case 'https_redirect':
                $value = data_get($page, 'site_evidence.redirects.payload.upgraded_to_https_same_host');
                $failed = is_bool($value) ? ! $value : null;
                break;
            case 'robots_file':
            case 'sitemap':
                $key = $method === 'robots_file' ? 'robots' : 'sitemap';
                $value = data_get($page, 'site_evidence.'.$key.'.payload.status_code');
                $failed = is_numeric($value) ? (int) $value !== 200 : null;
                break;
        }
        if ($failed === null) {
            return $this->out('unknown', 'Bu kontrolün gerektirdiği gözlem yok veya alan toplanmamış.', $value);
        }

        return $this->out($failed ? $state : 'pass', $failed ? $standard['action'] : 'Saklı gözlemde bu koşul için eksik saptanmadı.', $value) + ['observed_at' => $observedAt];
    }

    /** @return array{state:string,reason:string,observed:mixed} */
    private function out(string $state, string $reason, mixed $observed = null): array
    {
        return compact('state', 'reason', 'observed');
    }
}
