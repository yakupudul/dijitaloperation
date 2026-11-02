<?php
/**
 * A text page (legal pages, glossary, tools, comparisons, hubs …): breadcrumb from the page's parents, a heading with
 * the excerpt as lead, the editor content with a table of contents when it has three or more headings, then the
 * sections chosen under "Sayfa sonu" (results cards, Google rating, analysis form), taken from the home page so they
 * stay the same everywhere.
 */
defined('ABSPATH') || exit;
$GLOBALS['mx_drawn'] = true;
$id = get_the_ID();
$crumbs = [];
foreach (array_reverse(get_post_ancestors($id)) as $ancestor) {
    $crumbs[] = '<a href="'.esc_url(get_permalink($ancestor)).'">'.esc_html(get_the_title($ancestor)).'</a>';
}
if (get_post_type($id) === 'musteri') {
    $hub = get_page_by_path('sonuclar');
    if ($hub) {
        $crumbs[] = '<a href="'.esc_url(get_permalink($hub)).'">'.esc_html(get_the_title($hub)).'</a>';
    }
}
$eyebrow = trim((string) get_post_meta($id, '_mx_ust_yazi', true));
if ($eyebrow === '') {
    $parents = get_post_ancestors($id);
    $eyebrow = $parents ? get_the_title($parents[0]) : 'Moximu';
}
$html = apply_filters('the_content', get_the_content());
$toc = [];
$used = [];
$html = preg_replace_callback('~<h2([^>]*)>(.*?)</h2>~is', static function (array $m) use (&$toc, &$used): string {
    $text = wp_strip_all_tags($m[2]);
    if (preg_match('~\bid="([^"]+)"~', $m[1], $found)) {
        $slug = $found[1];
        $attrs = $m[1];
    } else {
        $slug = sanitize_title($text) ?: 'b'.count($toc);
        while (isset($used[$slug])) {
            $slug .= '-2';
        }
        $attrs = $m[1].' id="'.esc_attr($slug).'"';
    }
    $used[$slug] = true;
    $toc[] = [$slug, $text];

    return '<h2'.$attrs.'>'.$m[2].'</h2>';
}, (string) $html);
$with_toc = count($toc) >= 3;
$after = get_post_meta($id, '_mx_sayfa_sonu', true);
$after = is_string($after) && $after !== '' ? array_filter(array_map('trim', explode(',', $after))) : ['yorum_ozet', 'analiz'];
// Legal and utility pages ("Sayfa sonu: yok") stay quiet; every other text page gets the sales hero and the numbers band.
$sales = ! in_array('yok', $after, true);
if ($sales && ! in_array('sayilar', $after, true)) {
    $after = array_values($after);
    $at = array_search('analiz', $after, true);
    array_splice($after, $at === false ? count($after) : (int) $at, 0, ['sayilar']);
}
?>
<nav aria-label="breadcrumb" class="mx lp-bc"><div class="wrap"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <?= $crumbs ? implode(' › ', $crumbs).' › ' : '' ?><span><?php the_title(); ?></span></div></nav>
<section class="mx lp-art-hero mx-page-hero<?= $sales ? ' is-sales' : '' ?>" id="yazi"><div class="wrap">
<div class="lp-art-head"><span class="eyebrow"><img alt="" class="mxeye" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><?= esc_html($eyebrow) ?></span><h1><?php the_title(); ?></h1>
<?php if (has_excerpt()) : ?><p class="lead"><?= esc_html(get_the_excerpt()) ?></p><?php endif; ?>
<?php if ($sales) : ?><div class="mx-page-cta"><a class="btn lp-wa" href="<?= esc_url(mx_wa_url()) ?>" rel="noopener" target="_blank"><?= mx_wa_icon() ?>WhatsApp'tan yazın</a><a class="btn outline" href="<?= esc_url(home_url('/ucretsiz-klinik-analizi/')) ?>">Ücretsiz klinik analizi</a></div>
<div class="mx-page-trust"><span><b>4,9</b> Google puanı</span><span><b>500+</b> web sitesi</span><span><b>50+</b> klinik</span><span><b>24 saatte</b> ilk rapor</span></div><?php endif; ?>
</div></div></section>
<section class="mx lp-art mx-page" id="icerik"><div class="wrap"><div class="<?= $with_toc ? 'lp-art-g' : 'mx-page-one' ?>">
<?php if ($with_toc) : ?><aside class="lp-toc"><b>Bu sayfada</b><nav><?php foreach ($toc as [$slug, $text]) : ?><a href="#<?= esc_attr($slug) ?>"><?= esc_html($text) ?></a><?php endforeach; ?></nav><a class="btn" href="<?= esc_url(home_url('/ucretsiz-klinik-analizi/')) ?>">Ücretsiz klinik analizi</a></aside><?php endif; ?>
<article class="lp-art-body"><?= $html // already filtered by the_content ?></article>
</div></div></section>
<?php
foreach ($after as $layout) {
    if (in_array($layout, ['sonuclar', 'yorum_ozet', 'analiz', 'sayilar'], true)) {
        mx_shared_section($layout);
    }
}
