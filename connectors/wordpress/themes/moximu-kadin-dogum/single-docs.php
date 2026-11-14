<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$id = get_the_ID();
$kat = get_the_terms($id, 'doc_category');
$kat_id = ($kat && ! is_wp_error($kat)) ? $kat[0]->term_id : 0;
$url = rawurlencode(get_permalink());
$baslik = rawurlencode(get_the_title());
?>
<div class="kap bk-soru-duzen">
	<article class="bk-soru bk-golge">
		<?php bk_kirinti('soru'); ?>
		<h1><?php the_title(); ?></h1>
		<p class="bk-soru-tarih">Güncelleme Tarihi: <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date('F j, Y')); ?></time></p>
		<div class="bk-icerik bk-soru-icerik"><?php the_content(); ?></div>
		<?php if (function_exists('bk_soru_eki')) { bk_soru_eki($id, ($kat && ! is_wp_error($kat)) ? $kat[0] : null); } ?>
		<div class="bk-tepki" data-tepki>
			<p>Cevabı nasıl buldunuz?</p>
			<div>
				<button type="button" data-deger="iyi" aria-label="Faydalı">😊</button>
				<button type="button" data-deger="orta" aria-label="Kısmen faydalı">😐</button>
				<button type="button" data-deger="kotu" aria-label="Faydalı değil">😞</button>
			</div>
		</div>
		<div class="bk-paylas">
			<span>Soruyu paylaşın:</span>
			<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $url; ?>" target="_blank" rel="noopener" aria-label="Facebook'ta paylaş"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="#1877f2"/><path fill="#fff" d="M13.3 19v-6.2h2.1l.3-2.4h-2.4V8.9c0-.7.2-1.2 1.2-1.2h1.3V5.5c-.2 0-1-.1-1.9-.1-1.9 0-3.2 1.2-3.2 3.3v1.8H8.6v2.4h2.1V19h2.6z"/></svg></a>
			<a href="https://twitter.com/intent/tweet?url=<?php echo $url; ?>&amp;text=<?php echo $baslik; ?>" target="_blank" rel="noopener" aria-label="X'te paylaş"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#000" d="M17.8 3h3.1l-6.8 7.7 8 10.3h-6.2l-4.9-6.3L5.4 21H2.3l7.2-8.3L1.8 3h6.4l4.4 5.8L17.8 3zm-1.1 16.2h1.7L7.3 4.7H5.5l11.2 14.5z"/></svg></a>
			<a href="https://www.linkedin.com/shareArticle?mini=true&amp;url=<?php echo $url; ?>" target="_blank" rel="noopener" aria-label="LinkedIn'de paylaş"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="#0a66c2"/><path fill="#fff" d="M8.3 17.5H5.9V9.8h2.4v7.7zM7.1 8.7c-.8 0-1.4-.6-1.4-1.4s.6-1.4 1.4-1.4 1.4.6 1.4 1.4-.6 1.4-1.4 1.4zm11 8.8h-2.4v-3.7c0-.9 0-2-1.2-2s-1.4.9-1.4 1.9v3.8H10.7V9.8h2.3v1h.1c.3-.6 1.1-1.2 2.3-1.2 2.4 0 2.9 1.6 2.9 3.7v4.2z"/></svg></a>
			<a href="https://pinterest.com/pin/create/button/?url=<?php echo $url; ?>&amp;description=<?php echo $baslik; ?>" target="_blank" rel="noopener" aria-label="Pinterest'te paylaş"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="12" fill="#e60023"/><path fill="#fff" d="M12.3 5.5c-3.6 0-5.4 2.6-5.4 4.7 0 1.3.5 2.4 1.5 2.8.2.1.3 0 .4-.2l.1-.6c.1-.2 0-.3-.1-.5-.3-.4-.5-.9-.5-1.6 0-2 1.5-3.9 4-3.9 2.2 0 3.4 1.3 3.4 3.1 0 2.4-1 4.3-2.6 4.3-.8 0-1.5-.7-1.3-1.6.3-1 .7-2.2.7-2.9 0-.7-.4-1.2-1.1-1.2-.9 0-1.6.9-1.6 2.2 0 .8.3 1.3.3 1.3l-1.1 4.6c-.3 1.4 0 3 0 3.2 0 .1.1.1.2 0 .1-.1 1-1.3 1.3-2.5l.5-2c.3.5 1 .9 1.8.9 2.4 0 4-2.2 4-5.1 0-2.2-1.9-4.3-4.6-4.3z"/></svg></a>
		</div>
	</article>
	<aside class="bk-yan bk-golge bk-soru-orta">
		<?php get_template_part('parts/soru-ara'); ?>
		<nav class="bk-soru-kategoriler" aria-label="Soru kategorileri">
			<?php foreach (bk_soru_kategorileri() as $k) : ?>
				<details<?php echo $k->term_id === $kat_id ? ' open' : ''; ?>>
					<summary><?php echo esc_html($k->name); ?> <?php echo bk_ikon('ok'); ?></summary>
					<ul>
						<?php foreach (bk_ayri_sorular($k->term_id, 10) as $p) : /* ayrı sayfası olan, en çok aranan 10 soru */ ?>
							<li<?php echo (int) $p->ID === $id ? ' class="aktif"' : ''; ?>><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
						<?php endforeach; ?>
						<li class="tumu"><a href="<?php echo esc_url(get_term_link($k)); ?>">Tüm soruları gör (<?php echo esc_html((string) $k->count); ?>)</a></li>
					</ul>
				</details>
			<?php endforeach; ?>
		</nav>
	</aside>
	<aside class="bk-yan bk-golge bk-soru-sag">
		<?php bk_hekim_karti('soru'); ?>
		<?php bk_iletisim_kutusu('Prof. Dr. Burcu Kısa - Kadın Hastalıkları Ve Doğum Uzmanı Ankara', false); ?>
	</aside>
</div>
<?php
get_footer();
