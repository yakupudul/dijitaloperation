<?php defined('ABSPATH') || exit; ?>
<section class="mx lp-relsec"><div class="wrap"><h1><?php echo esc_html(wp_strip_all_tags(get_the_archive_title() ?: get_bloginfo('name'))); ?></h1>
<div class="lp-rel">
<?php while (have_posts()) : the_post(); ?>
<a href="<?php the_permalink(); ?>"><b><?php the_title(); ?></b><span><?php echo esc_html(wp_trim_words(get_the_excerpt(), 22)); ?></span></a>
<?php endwhile; ?>
</div>
<?php the_posts_pagination(['mid_size' => 1]); ?>
</div></section>
