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
                return $received ? $this->result($standard, $at - strtotime($received) > 1800, $received) : $unknown;
            }
            if (empty($wp['observed_at']) || $at - strtotime($wp['observed_at']) > 172800) {
                return $unknown;
            }
            $payload = $wp['payload'];
            $health = $payload['health'] ?? [];
            $value = null;
            $failed = null;
            switch ($method) {
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
                    if (! $checked || $at - strtotime($checked) > 172800) {
                        return $unknown;
                    }
                    $value = ['available' => $payload['core_update_available'] ?? null, 'version' => $payload['available_wordpress_version'] ?? null];
                    $failed = is_bool($value['available']) ? $value['available'] : null;
                    break;
                case 'wp_extension_updates':
                    $extensions = $wp['extensions'] ?? null;
                    if (! is_array($extensions)) {
                        return $unknown;
                    }
                    $fresh = array_filter($extensions, fn ($extension) => ! empty($extension['checked_at']) && $at - strtotime($extension['checked_at']) <= 172800);
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
                    $failed = is_string($value) && strtotime($value) !== false ? $at - strtotime($value) > 172800 : null;
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
        $html = $page['stored_html'] ?? null;
        if (! is_array($html) || empty($html['observed_at']) || $at - strtotime($html['observed_at']) > 2592000) {
            return $unknown;
        }
        $inspection = $html['seo_inspection'] ?? null;
        if (! is_array($inspection)) {
            return $unknown;
        }
        $index = $page['assessment_index'] ?? [];
        $value = null;
        $failed = null;
        switch ($method) {
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
                    $targets = array_values(array_filter(array_map(fn ($href) => $urls->resolve($page['url'], $href), $html['canonical_hrefs'] ?? [])));
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
                if ($value === [] && ($missing > 0 || (str_starts_with($method, 'internal_') && $inspection['links_truncated']))) {
                    return $out('unknown', 'Bazı bağlantı hedeflerinin güncel HTTP gözlemi yok.', ['unobserved' => $missing]);
                }
                $failed = $value !== [];
                break;
        }
        return $failed === null ? $unknown : $this->result($standard, $failed, $value);
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
