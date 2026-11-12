<?php
/**
 * Perde modeli sayfası. Metinler yazının "Perde modeli bilgileri" alanlarından gelir.
 */
get_header();
while ( have_posts() ) :
	the_post();
	$np_id   = get_the_ID();
	$np_f    = function ( $k ) use ( $np_id ) {
		return function_exists( 'get_field' ) ? get_field( $k, $np_id ) : '';
	};
	$np_kisa = (string) $np_f( 'kisa_ad' );
	$np_kisa = $np_kisa ? $np_kisa : get_the_title();
	$np_msg  = 'Merhaba, ' . $np_kisa . ' için fiyat almak istiyorum.';
	$np_rows = array();
	for ( $np_i = 1; $np_i <= 4; $np_i++ ) {
		$np_rows[] = array( (string) $np_f( 'bilgi_' . $np_i . '_baslik' ), (string) $np_f( 'bilgi_' . $np_i . '_deger' ) );
	}
	$np_gal  = (array) $np_f( 'galeri' );
	$np_bol  = np_rep( 'bolumler', 'baslik', 'metin', $np_id );
	$np_sec  = np_rep( 'secenekler', 'ad', 'aciklama', $np_id );
	$np_mon  = (string) $np_f( 'montaj' );
	$np_faq  = np_rep( 'sorular', 'soru', 'cevap', $np_id );
	$np_det  = (string) $np_f( 'detay' );
	$np_pb   = function ( $title, $right, $cls = '' ) {
		return '<div class="pb' . $cls . '"><div class="l"><h2 class="t-h2s c-ink">' . esc_html( $title ) . '</h2></div><div class="r">' . $right . '</div></div>';
	};
	$np_txt  = function ( $t ) {
		return '<div class="rt c-body">' . wpautop( wp_kses_post( $t ) ) . '</div>';
	};
	?>
<section class="sec" style="--pt:28px;--pb:88px;--g:28px"><div class="wrap">
	<?php echo np_crumb( 'Perde modelleri', '/perde-modelleri/', get_the_title() ); // phpcs:ignore ?>
	<div class="sp-row">
		<div class="sp-pics">
			<?php the_post_thumbnail( 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width:1024px) 100vw, 766px' ) ); ?>
			<?php if ( $np_gal ) : ?>
			<div class="sp-gal">
				<?php
				foreach ( $np_gal as $np_g ) :
					$np_gid = is_array( $np_g ) ? (int) $np_g['ID'] : (int) $np_g;
					?>
				<a href="<?php echo esc_url( wp_get_attachment_image_url( $np_gid, 'full' ) ); ?>"><?php echo wp_get_attachment_image( $np_gid, 'medium_large', false, array( 'sizes' => '380px' ) ); ?></a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<div class="sp-info">
			<div class="t-eyebrow c-plum"><?php echo esc_html( (string) $np_f( 'grup_etiketi' ) ); ?></div>
			<h1 class="t-h1 c-ink"><?php echo esc_html( $np_f( 'sayfa_h1' ) ? $np_f( 'sayfa_h1' ) : get_the_title() ); ?></h1>
			<div class="t-lead c-text"><p><?php echo esc_html( (string) $np_f( 'giris_metni' ) ); ?></p></div>
			<?php echo np_rows( $np_rows, 30 ); // phpcs:ignore ?>
			<div class="cta"><?php echo np_wa( $np_kisa . ' için fiyat sorun', 'wa', 'btn-full', $np_msg ) . np_call( 'line', 'btn-full' ); // phpcs:ignore ?></div>
			<div class="t-small c-text">Arama ve WhatsApp: <?php echo esc_html( np_opt( 'calisma_saatleri' ) ); ?></div>
		</div>
	</div>
</div></section>
<section class="sec" style="--pt:0px;--pb:56px;--g:0px"><div class="wrap">
	<?php
	if ( $np_bol || $np_sec || $np_mon || $np_faq ) {
		foreach ( $np_bol as $np_b ) {
			echo $np_pb( $np_b[0], $np_txt( $np_b[1] ) ); // phpcs:ignore
		}
		if ( $np_sec ) {
			$np_o = '<div class="opts">';
			foreach ( $np_sec as $np_s ) {
				$np_o .= '<div><h3 class="t-h4 c-ink">' . esc_html( $np_s[0] ) . '</h3><p>' . esc_html( $np_s[1] ) . '</p></div>';
			}
			echo $np_pb( 'Kumaş ve sistem seçenekleri', $np_txt( 'Ölçü sırasında aşağıdaki seçeneklerin örneklerini yanımızda getiriyor, pencerenizin önünde gösteriyoruz.' ) . $np_o . '</div>' ); // phpcs:ignore
		}
		if ( $np_mon ) {
			echo $np_pb( 'Ölçü ve montaj', $np_txt( $np_mon ) . '<div class="btns">' . np_wa( $np_kisa . ' için fiyat sorun', 'wa', '', $np_msg ) . np_call() . '</div>' ); // phpcs:ignore
		}
		if ( $np_faq ) {
			echo $np_pb( $np_kisa . ' hakkında sorular', np_acc( $np_faq ), ' last' ); // phpcs:ignore
		}
	} elseif ( trim( (string) get_the_content() ) ) {
		echo '<div class="detay"><div class="prose">';
		the_content();
		echo '</div></div>';
	}
	?>
</div></section>
	<?php if ( $np_det ) : ?>
<section class="sec" style="--pt:0px;--pb:88px;--g:0px"><div class="wrap"><div class="detay"><div class="prose"><?php echo wp_kses_post( $np_det ); ?></div></div></div></section>
	<?php endif; ?>
<section class="sec bg-mist"><div class="wrap">
	<?php
	$np_all = np_perde_ids( 40 );
	$np_pos = (int) array_search( $np_id, $np_all, true );
	$np_rel = array_slice( array_merge( array_slice( $np_all, $np_pos + 1 ), array_slice( $np_all, 0, $np_pos ) ), 0, 3 );
	echo np_head( 'Perde modelleri', 'Benzer perde modelleri' ) . np_perde_grid( array( 'ids' => $np_rel, 'fixed' => true ) ); // phpcs:ignore
	?>
</div></section>
	<?php
	echo np_measure_band() . np_final_cta(); // phpcs:ignore
endwhile;
get_footer();
