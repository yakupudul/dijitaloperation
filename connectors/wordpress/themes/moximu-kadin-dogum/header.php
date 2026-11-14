<?php
if (! defined('ABSPATH')) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="bk-atla" href="#icerik">İçeriğe geç</a>
<header class="bk-baslik">
	<div class="bk-ust1">
		<div class="kap">
			<a class="bk-logo" href="<?php echo esc_url(bk_url('/')); ?>" aria-label="<?php echo esc_attr(bk_ayar('hekim') . ' ana sayfa'); ?>"><?php echo bk_logo(true); ?></a>
			<?php bk_arama_formu(); ?>
			<?php bk_ust_menu(); ?>
			<a class="bk-randevu" href="<?php echo esc_url(bk_ayar('whatsapp_link')); ?>" target="_blank" rel="noopener"><?php echo bk_ikon('wa'); ?><span>Randevu Al</span></a>
			<button type="button" class="bk-hamburger" aria-controls="bk-oc" aria-expanded="false" aria-label="Menüyü aç"><?php echo bk_ikon('menu'); ?></button>
		</div>
	</div>
	<?php bk_hizmet_menusu(); ?>
</header>
<?php bk_mobil_menu(); ?>
<main id="icerik" class="bk-ana">
