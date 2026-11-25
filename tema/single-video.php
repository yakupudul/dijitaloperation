<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$mxv = mxv_veri(get_the_ID());
$mxv_arsiv = get_post_type_archive_link('video');
$mxv_u = rawurlencode($mxv['url']);
$mxv_t = rawurlencode($mxv['baslik']);
?>
<main>
<section class="vd-hero"><div class="wrap vd-grid <?php echo esc_attr($mxv['bicim']); ?>">
<div class="vd-player">
<?php if ($mxv['yt'] !== '') : ?>
<button class="yt-lite" type="button" data-ytl="<?php echo esc_attr($mxv['yt']); ?>" data-name="<?php echo esc_attr($mxv['baslik']); ?>" aria-label="Videoyu oynatın: <?php echo esc_attr($mxv['baslik']); ?>"><img src="<?php echo esc_url($mxv['kapak_tam']); ?>" alt="<?php echo esc_attr($mxv['baslik']); ?>" fetchpriority="high"><span class="play"><svg viewBox="0 0 10 12"><path d="M0 0l10 6-10 6z"/></svg></span></button>
<?php elseif ($mxv['dosya'] !== '') : ?>
<video controls playsinline preload="metadata" poster="<?php echo esc_url($mxv['kapak_tam']); ?>" src="<?php echo esc_url($mxv['dosya']); ?>"></video>
<?php endif; ?>
</div>
<div class="vd-info">
<nav class="crumb" aria-label="Sayfa yolu"><a href="<?php echo esc_url(home_url('/')); ?>">Ana sayfa</a><svg class="ico"><use href="#i-arrow"/></svg><a href="<?php echo esc_url($mxv_arsiv); ?>">Videolar</a></nav>
<span class="eyebrow">Video</span>
<h1><?php echo esc_html($mxv['baslik']); ?></h1>
<p class="post-meta"><img src="<?php echo esc_url(MXP_URI . '/assets/img/ali-avatar.webp'); ?>" alt="" width="40" height="40"><span><b>Uzm. Klinik Psikolog Ali Akbulut</b> · <?php echo esc_html(get_the_date('j F Y')); ?></span></p>
<?php if (trim((string) get_post()->post_content) !== '') : ?><div class="prose vd-text"><?php echo mxp_content(); // phpcs:ignore ?></div><?php endif; ?>
<div class="share" data-url="<?php echo esc_url($mxv['url']); ?>" data-name="<?php echo esc_attr($mxv['baslik']); ?>" data-img="<?php echo esc_url($mxv['kapak_tam']); ?>">
<span class="share-l">Paylaşın</span>
<button class="sh only-share" type="button" data-share><svg class="ico"><use href="#i-share"/></svg>Paylaş</button>
<a class="sh" data-net="whatsapp" href="https://wa.me/?text=<?php echo $mxv_t . '%0A' . $mxv_u; // phpcs:ignore ?>" target="_blank" rel="noopener"><svg class="ico"><use href="#i-wa"/></svg>WhatsApp</a>
<a class="sh" data-net="facebook" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $mxv_u; // phpcs:ignore ?>" target="_blank" rel="noopener"><svg class="ico"><use href="#i-fb"/></svg>Facebook</a>
<a class="sh" data-net="x" href="https://twitter.com/intent/tweet?text=<?php echo $mxv_t; // phpcs:ignore ?>&amp;url=<?php echo $mxv_u; // phpcs:ignore ?>" target="_blank" rel="noopener"><svg class="ico"><use href="#i-x"/></svg>X</a>
<button class="sh" type="button" data-copy><svg class="ico"><use href="#i-link"/></svg>Bağlantıyı kopyala</button>
</div>
<div class="actions"><a class="btn btn-navy" href="<?php echo esc_url(mxp_tokenler()['WA']); ?>"><svg class="ico"><use href="#i-wa"/></svg>WhatsApp'tan yazın</a><a class="btn btn-line" href="<?php echo esc_url(mxp_tokenler()['TELL']); ?>"><svg class="ico"><use href="#i-phone"/></svg>Hemen arayın</a></div>
</div>
</div></section>
<?php
$mxv_kart = '';
foreach ($mxv['sayfalar'] as $mxv_pid) {
	$mxv_kart .= mxv_hizmet_karti($mxv_pid);
}
if ($mxv_kart !== '') {
	echo '<section class="pg-rel vd-rel"><div class="wrap"><h2>Bu video ile ilgili</h2><div class="cards4 flat">' . $mxv_kart . '</div></div></section>'; // phpcs:ignore
}
$mxv_diger = $mxv['sayfalar'] ? mxv_liste(['sayfa' => $mxv['sayfalar'][0], 'haric' => [$mxv['id']]], 8) : [];
if (count($mxv_diger) < 4) {
	$mxv_diger = array_merge($mxv_diger, mxv_liste(['haric' => array_merge([$mxv['id']], wp_list_pluck($mxv_diger, 'ID')), 'bicim' => $mxv['bicim']], 8 - count($mxv_diger), 'yeni'));
}
if ($mxv_diger) {
	echo '<section class="pg-rel"><div class="wrap"><div class="sec-h"><div><h2>Diğer videolar</h2></div><a class="more" href="' . esc_url($mxv_arsiv) . '">Tüm videolar</a></div><div class="v-grid' . ($mxv['bicim'] === 'yatay' ? ' wide' : '') . '">' . mxv_kartlar($mxv_diger) . '</div></div></section>'; // phpcs:ignore
}
mxp_part('cta');
?>
</main>
<?php
get_footer();
