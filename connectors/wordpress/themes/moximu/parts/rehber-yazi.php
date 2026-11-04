<?php defined('ABSPATH') || exit;
// The post body with an id on every h2, and the table of contents built from them.
$html = apply_filters('the_content', get_the_content());
$toc = [];
$used = [];
$html = preg_replace_callback('~<h2([^>]*)>(.*?)</h2>~is', static function (array $m) use (&$toc, &$used): string {
    $text = wp_strip_all_tags($m[2]);
    if (preg_match('~\bid="([^"]+)"~', $m[1], $id)) {
        $slug = $id[1];
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
}, (string) $html); ?>
<section class="mx lp-art" id="icerik"><div class="wrap"><div class="lp-art-g">
<aside class="lp-toc"><b><?= mx_h($d['kalin_yazi'] ?? 'Bu yazıda') ?></b><nav><?php foreach ($toc as [$slug, $text]) : ?><a href="#<?= esc_attr($slug) ?>"><?= esc_html($text) ?></a><?php endforeach; ?></nav><a class="btn" href="<?= mx_url($d['baglanti'] ?? '/ucretsiz-klinik-analizi/') ?>"><?= mx_h($d['baglanti_yaz'] ?? 'Ücretsiz ön değerlendirme') ?></a></aside>
<article class="lp-art-body">
<?= $html // already filtered by the_content ?>
<div class="lp-art-cta"><div><?= mx_h($d['yazi_6'] ?? '') ?></div><a class="btn" href="<?= mx_url($d['baglanti_2'] ?? '/ucretsiz-klinik-analizi/') ?>"><?= mx_h($d['baglanti_yaz_2'] ?? 'Ücretsiz ön değerlendirme') ?></a></div>
<div class="lp-author"><img alt="" src="<?= MX_URI ?>/assets/i/mark-static.svg"/><div><?= mx_h($d['yazi_10'] ?? '') ?></div></div>
</article>
</div></div></section>
