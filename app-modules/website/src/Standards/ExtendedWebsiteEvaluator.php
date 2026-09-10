<?php

namespace MoxDop\Website\Standards;

use MoxDop\Website\Discovery\PublicUrlNormalizer;

final class ExtendedWebsiteEvaluator
{
    public function evaluate(array $standard, array $page): array
    {
        $method = $standard['method'];
        $out = static fn (string $state, string $reason, mixed $observed = null): array => compact('state', 'reason', 'observed');
        $unknown = $out('unknown', 'Güncel ve yeterli kaynak verisi yok; entegrasyon verisini yenileyin.');
        $at = strtotime($page['evaluated_at'] ?? '') ?: time();
        if (str_starts_with($method, 'wp_')) {
            $wp = $page['site_evidence']['wordpress'] ?? null;
            if ($wp === null) {
                return ($page['site_evidence']['wordpress_expected'] ?? false) ? $unknown : $out('not_applicable', 'WordPress altyapısı bildirilmemiş.');
            }
            if ($method === 'wp_event_delivery') {
                $received = $wp['last_event_received_at'] ?? null;
                return is_string($received) && strtotime($received) !== false && strtotime($received) <= $at + 300
                    ? $this->result($standard, $at - strtotime($received) > 1800, $received) : $unknown;
            }
            if (! $this->fresh($wp['observed_at'] ?? null, $at, 345600)) {
                return $unknown;
            }
            $payload = $wp['payload'];
            $health = $payload['health'] ?? [];
            $value = null;
            $failed = null;
            switch ($method) {
                case 'wp_https':
                    $value = $health['https'] ?? null;
                    $failed = is_bool($value) ? ! $value : null;
                    break;
                case 'wp_permalink':
                    $value = $payload['settings']['permalink_structure'] ?? null;
                    $failed = is_string($value) ? trim($value) === '' : null;
                    break;
                case 'wp_cache_config':
                    $value = $health['page_cache_configured'] ?? null;
                    $failed = is_bool($value) ? ! $value : null;
                    break;
                case 'wp_event_storage':
                    $value = $health['event_delivery']['storage_ready'] ?? null;
                    $failed = is_bool($value) ? ! $value : null;
                    break;
                case 'wp_event_gap':
                    if (! array_key_exists('delivery_gap_at', $wp) || ! $this->fresh($wp['last_event_received_at'] ?? null, $at, 345600)) {
                        return $unknown;
                    }
                    $value = $wp['delivery_gap_at'];
                    if ($value === null) {
                        $failed = false;
                    } elseif (is_string($value) && strtotime($value) !== false) {
                        $inventory = $wp['last_inventory_at'] ?? null;
                        $failed = ! is_string($inventory) || strtotime($inventory) === false || strtotime($inventory) < strtotime($value);
                    }
                    break;
                case 'wp_debug_display':
                    if (($health['environment'] ?? null) !== 'production') {
                        return empty($health['environment']) ? $unknown : $out('not_applicable', 'Canlı ortam değil.');
                    }
                    $value = $health['debug_display'] ?? null;
                    $failed = is_bool($value) ? $value : null;
                    break;
                case 'wp_registration_role':
                    $value = ['registration' => $health['users_can_register'] ?? null, 'role' => $health['default_role'] ?? null];
                    if (is_bool($value['registration']) && is_string($value['role'])) {
                        $failed = $value['registration'] && $value['role'] === 'administrator';
                    }
                    break;
                case 'wp_blog_public':
                    if (($health['environment'] ?? null) !== 'production') {
                        return empty($health['environment']) ? $unknown : $out('not_applicable', 'Canlı ortam değil.');
                    }
                    $value = $payload['settings']['blog_public'] ?? null;
                    $failed = is_bool($value) ? ! $value : null;
                    break;
                case 'wp_cron_overdue':
                    $value = $health['cron_overdue_count'] ?? null;
                    $failed = is_numeric($value) ? $value > 0 : null;
                    break;
                case 'wp_core_update':
                    $checked = $payload['core_update_checked_at'] ?? null;
                    if (! $this->fresh($checked, $at, 172800)) {
                        return $unknown;
                    }
                    $value = ['available' => $payload['core_update_available'] ?? null, 'version' => $payload['available_wordpress_version'] ?? null];
                    $failed = is_bool($value['available']) ? $value['available'] : null;
                    break;
                case 'wp_extension_updates':
                    $extensions = $wp['extensions'] ?? null;
                    if (! is_array($extensions) || $extensions === []) {
                        return $unknown;
                    }
                    $fresh = array_filter($extensions, fn ($extension) => $this->fresh($extension['checked_at'] ?? null, $at, 172800));
                    $value = array_values(array_filter($fresh, fn ($extension) => $extension['update_available']));
                    if ($value === [] && count($fresh) !== count($extensions)) {
                        return $unknown;
                    }
                    if ($value === [] && ($wp['extensions_truncated'] ?? false)) {
                        return $unknown;
                    }
                    $failed = $value !== [];
                    break;
                case 'wp_update_freshness':
                    $value = $payload['core_update_checked_at'] ?? null;
                    $failed = is_string($value) && strtotime($value) !== false && strtotime($value) <= $at + 300
                        ? $at - strtotime($value) > 172800 : null;
                    break;
                case 'wp_health_critical':
                    $value = $payload['site_health_cached']['critical'] ?? null;
                    return is_numeric($value) && $value > 0
                        ? $out('review', 'Önbellekte kritik sağlık bildirimi var; WordPress ekranında güncel sonucu doğrulayın.', $value)
                        : $unknown;
                case 'wp_file_editor':
                    $value = $health['file_editor_disabled'] ?? null;
                    $failed = is_bool($value) ? ! $value : null;
                    break;
            }
            return $failed === null ? $unknown : $this->result($standard, $failed, $value);
        }
        $httpAt = data_get($page, 'facts.http.observed_at');
        $httpCode = data_get($page, 'facts.http.status_code');
        if ($this->fresh($httpAt, $at, 2592000) && is_numeric($httpCode) && (int) $httpCode >= 300) {
            return $out('not_applicable', 'Başarılı içerik yanıtı değil; erişim kontrolünü inceleyin.', $httpCode);
        }
        $html = $page['stored_html'] ?? null;
        if (! is_array($html) || ! $this->fresh($html['observed_at'] ?? null, $at, 2592000)) {
            return $unknown;
        }
        $headAt = data_get($page, 'facts.document_head.observed_at');
        if (is_string($headAt) && strtotime($headAt) > strtotime($html['observed_at'])) {
            return $out('unknown', 'Başlık gözlemi saklı HTML’den daha yeni; HTML verisini yenileyin.');
        }
        $inspection = $html['seo_inspection'] ?? null;
        if (! is_array($inspection)) {
            return $unknown;
        }
        $index = $page['assessment_index'] ?? [];
        $value = null;
        $failed = null;
        switch ($method) {
            case 'canonical_noindex':
            case 'hreflang_self':
            case 'hreflang_return':
                if (! ($html['head_complete'] ?? false)) {
                    return $unknown;
                }
                $urls = new PublicUrlNormalizer;
                $self = $urls->normalizeAbsolute($page['url']);
                $targets = $method === 'canonical_noindex'
                    ? array_values(array_filter(array_map(fn ($href) => $urls->resolve($inspection['base_url'] ?? $page['url'], $href), $html['canonical_hrefs'] ?? [])))
                    : array_column($inspection['hreflang'], 'url');
                if ($targets === []) {
                    return $out('not_applicable', 'Bu kontrol için bağlantı bildirilmemiş.');
                }
                $value = [];
                $missing = 0;
                if ($method === 'hreflang_self') {
                    if (! in_array($self, $targets, true)) {
                        if ($inspection['hreflang_truncated'] ?? false) {
                            return $unknown;
                        }
                        $value[] = $self;
                    }
                } else {
                    foreach (array_unique($targets, SORT_REGULAR) as $target) {
                        $candidate = is_string($target) ? ($index[$target] ?? null) : null;
                        if (! is_numeric($candidate['status_code'] ?? null) || (int) $candidate['status_code'] < 200 || (int) $candidate['status_code'] >= 300) {
                            $missing++;
                            continue;
                        }
                        if ($method === 'canonical_noindex') {
                            if (! is_bool($candidate['noindex'] ?? null)) {
                                $missing++;
                            } elseif ($candidate['noindex']) {
                                $value[] = $target;
                            }
                        } elseif (! ($candidate['hreflang_complete'] ?? false)) {
                            $missing++;
                        } elseif (! in_array($self, array_column($candidate['hreflang'] ?? [], 'url'), true)) {
                            $value[] = $target;
                        }
                    }
                }
                if ($value === [] && ($missing > 0 || ($method !== 'canonical_noindex' && ($inspection['hreflang_truncated'] ?? false)))) {
                    return $out('unknown', 'Hedeflerin güncel ve tam saklı HTML verisi gerekli.', ['unobserved' => $missing]);
                }
                $failed = $value !== [];
                break;
            case 'title_multiple':
            case 'description_multiple':
                if (! ($html['head_complete'] ?? false)) {
                    return $unknown;
                }
                $value = $inspection[$method === 'title_multiple' ? 'title_count' : 'description_count'];
                $failed = $value > 1;
                break;
            case 'h1_multiple':
                $value = $inspection['h1_count'];
                $failed = $value > 1;
                break;
            case 'language_missing':
                $value = $inspection['language'];
                $failed = $value === '';
                break;
            case 'empty_anchors':
                $value = $inspection['empty_anchors'];
                if ($value === 0 && $inspection['links_truncated']) {
                    return $unknown;
                }
                $failed = $value > 0;
                break;
            case 'image_alt':
            case 'image_dimensions':
                if ($inspection['images']['total'] === 0) {
                    return $out('not_applicable', 'HTML içinde görsel bulunmadı.');
                }
                $value = $inspection['images'][$method === 'image_alt' ? 'missing_alt' : 'missing_dimensions'];
                if ($value === 0 && $inspection['images']['truncated']) {
                    return $unknown;
                }
                $failed = $value > 0;
                break;
            case 'mixed_resources':
                $value = $inspection['mixed_resources'];
                $failed = $value > 0;
                break;
            case 'title_duplicate':
            case 'description_duplicate':
            case 'content_duplicate':
                $field = match ($method) { 'title_duplicate' => 'title', 'description_duplicate' => 'meta_description', default => 'content_fingerprint' };
                $needle = trim((string) ($html[$field] ?? ''));
                if ($needle === '' || ($method === 'content_duplicate' && trim($html['normalized_text_excerpt'] ?? '') === '')) {
                    return $out('not_applicable', 'Karşılaştırılacak dolu içerik bulunmuyor.');
                }
                $value = [];
                foreach ($index as $url => $candidate) {
                    if ($url !== $page['url'] && ($candidate[$field] ?? null) === $needle) {
                        $value[] = $url;
                    }
                }
                if ($value === [] && ! ($page['assessment_index_complete'] ?? false)) {
                    return $unknown;
                }
                $failed = $value !== [];
                break;
            case 'internal_broken':
            case 'internal_redirect':
            case 'canonical_target':
            case 'hreflang_target':
                $urls = new PublicUrlNormalizer;
                $targets = $inspection['internal_urls'];
                if ($method === 'canonical_target') {
                    if (! ($html['head_complete'] ?? false)) {
                        return $unknown;
                    }
                    $targets = array_values(array_filter(array_map(fn ($href) => $urls->resolve($inspection['base_url'] ?? $page['url'], $href), $html['canonical_hrefs'] ?? [])));
                } elseif ($method === 'hreflang_target') {
                    $targets = array_column($inspection['hreflang'], 'url');
                }
                if ($targets === []) {
                    return $out('not_applicable', 'Bu kontrol için hedef bağlantı bildirilmemiş.');
                }
                $missing = 0;
                $value = [];
                foreach ($targets as $url) {
                    $code = $index[$url]['status_code'] ?? null;
                    if (! is_numeric($code)) {
                        $missing++;
                        continue;
                    }
                    $bad = $method === 'internal_broken' ? $code >= 400
                        : ($method === 'internal_redirect' ? $code >= 300 && $code < 400 : $code >= 300);
                    if ($bad) {
                        $value[] = ['url' => $url, 'status_code' => $code];
                    }
                }
                if ($value === [] && ($missing > 0 || (str_starts_with($method, 'internal_') && $inspection['links_truncated'])
                    || ($method === 'hreflang_target' && ($inspection['hreflang_truncated'] ?? false)))) {
                    return $out('unknown', 'Bazı bağlantı hedeflerinin güncel HTTP gözlemi yok.', ['unobserved' => $missing]);
                }
                $failed = $value !== [];
                break;
        }
        return $failed === null ? $unknown : $this->result($standard, $failed, $value);
    }

    private function fresh(mixed $value, int $at, int $seconds): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }
        $timestamp = strtotime($value);
        return $timestamp !== false && $timestamp <= $at + 300 && $timestamp >= $at - $seconds;
    }

    private function result(array $standard, bool $failed, mixed $observed): array
    {
        return [
            'state' => $failed ? ($standard['classification'] === 'verified' ? 'fail' : 'review') : 'pass',
            'reason' => $failed ? $standard['action'] : 'Kontrol edilen kapsamda bu koşul için sorun saptanmadı.',
            'observed' => $observed,
        ];
    }
}
