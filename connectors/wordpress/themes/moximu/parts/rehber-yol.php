<?php defined('ABSPATH') || exit;
$blog = (int) get_option('page_for_posts');
$blog_url = $blog ? get_permalink($blog) : home_url('/rehber/'); ?>
<nav aria-label="breadcrumb" class="mx lp-bc"><div class="wrap"><a href="<?= esc_url(home_url('/')) ?>">Ana sayfa</a> › <a href="<?= esc_url($blog_url) ?>">Rehber</a> › <span><?php the_title(); ?></span></div></nav>
