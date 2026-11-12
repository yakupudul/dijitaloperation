<?php
/**
 * Alt bölüm: marka, perde modelleri, hizmetler, iletişim; telefonda sabit Ara / WhatsApp çubuğu.
 */
$np_l = function ( $items ) {
	$h = '<ul>';
	foreach ( $items as $i ) {
		$h .= '<li><a href="' . esc_url( $i[1] ) . '">' . esc_html( $i[0] ) . '</a></li>';
	}
	return $h . '</ul>';
};
$np_p = array();
foreach ( np_perde_ids( 9 ) as $np_id ) {
	$np_k   = function_exists( 'get_field' ) ? (string) get_field( 'kisa_ad', $np_id ) : '';
	$np_p[] = array( $np_k ? $np_k : get_the_title( $np_id ), get_permalink( $np_id ) );
}
$np_s = array(
	array( 'Ölçü ve montaj', np_url( '/olcu-ve-montaj/' ) ),
	array( 'Perde tamiri ve yedek parça', np_url( '/perde-tamiri-ve-yedek-parca/' ) ),
	array( 'Zip perde', np_url( '/perde/zip-perde/' ) ),
	array( 'Motorlu perde', np_url( '/perde/motorlu-perde/' ) ),
	array( 'Hizmet bölgeleri', np_url( '/hizmet-bolgeleri/' ) ),
	array( 'Perde rehberi', np_url( '/rehber/' ) ),
	array( 'Hakkımızda', np_url( '/hakkimizda/' ) ),
	array( 'Sık sorulan sorular', np_url( '/sik-sorulan-sorular/' ) ),
);
?>
</main>
<footer class="ftr"><div class="wrap">
	<div class="cols">
		<div class="br">
			<a class="name" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( np_opt( 'marka_adi' ) ); ?></a>
			<p><?php echo esc_html( np_opt( 'marka_tanitim' ) ); ?></p>
			<div class="btns"><?php echo np_wa( 'WhatsApp', 'wa', 'btn-sm' ) . np_call( 'line-w', 'btn-sm' ); // phpcs:ignore ?></div>
			<?php if ( np_opt( 'instagram' ) || np_opt( 'facebook' ) ) : ?>
			<p class="soc"><?php if ( np_opt( 'instagram' ) ) : ?><a href="<?php echo esc_url( np_opt( 'instagram' ) ); ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?><?php if ( np_opt( 'facebook' ) ) : ?><a href="<?php echo esc_url( np_opt( 'facebook' ) ); ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?></p>
			<?php endif; ?>
		</div>
		<div><h4>Perde modelleri</h4><?php echo $np_l( $np_p ); // phpcs:ignore ?></div>
		<div><h4>Hizmetler</h4><?php echo $np_l( $np_s ); // phpcs:ignore ?></div>
		<div class="ct">
			<h4>İletişim</h4>
			<div><a href="<?php echo esc_url( np_tel_link() ); ?>"><?php echo esc_html( np_opt( 'telefon' ) ); ?></a></div>
			<div><?php echo esc_html( np_opt( 'calisma_saatleri' ) ); ?></div>
			<div><?php echo esc_html( np_opt( 'adres' ) ); ?></div>
			<?php if ( np_opt( 'eposta' ) ) : ?><div><a href="mailto:<?php echo esc_attr( np_opt( 'eposta' ) ); ?>"><?php echo esc_html( np_opt( 'eposta' ) ); ?></a></div><?php endif; ?>
			<div><a class="pk" href="<?php echo esc_url( np_url( '/iletisim/' ) ); ?>">İletişim sayfası →</a></div>
		</div>
	</div>
	<div class="bot"><span>© <?php echo esc_html( gmdate( 'Y' ) ); ?></span><span><?php echo esc_html( np_opt( 'marka_adi' ) ); ?></span><span>· Tüm hakları saklıdır.</span>
		<a class="lg" href="<?php echo esc_url( np_url( '/kvkk-ve-cerez-politikasi/' ) ); ?>">KVKK ve çerez politikası</a><a class="cr" href="https://moximu.com/" target="_blank" rel="noopener">Created by Moximu</a></div>
</div></footer>
<div class="mbar"><?php echo np_call( 'white', 'btn-sm', 'Ara' ) . np_wa( 'WhatsApp', 'wa', 'btn-sm' ); // phpcs:ignore ?></div>
<?php wp_footer(); ?>
</body>
</html>
