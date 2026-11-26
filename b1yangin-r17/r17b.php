<?php
/* One-off: vehicle pages to draft (their data now lives on the category pages). Previous status kept in b1_r17b_done. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
add_action( 'init', function () {
	if ( get_option( 'b1_r17b_done' ) ) {
		return;
	}
	$ids = array( 413, 414, 160, 164, 418, 417, 415, 416, 162, 166, 168, 169, 451, 452, 438, 444, 456, 455, 453, 454, 442, 446, 448, 449 );
	$rep = array();
	foreach ( $ids as $id ) {
		$p = get_post( $id );
		if ( ! $p || 'product' !== $p->post_type ) {
			$rep[ $id ] = 'skip';
			continue;
		}
		$rep[ $id ] = $p->post_status;
		if ( 'publish' === $p->post_status ) {
			$r = wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $r ) ) {
				$rep[ $id ] .= ' err ' . $r->get_error_message();
			}
		}
	}
	update_option( 'b1_r17b_done', $rep, false );
}, 50 );
