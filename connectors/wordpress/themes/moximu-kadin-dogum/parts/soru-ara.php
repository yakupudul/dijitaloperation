<?php
if (! defined('ABSPATH')) {
	exit;
}
?>
<form class="bk-soru-ara" role="search" method="get" action="<?php echo esc_url(bk_url('/')); ?>">
	<label class="gizli" for="bk-soru-s-<?php echo esc_attr((string) wp_rand(10, 99999)); ?>">Soru arayın</label>
	<?php echo bk_ikon('ara'); ?>
	<input type="search" name="s" placeholder="Soru arayın..." value="<?php echo esc_attr(get_search_query()); ?>">
	<input type="hidden" name="post_type" value="docs">
</form>
