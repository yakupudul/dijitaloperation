<?php
/**
 * İlçe (hizmet bölgesi) sayfası. Metinler yazının "Bölge bilgileri" alanlarından gelir.
 */
get_header();
while ( have_posts() ) :
	the_post();
	$np_id   = get_the_ID();
	$np_f    = function ( $k ) use ( $np_id ) {
		return function_exists( 'get_field' ) ? get_field( $k, $np_id ) : '';
	};
	$np_ad   = (string) $np_f( 'kisa_ad' );
	$np_ad   = $np_ad ? $np_ad : get_the_title();
	$np_msg  = 'Merhaba, ' . $np_ad . ' için perde fiyatı almak istiyorum.';
	$np_is   = (array) $np_f( 'isler' );
	$np_mah  = (string) $np_f( 'mahalle_metni' );
	$np_faq  = np_rep( 'sorular', 'soru', 'cevap', $np_id );
	$np_pb   = function ( $title, $right, $cls = '' ) {
		return '<div class="pb b' . $cls . '"><div class="l"><h2 class="t-h2s c-ink">' . esc_html( $title ) . '</h2></div><div class="r">' . $right . '</div></div>';
	};
	?>
<section class="sec" style="--pt:28px;--pb:72px;--g:28px"><div class="wrap">
	<?php echo np_crumb( 'Hizmet bölgeleri', '/hizmet-bolgeleri/', get_the_title() ); // phpcs:ignore ?>
	<div class="sb-row">
		<div class="l">
			<div class="t-eyebrow c-plum">İzmir hizmet bölgesi</div>
			<h1 class="t-h1 c-ink"><?php echo esc_html( $np_f( 'sayfa_h1' ) ? $np_f( 'sayfa_h1' ) : get_the_title() ); ?></h1>
			<div class="t-lead c-text"><p><?php echo esc_html( (string) $np_f( 'giris_metni' ) ); ?></p></div>
			<div class="btns"><?php echo np_wa( "WhatsApp'tan fiyat sorun", 'wa', '', $np_msg ) . np_call(); // phpcs:ignore ?></div>
			<div class="t-small c-text"><p>Ölçü ve montaj ücretsiz. Fotoğraf ve yaklaşık ölçü gönderirseniz ön fiyatı WhatsApp'tan iletiyoruz.</p></div>
		</div>
		<div class="r"><?php the_post_thumbnail( 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width:1024px) 100vw, 634px' ) ); ?></div>
	</div>
</div></section>
<section class="sec" style="--pt:0px;--pb:56px;--g:0px"><div class="wrap">
	<?php
	if ( $np_is || $np_mah || $np_faq ) {
		if ( $np_is ) {
			$np_o = '<div class="isler">';
			foreach ( $np_is as $np_r ) {
				if ( empty( $np_r['ad'] ) ) {
					continue;
				}
				$np_p = $np_r['perde'] ?? 0;
				$np_p = is_object( $np_p ) ? $np_p->ID : (int) $np_p;
				$np_u = $np_p ? get_permalink( $np_p ) : '';
				$np_o .= '<div><h3 class="t-h3 c-ink">' . ( $np_u ? '<a href="' . esc_url( $np_u ) . '">' . esc_html( $np_r['ad'] ) . '</a>' : esc_html( $np_r['ad'] ) ) . '</h3><p>' . esc_html( $np_r['aciklama'] ?? '' ) . '</p>'
					. ( $np_u ? '<a class="ar" href="' . esc_url( $np_u ) . '" aria-hidden="true" tabindex="-1">→</a>' : '' ) . '</div>';
			}
			$np_t = (string) $np_f( 'isler_baslik' );
			echo $np_pb( $np_t ? $np_t : $np_ad . ' için en çok yaptığımız işler', $np_o . '</div>' ); // phpcs:ignore
		}
		if ( $np_mah ) {
			echo $np_pb( 'Geldiğimiz mahalleler', '<div class="t-lead c-body rt"><p>' . esc_html( $np_mah ) . '</p></div>' ); // phpcs:ignore
		}
		if ( $np_faq ) {
			echo $np_pb( $np_ad . ' perde siparişi', np_acc( $np_faq ), ' last' ); // phpcs:ignore
		}
	} elseif ( trim( (string) get_the_content() ) ) {
		echo '<div class="detay"><div class="prose">';
		the_content();
		echo '</div></div>';
	}
	?>
</div></section>
<section class="sec bg-mist"><div class="wrap"><?php echo np_head( 'Perde modelleri', 'Ölçüye özel perde modellerimiz', 'Her model ölçüye göre hazırlanır. Kumaş seçeneklerini ve nasıl takıldığını model sayfalarında anlattık.' ) . np_perde_grid( array( 'n' => 8, 'cols' => 4, 'fixed' => true ) ); // phpcs:ignore ?></div></section>
<section class="sec"><div class="wrap"><?php echo np_head( 'Hizmet bölgeleri', "İzmir'in diğer ilçeleri" ) . np_bolge_links( $np_id ); // phpcs:ignore ?></div></section>
	<?php
	echo np_measure_band() . np_final_cta(); // phpcs:ignore
endwhile;
get_footer();
