<?php
/**
 * İlk içerik: tema, onaylanan sayfa gövdelerini, perde ve ilçe sayfalarının alanlarını ve rehber yazılarını
 * seed/icerik.json içinde taşır. seed/rev.txt değiştiğinde bir kez çalışır. Panelden elle değiştirilmiş bir
 * içeriğin üzerine yazmaz (yazdığı her şeyin özetini saklar, özet tutmuyorsa dokunmaz).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'np_seed_run', 99 );

function np_seed_hash( $v ) {
	return md5( (string) wp_json_encode( $v ) );
}

/** Alanların ham (kayıtlı) değerleri. */
function np_seed_fields_now( $id, $names ) {
	$out = array();
	foreach ( $names as $n ) {
		$out[ $n ] = get_field( $n, $id, false );
	}
	return $out;
}

function np_seed_run() {
	if ( ! function_exists( 'update_field' ) || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$rev = trim( (string) @file_get_contents( get_template_directory() . '/seed/rev.txt' ) ); // phpcs:ignore
	if ( '' === $rev || get_option( 'np_seed_rev' ) === $rev || get_transient( 'np_seed_lock' ) ) {
		return;
	}
	set_transient( 'np_seed_lock', 1, 60 );
	$t0    = microtime( true );
	$state = get_option( 'np_seed_state' );
	if ( ! is_array( $state ) || ( $state['rev'] ?? '' ) !== $rev ) {
		$state = array( 'rev' => $rev, 'done' => array(), 'log' => array() );
	}
	$data = json_decode( (string) file_get_contents( get_template_directory() . '/seed/icerik.json' ), true ); // phpcs:ignore
	if ( ! is_array( $data ) ) {
		delete_transient( 'np_seed_lock' );
		return;
	}
	kses_remove_filters(); // Gövdeler temanın kendi HTML'i; süzgeç style ve details özniteliklerini silmesin.
	$late = function () use ( $t0 ) {
		return microtime( true ) - $t0 > 15;
	};
	$finished = true;
	$keys     = array(
		'perde' => array( 'bolumler' => 'field_np_p_bolumler', 'secenekler' => 'field_np_p_secenekler', 'montaj' => 'field_np_p_montaj', 'sorular' => 'field_np_p_sorular' ),
		'bolge' => array( 'isler_baslik' => 'field_np_b_isler_baslik', 'isler' => 'field_np_b_isler', 'mahalle_metni' => 'field_np_b_mahalle', 'sorular' => 'field_np_b_sorular' ),
	);

	// 1) Sayfa gövdeleri.
	foreach ( (array) ( $data['pages'] ?? array() ) as $path => $html ) {
		$k = 'page:' . $path;
		if ( ! empty( $state['done'][ $k ] ) ) {
			continue;
		}
		if ( $late() ) {
			$finished = false;
			break;
		}
		$p = '' === $path ? get_post( (int) get_option( 'page_on_front' ) ) : get_page_by_path( $path );
		if ( $p ) {
			$stored = (string) get_post_meta( $p->ID, '_np_seed_hash', true );
			if ( '' === $stored || md5( $p->post_content ) === $stored ) {
				wp_update_post( array( 'ID' => $p->ID, 'post_content' => wp_slash( $html ) ) );
				update_post_meta( $p->ID, '_np_seed_hash', md5( get_post( $p->ID )->post_content ) );
				delete_post_meta( $p->ID, '_wp_page_template' );
				$state['log'][] = 'sayfa: /' . $path;
			} else {
				$state['log'][] = 'sayfa elle değişmiş, dokunulmadı: /' . $path;
			}
		} else {
			$state['log'][] = 'sayfa yok: /' . $path;
		}
		$state['done'][ $k ] = 1;
	}

	// 2) Perde ve ilçe sayfalarının alanları.
	foreach ( array( 'perde', 'bolge' ) as $type ) {
		foreach ( (array) ( $data[ $type ] ?? array() ) as $slug => $fields ) {
			$k = $type . ':' . $slug;
			if ( ! empty( $state['done'][ $k ] ) || ! $finished ) {
				continue;
			}
			if ( $late() ) {
				$finished = false;
				break;
			}
			$p = get_page_by_path( $slug, OBJECT, $type );
			if ( $p ) {
				$names  = array_keys( $keys[ $type ] );
				$stored = (string) get_post_meta( $p->ID, '_np_seed_hash', true );
				if ( '' === $stored || np_seed_hash( np_seed_fields_now( $p->ID, $names ) ) === $stored ) {
					foreach ( $fields as $name => $value ) {
						if ( 'isler' === $name ) {
							foreach ( $value as $i => $row ) {
								$pp                   = get_page_by_path( (string) $row['perde'], OBJECT, 'perde' );
								$value[ $i ]['perde'] = $pp ? $pp->ID : '';
							}
						}
						if ( isset( $keys[ $type ][ $name ] ) ) {
							update_field( $keys[ $type ][ $name ], $value, $p->ID );
						}
					}
					update_post_meta( $p->ID, '_np_seed_hash', np_seed_hash( np_seed_fields_now( $p->ID, $names ) ) );
					$state['log'][] = $type . ': ' . $slug;
				} else {
					$state['log'][] = $type . ' elle değişmiş, dokunulmadı: ' . $slug;
				}
			} else {
				$state['log'][] = $type . ' yok: ' . $slug;
			}
			$state['done'][ $k ] = 1;
		}
	}

	// 3) Rehber yazıları: yoksa oluşturulur, varsa yalnızca elle değişmemişse güncellenir.
	if ( $finished ) {
		$cat = get_term_by( 'name', 'Perde Rehberi', 'category' );
		if ( ! $cat ) {
			$new = wp_insert_term( 'Perde Rehberi', 'category' );
			$cat = is_wp_error( $new ) ? null : get_term( $new['term_id'], 'category' );
		}
		foreach ( (array) ( $data['posts'] ?? array() ) as $i => $row ) {
			$k = 'post:' . $row['slug'];
			if ( ! empty( $state['done'][ $k ] ) ) {
				continue;
			}
			if ( $late() ) {
				$finished = false;
				break;
			}
			$found = get_posts( array( 'name' => $row['slug'], 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1 ) );
			$id    = $found ? $found[0]->ID : 0;
			if ( ! $id ) {
				// Sıra bozulmasın diye yazılar birer dakika arayla tarihlenir (ilk yazı en yeni).
				$date = gmdate( 'Y-m-d H:i:s', time() - 60 * ( $i + 1 ) );
				$id   = (int) wp_insert_post( array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => $row['title'],
					'post_name'     => $row['slug'],
					'post_excerpt'  => $row['seo_desc'],
					'post_content'  => wp_slash( $row['html'] ),
					'post_date_gmt' => $date,
					'post_date'     => get_date_from_gmt( $date ),
					'post_category' => $cat ? array( (int) $cat->term_id ) : array(),
				) );
				if ( $id ) {
					update_post_meta( $id, '_np_seed_hash', md5( get_post( $id )->post_content ) );
					update_post_meta( $id, '_seopress_titles_title', $row['seo_title'] );
					update_post_meta( $id, '_seopress_titles_desc', $row['seo_desc'] );
					$img = np_media_id( $row['img'] );
					if ( $img ) {
						set_post_thumbnail( $id, $img );
					}
					$state['log'][] = 'yazı oluşturuldu: ' . $row['slug'];
				}
			} else {
				$stored = (string) get_post_meta( $id, '_np_seed_hash', true );
				if ( '' !== $stored && md5( get_post( $id )->post_content ) === $stored ) {
					wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $row['html'] ) ) );
					update_post_meta( $id, '_np_seed_hash', md5( get_post( $id )->post_content ) );
					$state['log'][] = 'yazı güncellendi: ' . $row['slug'];
				}
			}
			if ( $id && ! empty( $row['faq'] ) ) {
				update_post_meta( $id, '_np_faq', wp_slash( wp_json_encode( $row['faq'], JSON_UNESCAPED_UNICODE ) ) );
			}
			$state['done'][ $k ] = 1;
		}
	}

	kses_init();
	update_option( 'np_seed_state', $state, false );
	if ( $finished ) {
		update_option( 'np_seed_rev', $rev, false );
		do_action( 'litespeed_purge_all' );
	}
	delete_transient( 'np_seed_lock' );
}
