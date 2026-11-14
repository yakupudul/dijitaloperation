<?php
if (! defined('ABSPATH')) {
	exit;
}
$randevu = bk_url('/randevu-olustur/');
?>
</main>
<footer class="bk-altbilgi">
	<section class="bk-alt-cta">
		<div class="kap">
			<div class="bk-alt-cta-sol">
				<p class="bk-alt-ust-etiket">ANKARA · ÇANKAYA · MAİDAN</p>
				<h2>Sağlığınız için birlikte planlayalım.</h2>
				<p>Muayene, değerlendirme ve takip için bizimle iletişime geçin. Size uygun randevu zamanını birlikte belirleyelim.</p>
			</div>
			<div class="bk-alt-cta-sag">
				<h3>Sizin için uygun bir zaman bulalım.</h3>
				<div class="bk-alt-cta-btn">
					<a class="bk-alt-btn acik" href="<?php echo esc_url($randevu); ?>">Randevu talebi oluştur</a>
					<a class="bk-alt-btn yesil" href="<?php echo esc_url(bk_ayar('whatsapp_link')); ?>" target="_blank" rel="noopener">WhatsApp ile yazın</a>
				</div>
				<p class="bk-alt-tel">Telefonla ulaşın: <a href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo esc_html(bk_ayar('telefon')); ?></a></p>
				<p class="bk-alt-saat">Pazartesi – Cumartesi · 08:00 – 20:00</p>
			</div>
		</div>
	</section>
	<section class="bk-alt-bilgi">
		<div class="kap">
			<div class="bk-alt-izgara">
				<div class="bk-alt-marka">
					<a href="<?php echo esc_url(bk_url('/')); ?>" class="bk-alt-logo"><?php echo bk_gorsel('2026/09/logo-q-1.jpeg', 'medium_large', ['alt' => bk_ayar('hekim') . ' – ' . bk_ayar('unvan')]); ?></a>
					<p>Ankara Çankaya'daki Maidan İş Merkezi'nde kadın hastalıkları ve doğum alanında muayene, değerlendirme ve takip.</p>
					<a class="bk-alt-ig" href="<?php echo esc_url(bk_ayar('instagram')); ?>" target="_blank" rel="noopener">Instagram ↗</a>
				</div>
				<nav class="bk-alt-linkler" aria-label="Muayenehanemizi tanıyın">
					<h3>Muayenehanemizi tanıyın</h3>
					<ul>
						<li><a href="<?php echo esc_url($randevu); ?>">Randevu Oluştur</a></li>
						<li><a href="<?php echo esc_url(bk_url('/hakkimda/')); ?>">Hakkımda</a></li>
						<li><a href="<?php echo esc_url(bk_url('/hizmetlerim/')); ?>">Hizmetlerim</a></li>
						<li><a href="<?php echo esc_url(bk_url('/blog-ankara/')); ?>">Blog</a></li>
						<li><a href="<?php echo esc_url(bk_url('/iletisim-ankara/')); ?>">İletişim</a></li>
					</ul>
				</nav>
				<address class="bk-alt-kart">
					<span class="bk-alt-kart-etiket">MAİDAN İŞ MERKEZİ</span>
					<strong class="bk-alt-kart-ad"><?php echo esc_html(bk_ayar('hekim')); ?></strong>
					<span class="bk-alt-kart-unvan"><?php echo esc_html(bk_ayar('unvan')); ?></span>
					<span class="bk-alt-kart-adres"><?php echo esc_html(bk_ayar('adres_satir1')); ?><br><?php echo esc_html(bk_ayar('adres_satir2')); ?></span>
					<a href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo esc_html(bk_ayar('telefon')); ?></a>
					<a href="mailto:<?php echo esc_attr(bk_ayar('eposta')); ?>"><?php echo esc_html(bk_ayar('eposta')); ?></a>
					<span class="bk-alt-kart-saat"><?php echo esc_html(bk_ayar('saat_gun')); ?><b><?php echo esc_html(bk_ayar('saat_aralik')); ?></b></span>
					<a class="bk-alt-yol" href="<?php echo esc_url(bk_ayar('yol_tarifi')); ?>" target="_blank" rel="noopener">Yol tarifi alın ↗</a>
				</address>
			</div>
			<div class="bk-alt-hizmetler">
				<h2>Kadın sağlığı hizmetlerimiz</h2>
				<p>İhtiyacınız olan bilgiye, ilgili hizmet başlığından ulaşın.</p>
				<?php bk_alt_hizmetler(); ?>
			</div>
		</div>
	</section>
	<div class="bk-alt-son">
		<div class="kap">
			<span>© <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html(bk_ayar('hekim')); ?>. Tüm hakları saklıdır.</span>
			<span>Bu sitedeki bilgiler genel bilgilendirme amaçlıdır; kişisel değerlendirme için hekiminize başvurun.</span>
			<span>Made by <a href="https://www.moximu.com/" target="_blank" rel="noopener">Moximu</a></span>
		</div>
	</div>
</footer>
<div class="bk-yapiskan" aria-label="Hızlı iletişim">
	<a class="yesil" href="<?php echo esc_url(bk_ayar('whatsapp_link')); ?>" target="_blank" rel="noopener"><?php echo bk_ikon('wa'); ?> WhatsApp</a>
	<a class="koyu" href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo bk_ikon('tel'); ?> Hemen ara</a>
</div>
<?php wp_footer(); ?>
</body>
</html>
