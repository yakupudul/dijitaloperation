<?php
/**
 * Sayfa: gövde hazır bölümlerden oluşur, olduğu gibi basılır.
 */
get_header();
while ( have_posts() ) {
	the_post();
	the_content();
}
get_footer();
