<?php
if (! defined('ABSPATH')) {
	exit;
}
get_header();
the_post();
$wa_mesaj = 'https://wa.me/905336877853?text=' . rawurlencode('Merhaba, randevu ve muayene hakkında bilgi almak istiyorum.');
?>
<section class="bk-bolum bk-randevu-sayfa">
	<div class="kap bk-randevu-duzen">
		<div class="bk-randevu-form bk-golge">
			<?php bk_kirinti(); ?>
			<h1>Randevu Talebi Oluştur</h1>
			<?php bk_form('randevu'); ?>
		</div>
		<div class="bk-randevu-bilgi bk-golge">
			<p><strong>Muayenehane:</strong><br>Prof. Dr. Burcu Kısa | Kadın Hastalıkları ve Doğum Uzmanı Ankara</p>
			<p><strong>Adres:</strong><br><a href="<?php echo esc_url(bk_ayar('yol_tarifi')); ?>" target="_blank" rel="noopener noreferrer">Mustafa Kemal Mahallesi, Maidan İş Merkezi, C Blok,<br>10. Kat, No:115, Çankaya / Ankara</a></p>
			<p><strong>Çağrı Merkezi:</strong><br><a href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo esc_html(bk_ayar('telefon')); ?></a></p>
			<p><strong>E-posta:</strong><br><a href="mailto:<?php echo esc_attr(bk_ayar('eposta')); ?>"><?php echo esc_html(bk_ayar('eposta')); ?></a></p>
			<p><strong>Muayenehane Telefonu:</strong><br><a href="<?php echo esc_url(bk_ayar('telefon_link')); ?>"><?php echo esc_html(bk_ayar('telefon')); ?></a></p>
			<p><strong>WhatsApp:</strong><br><a href="<?php echo esc_url($wa_mesaj); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(bk_ayar('whatsapp')); ?></a></p>
			<p><strong>Çalışma Gün ve Saatleri:</strong></p>
			<table class="bk-saat-tablo"><thead><tr><th>Gün</th><th>Saat</th></tr></thead><tbody><tr><td>Pazartesi – Cumartesi</td><td>08:00 – 20:00</td></tr></tbody></table>
		</div>
	</div>
</section>
<?php
get_footer();
