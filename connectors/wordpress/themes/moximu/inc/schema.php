<?php
defined('ABSPATH') || exit;

/**
 * JSON-LD from the page's own sections: BreadcrumbList from "Yol", FAQPage from "Sık sorulan sorular", Service from
 * the page's "Hizmet bilgisi"; Organization on the home page. Editing a section updates the schema with it.
 */
add_action('wp_head', static function (): void {
    if (! function_exists('get_field') || ! is_singular()) {
        return;
    }
    $id = get_queried_object_id();
    $rows = (array) (get_field('bolumler', $id) ?: []);
    $graph = [];
    $home = home_url('/');
    $org = ['@type' => 'Organization', '@id' => $home.'#org', 'name' => 'Moximu', 'url' => $home];
    $phone = trim((string) get_field('telefon', 'option'));
    if ($phone !== '') {
        $org['telephone'] = $phone;
    }
    $org['logo'] = MX_URI.'/assets/i/og.png';
    $org['sameAs'] = ['https://www.instagram.com/moximucom/', 'https://www.facebook.com/moximucom', 'https://www.linkedin.com/company/moximu/', 'https://www.youtube.com/@yakup-udul'];
    $address = trim((string) get_field('adres', 'option'));
    if ($address !== '') {
        $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressLocality' => 'Manisa', 'addressCountry' => 'TR'];
    }
    if (is_front_page()) {
        $graph[] = $org;
    }
    foreach ($rows as $row) {
        $layout = $row['acf_fc_layout'] ?? '';
        if ($layout === 'yol' && ! empty($row['yazi'])) {
            $items = [];
            if (preg_match_all('~<a[^>]+href="([^"]*)"[^>]*>(.*?)</a>|<span>(.*?)</span>~s', (string) $row['yazi'], $m, PREG_SET_ORDER)) {
                foreach ($m as $i => $hit) {
                    $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags($hit[2] !== '' ? $hit[2] : ($hit[3] ?? ''))];
                    if ($hit[1] !== '') {
                        $item['item'] = str_starts_with($hit[1], '/') ? home_url($hit[1]) : $hit[1];
                    }
                    $items[] = $item;
                }
            }
            if ($items) {
                $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
            }
        }
        if ($layout === 'sss') {
            $qa = [];
            foreach ($row as $name => $value) {
                if (! str_starts_with((string) $name, 'liste') || ! is_array($value)) {
                    continue;
                }
                foreach ($value as $r) {
                    $q = wp_strip_all_tags((string) ($r['buton_yazisi'] ?? $r['baslik'] ?? ''));
                    $a = wp_strip_all_tags((string) ($r['metin'] ?? ''));
                    if ($q !== '' && $a !== '') {
                        $qa[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
                    }
                }
            }
            if ($qa) {
                $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $qa];
            }
        }
    }
    $service = (array) (get_field('hizmet', $id) ?: []);
    if (! empty($service['ad'])) {
        $node = ['@type' => 'Service', 'name' => $service['ad'], 'provider' => ['@id' => $home.'#org'], 'areaServed' => 'TR',
            'audience' => ['@type' => 'Audience', 'audienceType' => 'Klinikler ve muayenehaneler'], 'url' => get_permalink($id)];
        if (! empty($service['tur'])) {
            $node['serviceType'] = $service['tur'];
        }
        if (! empty($service['fiyat'])) {
            $node['offers'] = ['@type' => 'Offer', 'price' => (string) $service['fiyat'], 'priceCurrency' => 'TRY'];
        }
        $graph[] = $node;
        if (! is_front_page()) {
            $graph[] = $org;
        }
    }
    if (is_singular('post')) {
        $graph[] = ['@type' => 'BlogPosting', 'headline' => get_the_title($id), 'datePublished' => get_the_date('c', $id), 'dateModified' => get_the_modified_date('c', $id),
            'mainEntityOfPage' => get_permalink($id), 'author' => ['@id' => $home.'#org'], 'publisher' => ['@id' => $home.'#org']];
        $graph[] = $org;
        $blog = (int) get_option('page_for_posts');
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana sayfa', 'item' => $home],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Rehber', 'item' => $blog ? get_permalink($blog) : home_url('/rehber/')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => get_the_title($id)]]];
    }
    if ($graph) {
        echo '<script type="application/ld+json">'.wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'</script>'."\n";
    }
}, 20);
