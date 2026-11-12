<?php
get_header();
?>
<section class="sec" style="--pt:96px;--pb:96px"><div class="wrap"><div class="col" style="--w:70%;--g:22px">
	<div class="t-eyebrow c-plum">Sayfa bulunamadı</div>
	<h1 class="t-h1 c-ink">Aradığınız sayfa burada değil</h1>
	<p class="t-lead c-text">Adres değişmiş ya da sayfa kaldırılmış olabilir. Perde modellerine bakabilir ya da doğrudan bize yazabilirsiniz.</p>
	<div class="btns"><a class="btn btn-dark" href="<?php echo esc_url( np_url( '/perde-modelleri/' ) ); ?>">Perde modelleri</a><?php echo np_wa(); // phpcs:ignore ?></div>
</div></div></section>
<?php
get_footer();
