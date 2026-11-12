<?php
/**
 * Üst bölüm: mor bilgi bandı, logo, menü, telefon ve WhatsApp.
 */
$np_logo = np_opt( 'logo' );
$np_lid  = is_array( $np_logo ) ? (int) $np_logo['ID'] : (int) $np_logo;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#951D75">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip" href="#icerik">İçeriğe geç</a>
<header class="hdr">
	<div class="top"><div><span class="a"><?php echo esc_html( np_opt( 'ust_bant' ) ); ?></span><span class="b"><?php echo esc_html( np_opt( 'calisma_saatleri' ) ); ?></span></div></div>
	<div class="bar"><div>
		<div class="logo">
			<?php if ( $np_lid ) : ?>
				<a class="im" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( np_opt( 'marka_adi' ) ); ?>"><?php echo wp_get_attachment_image( $np_lid, 'medium', false, array( 'loading' => 'eager', 'alt' => np_opt( 'marka_adi' ) ) ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( np_opt( 'marka_adi' ) ); ?></a>
				<?php if ( np_opt( 'marka_etiketi' ) ) : ?><span><?php echo esc_html( np_opt( 'marka_etiketi' ) ); ?></span><?php endif; ?>
			<?php endif; ?>
		</div>
		<nav class="nav" aria-label="Ana menü"><?php wp_nav_menu( array( 'theme_location' => 'menu-1', 'container' => false, 'fallback_cb' => false, 'depth' => 2 ) ); ?></nav>
		<button class="burger" type="button" aria-label="Menü" aria-expanded="false"><?php echo np_icon( 'menu' ); // phpcs:ignore ?></button>
		<div class="acts">
			<a class="tel" href="<?php echo esc_url( np_tel_link() ); ?>"><?php echo esc_html( np_opt( 'telefon' ) ); ?></a>
			<?php echo np_wa( 'WhatsApp', 'wa', 'btn-sm wt' ) . np_wa( '', 'wa', 'btn-sm wi' ); // phpcs:ignore ?>
		</div>
	</div></div>
</header>
<main id="icerik">
