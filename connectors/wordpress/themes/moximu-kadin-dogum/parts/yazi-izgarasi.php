<?php
if (! defined('ABSPATH')) {
	exit;
}
?>
<section class="bk-bolum bk-blog-liste">
	<div class="kap">
		<?php if (have_posts()) : ?>
			<div class="bk-blog-izgara">
				<?php while (have_posts()) : the_post();
					echo bk_blog_karti(get_post());
				endwhile; ?>
			</div>
			<?php the_posts_pagination(['prev_text' => '‹', 'next_text' => '›', 'class' => 'bk-sayfalama']); ?>
		<?php else : ?>
			<p class="bk-bos">Bu bölümde henüz yazı yok.</p>
		<?php endif; ?>
	</div>
</section>
