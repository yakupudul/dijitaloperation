<?php
defined('ABSPATH') || exit;

/**
 * JSON-LD from the page's own sections: BreadcrumbList from "Yol", FAQPage from "Sık sorulan sorular", Service from
 * the page's "Hizmet bilgisi"; Organization on the home page. Editing a section updates the schema with it.
 *
 * With SEOPress: its Knowledge Graph (SEO › Social) is the Organization on the home page, under the same @id, and
 * when its single @graph is on (SEO › Advanced) these nodes join that graph instead of a second script.
 */
function mx_schema_org_id(): string
{
    return (string) apply_filters('seopress_schema_entity_id', home_url('/#organization'), 'organization');
}

/** True when SEOPress prints the Organization itself (Knowledge Graph type set, social toggle on). */
function mx_schema_seopress_org(): bool
{
    if (! defined('SEOPRESS_VERSION')) {
        return false;
    }
    $toggle = (array) get_option('seopress_toggle', []);
    $type = (string) (((array) get_option('seopress_social_option_name', []))['seopress_social_knowledge_type'] ?? '');

    return ($toggle['toggle-social'] ?? '') === '1' && $type !== '' && $type !== 'none';
}

function mx_schema_seopress_graph(): bool
{
    return defined('SEOPRESS_VERSION') && (string) (((array) get_option('seopress_advanced_option_name', []))['seopress_advanced_advanced_schema_graph'] ?? '') === '1';
}

add_filter('seopress_schemas_graph', static function ($nodes) {
    return is_array($nodes) ? array_merge($nodes, mx_schema_nodes()) : $nodes;
});

add_action('wp_head', static function (): void {
    if (mx_schema_seopress_graph()) {
        return; // the nodes go out in SEOPress's single @graph (filter above)
    }
    $graph = mx_schema_nodes();
    if ($graph) {
        echo '<script type="application/ld+json">'.wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'</script>'."\n";
    }
}, 20);

function mx_schema_nodes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (! function_exists('get_field') || ! is_singular()) {
        return $cache = [];
    }
    $id = get_queried_object_id();
    $rows = (array) (get_field('bolumler', $id) ?: []);
    $graph = [];
    $home = home_url('/');
    $org = ['@type' => 'Organization', '@id' => mx_schema_org_id(), 'name' => 'Moximu', 'url' => $home, 'email' => 'info@moximu.com'];
    $phone = trim((string) get_field('telefon', 'option'));
    if ($phone !== '') {
        $org['telephone'] = $phone;
    }
    $org['logo'] = MX_URI.'/assets/i/logo-512.png';
    $org['image'] = MX_URI.'/assets/i/og.png';
    $org['sameAs'] = ['https://www.instagram.com/moximucom/', 'https://www.facebook.com/moximucom', 'https://www.linkedin.com/company/moximu/', 'https://www.youtube.com/@yakup-udul'];
    $address = trim((string) get_field('adres', 'option'));
    if ($address !== '') {
        $org['address'] = ['@type' => 'PostalAddress', 'streetAddress' => trim((string) preg_replace('/,\s*\d{5}\b.*$/u', '', $address)), 'addressLocality' => 'Şehzadeler', 'addressRegion' => 'Manisa', 'postalCode' => '45010', 'addressCountry' => 'TR'];
    }
    $own_org = ! (is_front_page() && mx_schema_seopress_org());
    if (is_front_page() && $own_org) {
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
                    // v1 keeps the question in "baslik" ("buton_yazisi" is the + sign), v2 in "buton_yazisi".
                    $q = trim(wp_strip_all_tags((string) ($r['baslik'] ?? '')));
                    if ($q === '') {
                        $q = trim(wp_strip_all_tags((string) ($r['buton_yazisi'] ?? '')));
                    }
                    if (mb_strlen($q) < 4) {
                        continue;
                    }
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
        $node = ['@type' => 'Service', 'name' => $service['ad'], 'provider' => ['@id' => mx_schema_org_id()], 'areaServed' => 'TR',
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
            'mainEntityOfPage' => get_permalink($id), 'author' => ['@id' => mx_schema_org_id()], 'publisher' => ['@id' => mx_schema_org_id()]];
        $graph[] = $org;
        $blog = (int) get_option('page_for_posts');
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana sayfa', 'item' => $home],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Rehber', 'item' => $blog ? get_permalink($blog) : home_url('/rehber/')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => get_the_title($id)]]];
    }
    return $cache = $graph;
}
