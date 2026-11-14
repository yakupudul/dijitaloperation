<?php
/* Tasarımı olmayan sayfa ya da yazı için sade gösterim. */
if (! defined('ABSPATH')) {
	exit;
}
?>
<main id="icerik" class="sec"><div class="wrap">
<?php while (have_posts()) : the_post(); ?>
<article>
<h1><?php the_title(); ?></h1>
<?php the_content(); ?>
</article>
<?php endwhile; ?>
</div></main>
