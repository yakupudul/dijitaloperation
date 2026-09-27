<?php

namespace App\Services\CommandCenter;

/**
 * Komuta merkezi topics: the kind of problem an item is, independent of which account it is on.
 *
 * A topic key is "source:rule" (alert kind, advisor / SEO rule id, coverage type…). Every account with the same
 * problem sits under one topic ("Bütçe / bakiye bitti" once, with its accounts listed), so the operator reads one
 * explanation and acts on all of them together. Keys ending with "*" group a rule family ("seo:create-*"). A rule
 * that is not listed still gets its own topic, labelled by its first item's title under the source's defaults.
 *
 * Priority groups: `urgent` (Acil), `week` (Bu hafta), `opportunity` (Fırsatlar). Areas feed the top filter.
 */
final class TopicCatalog
{
    public const array GROUPS = ['urgent' => 'Acil', 'week' => 'Bu hafta', 'opportunity' => 'Fırsatlar'];

    public const string AGED_GROUP = 'Uzun süredir devam eden';

    public const array AREAS = ['ads' => 'Reklam', 'seo' => 'SEO', 'site' => 'Site', 'client' => 'Müşteri & ajans', 'system' => 'Sistem'];

    /** Area and group of a source when its rule is not in the catalog. */
    private const array SOURCE_DEFAULTS = [
        'alert' => ['ads', 'week'], 'advisor' => ['ads', 'week'], 'seo' => ['seo', 'week'], 'site_fix' => ['site', 'week'],
        'brain' => ['seo', 'opportunity'], 'compliance' => ['site', 'urgent'], 'lead' => ['client', 'urgent'], 'system' => ['system', 'urgent'],
        'approval' => ['system', 'week'], 'coverage' => ['system', 'week'], 'calendar' => ['client', 'week'], 'client_approval' => ['client', 'week'],
        'followup' => ['client', 'urgent'], 'invoice' => ['client', 'urgent'], 'commitment' => ['client', 'week'], 'task' => ['client', 'week'],
        'live' => ['system', 'urgent'], 'data' => ['system', 'week'], 'lead_outcome' => ['client', 'week'], 'gbp' => ['seo', 'week'],
    ];

    /** Areas of alert kinds (alerts sit on every channel). */
    private const array ALERT_AREAS = [
        'search_traffic_drop' => 'seo', 'gsc_stale' => 'seo', 'site_down' => 'site', 'wordpress_plugin_outdated' => 'site',
        'ga4_sessions_drop' => 'site', 'ga4_conversions_drop' => 'site', 'ga4_stale' => 'system', 'stale_data' => 'system', 'renewal_due' => 'client',
    ];

    /**
     * @var array<string, array{0: string, 1: string, 2: string}> key => [label, group, explanation]
     */
    private const array TOPICS = [
        // Uyarılar — ad accounts
        'alert:budget_exhausted' => ['Bütçe / bakiye bitti', 'urgent', 'Hesabın harcama limiti ya da ön ödemeli bakiyesi bitti; reklamlar şu an yayında değil ve her gün kayıp büyüyor. Müşteriye haber verip limiti artırmasını veya bakiye yüklemesini isteyin. Müşteri reklamı bilerek durdurduysa "Hesap duraklatıldı" ile işaretleyin; uyarı susar.'],
        'alert:budget_low' => ['Bütçe bitmek üzere', 'urgent', 'Kalan limit veya bakiye birkaç günlük harcamaya yetiyor. Bitmeden müşteriye haber verin; reklamların kesilmesi öğrenme dönemini de sıfırlar.'],
        'alert:budget_account_blocked' => ['Hesap reklam yayınlayamıyor', 'urgent', 'Ödeme yöntemi, politika veya hesap durumu yüzünden hesap reklam yayınlayamıyor. Reklam panelinde nedeni okuyun; ödeme sorunuysa müşteriye, politika sorunuysa itiraza yönlendirin.'],
        'alert:budget_no_spend_today' => ['Bugün hiç harcama yok', 'urgent', 'Normalde her gün harcayan hesap bugün öğleden sonra hâlâ sıfırda. Bakiye, ödeme yöntemi veya kampanya bütçesi bitmiş olabilir; reklam panelinden kontrol edin.'],
        'alert:budget_campaign_capped' => ['Kampanya bütçesi gün bitmeden doldu', 'week', 'Bazı kampanyaların günlük bütçesi öğleden önce doluyor; günün kalanında reklam gösterilmiyor. Kampanya kârlıysa bütçeyi artırmayı, değilse teklifleri düşürmeyi önerin.'],
        'alert:ads_disapproved' => ['Reddedilen reklamlar', 'urgent', 'Reklamlar politika nedeniyle reddedildi veya sınırlandı; yayınlanmıyorlar. Reklam panelinde red nedenini okuyup metni düzeltin ya da itiraz edin.'],
        'alert:delivery_stopped' => ['Reklamlar harcama yapmıyor', 'urgent', 'Düzenli harcayan hesap son günlerde hiç harcamadı. Kampanyalar durdurulmuş, bütçe bitmiş veya hesap kısıtlanmış olabilir. Müşteri durdurduysa hesabı duraklatıldı olarak işaretleyin.'],
        'alert:spend_spike' => ['Harcama sıçradı', 'urgent', 'Günlük harcama olağan seviyenin çok üstünde. Yanlış bütçe değişikliği, otomatik teklif sapması veya bot trafiği olabilir; aynı gün kontrol edin.'],
        'alert:conversions_stopped' => ['Dönüşüm gelmiyor', 'urgent', 'Reklam harcıyor ama birkaç gündür hiç dönüşüm kaydı yok. Çoğu zaman takip kodu, form veya telefon bağlantısı bozulmuştur; önce ölçümü, sonra kampanyayı kontrol edin.'],
        'alert:bad_review_unanswered' => ['Yanıtsız düşük puanlı yorum', 'urgent', 'İşletme Profili\'nde düşük puanlı bir yorum yanıt bekliyor. Hızlı ve sakin bir yanıt yeni müşterilerin güvenini korur; İşletme Profili ekranından yanıtlayın.'],
        // Uyarılar — site / data
        'alert:site_down' => ['Site erişilemiyor', 'urgent', 'Site yanıt vermiyor; ziyaretçiler ve reklam tıklamaları boşa gidiyor. Barındırma ve alan adını hemen kontrol edin; reklamları geçici durdurmayı düşünün.'],
        'alert:search_traffic_drop' => ['Arama tıklamaları düştü', 'week', 'Google aramasından gelen tıklamalar önceki döneme göre belirgin düştü. Search Console\'da hangi sayfa ve sorguların düştüğüne bakın; yakın zamanda yapılan site değişikliklerini kontrol edin.'],
        'alert:ga4_sessions_drop' => ['Site ziyaretleri düştü', 'week', 'GA4\'teki oturumlar önceki döneme göre belirgin düştü. Kanal kırılımına bakın: tek kanalsa o kanalı, hepsiyse takip kodunu kontrol edin.'],
        'alert:ga4_conversions_drop' => ['Site dönüşümleri düştü', 'week', 'GA4 anahtar olayları düştü. Formun, telefon tıklamasının ve etiketlerin çalıştığını doğrulayın.'],
        'alert:stale_data' => ['Veri güncel değil', 'week', 'Bu hesabın verisi birkaç gündür yenilenmedi; raporlar ve öneriler eski veriye dayanıyor. Bağlantıyı ve toplama durumunu Veri merkezi\'nden kontrol edin.'],
        'alert:ga4_stale' => ['GA4 verisi güncel değil', 'week', 'GA4 verisi birkaç gündür gelmiyor. Erişimin sürdüğünü ve mülkün doğru bağlandığını kontrol edin.'],
        'alert:gsc_stale' => ['Search Console verisi güncel değil', 'week', 'Search Console verisi birkaç gündür gelmiyor. Erişimin sürdüğünü ve mülkün doğru bağlandığını kontrol edin.'],
        'alert:wordpress_plugin_outdated' => ['WordPress eklentisi eski', 'week', 'Sitedeki MoxDOP eklentisi güncel değil; bazı düzeltmeler ve güvenli güncellemeler çalışmayabilir. Site ekranından eklentiyi güncelleyin.'],
        'alert:renewal_due' => ['Yenileme yaklaşıyor', 'urgent', 'Alan adı, barındırma veya lisans yenilemesi yaklaştı ya da geçti. Süresi dolan alan adı siteyi ve e-postayı durdurur; yenilemeyi planlayın.'],

        // Danışman — Google Ads
        'advisor:negative-keywords' => ['Negatif kelime önerisi', 'week', 'Para harcayan ama işe yaramayan arama terimleri bulundu. Listeyi gözden geçirip negatif olarak ekleyin; Google Ads Editor dosyası ile toplu yükleyebilirsiniz.'],
        'advisor:ngram-waste' => ['Boşa harcayan kelime kalıpları', 'week', 'Birçok arama teriminde tekrar eden ve dönüşüm getirmeyen kelime kalıpları var. Kalıbı negatif ekleyerek bütçeyi korurun.'],
        'advisor:budget-waste' => ['Boşa harcanan bütçe', 'week', 'Harcama yapan ama sonuç getirmeyen kampanya veya reklam grupları var. Bütçeyi kısın ya da hedeflemeyi daraltın.'],
        'advisor:negative-keyword-conflict' => ['Negatif kelime çakışması', 'week', 'Eklenmiş bir negatif kelime kendi anahtar kelimelerinizi engelliyor. Çakışan negatifi kaldırın.'],
        'advisor:low-quality-score' => ['Düşük kalite puanı', 'week', 'Kalite puanı düşük anahtar kelimeler tıklama başına daha pahalıya geliyor. Reklam metnini ve açılış sayfasını kelimeye yaklaştırın.'],
        'advisor:quality-score-drop' => ['Kalite puanı düştü', 'week', 'Bazı anahtar kelimelerin kalite puanı düştü. Son reklam veya sayfa değişikliklerini kontrol edin.'],
        'advisor:weak-ad-strength' => ['Zayıf reklam metni', 'week', 'Reklam gücü zayıf; Google reklamı daha az gösteriyor. AI taslak ile yeni başlık ve açıklamalar hazırlayıp ekleyin.'],
        'advisor:missing-assets' => ['Eksik reklam öğeleri', 'week', 'Site bağlantısı, açıklama metni veya arama öğesi eksik. Eklemek reklamın kapladığı alanı ve tıklama oranını artırır.'],
        'advisor:no-primary-conversion' => ['Birincil dönüşüm tanımlı değil', 'urgent', 'Harcama ölçülmüyor: hesapta birincil dönüşüm işlemi yok. Akıllı teklif ve raporlar bu olmadan kördür; dönüşüm işlemini tanımlayın.'],
        'advisor:primary-no-signal' => ['Dönüşüm sinyali gelmiyor', 'urgent', 'Birincil dönüşüm tanımlı ama sinyal gelmiyor. Etiketin sitede çalıştığını ve doğru olayın seçildiğini kontrol edin.'],
        'advisor:auto-tagging-off' => ['Otomatik etiketleme kapalı', 'urgent', 'Otomatik etiketleme (gclid) kapalı; GA4 reklam trafiğini tanıyamıyor. Hesap ayarlarından açın.'],
        'advisor:conversion-settings' => ['Dönüşüm ayarları hatalı', 'week', 'Sayım yöntemi, değer veya dönüşüm penceresi hatalı görünüyor. Ayarı düzeltmek teklif stratejisinin doğru öğrenmesini sağlar.'],
        'advisor:ga4-mismatch' => ['Google Ads ile GA4 tutmuyor', 'week', 'Google Ads ve GA4 aynı dönem için çok farklı sayılar gösteriyor. Bağlantıyı, içe aktarılan olayları ve sayım ayarını kontrol edin.'],
        'advisor:landing-page-issues' => ['Açılış sayfası sorunları', 'week', 'Reklamların gittiği sayfalarda hız, hata veya içerik sorunu var. Sayfa düzelmeden reklam bütçesi boşa gider.'],
        'advisor:landing-keyword-mismatch' => ['Kelime ile sayfa uyumsuz', 'week', 'Anahtar kelimeler, gittikleri sayfanın konusuyla örtüşmüyor. Daha uygun sayfaya yönlendirin veya sayfayı güçlendirin.'],
        'advisor:budget-limited-profitable' => ['Kârlı ama bütçesi kısıtlı', 'opportunity', 'Kârlı çalışan kampanyalar bütçe yüzünden gösterim kaçırıyor. Bütçeyi artırmak en hızlı büyüme yoludur; müşteriye önerin.'],
        'advisor:keyword-opportunities' => ['Yeni anahtar kelime fırsatı', 'opportunity', 'Arama terimlerinde dönüşüm getiren ama anahtar kelime olarak eklenmemiş sorgular var. Ekleyerek kontrolü artırın.'],
        'advisor:segment-bid-adjustment' => ['Cihaz / saat teklif ayarı', 'opportunity', 'Bazı cihaz, saat veya konumlarda sonuçlar belirgin farklı. Teklif ayarı ile bütçeyi iyi segmente kaydırın.'],
        'advisor:google-recommendations' => ['Google önerileri', 'opportunity', 'Google\'ın hesaba özel önerileri var. Otomatik uygulamadan önce hesabın hedefiyle uyumunu kontrol edin.'],
        'advisor:service-terms-not-converting' => ['Dönüşmeyen hizmet aramaları', 'week', 'Hizmet aramalarından tıklama geliyor ama dönüşüm yok. Sayfayı, teklifi ve eşleme türünü gözden geçirin.'],
        'advisor:performance-anomaly' => ['Performans anomalisi', 'week', 'Harcama, tıklama veya dönüşümde olağan dışı bir değişim var. Nedenini (değişiklik, sezon, rakip) bulun.'],
        'advisor:daily-anomaly' => ['Günlük anomali', 'week', 'Bir gün olağan dışı sonuç verdi. Tek seferlik mi, yeni bir eğilim mi kontrol edin.'],
        'advisor:change-impact' => ['Değişikliğin etkisi', 'week', 'Hesapta yapılan bir değişiklik sonrası sonuçlar değişti. Değişikliği sürdürmek veya geri almak için etkisine bakın.'],
        // Danışman — Meta Ads
        'advisor:creative-fatigue' => ['Kreatif yoruldu', 'week', 'Aynı görseller kitleye çok gösterildi; tıklama düştü, maliyet arttı. AI taslak ile yeni kreatif fikirleri hazırlayıp değiştirin.'],
        'advisor:audience-saturation' => ['Kitle doydu', 'week', 'Kitlenin büyük kısmı reklamı gördü; frekans yükseldi. Kitleyi genişletin veya benzer kitle ekleyin.'],
        'advisor:learning-limited' => ['Öğrenme sınırlı', 'week', 'Reklam setleri öğrenme aşamasını geçemiyor. Setleri birleştirin veya daha sık olan bir optimizasyon olayı seçin.'],
        'advisor:pixel-health' => ['Pixel / ölçüm sorunlu', 'urgent', 'Meta Pixel veya dönüşüm API olayları eksik ya da hatalı. Ölçüm düzelmeden optimizasyon doğru çalışmaz.'],
        'advisor:spend-no-results' => ['Harcıyor ama sonuç yok', 'urgent', 'Reklam seti harcıyor ama hiç sonuç getirmiyor. Hedefi, kreatifi ve açılış sayfasını kontrol edin; gerekirse durdurun.'],
        'advisor:delivery-outliers' => ['Dağıtım sapması', 'week', 'Bazı reklamlar bütçeyi orantısız harcıyor. Performansı düşük olanları durdurun.'],
        // Danışman — İşletme Profili
        'advisor:profile-gaps' => ['Profil eksikleri', 'week', 'İşletme Profili\'nde açıklama, kategori, saat veya hizmet bilgisi eksik. Eksiksiz profil haritada daha çok görünür.'],
        'advisor:photo-freshness' => ['Fotoğraflar eski', 'opportunity', 'Profile uzun süredir yeni fotoğraf eklenmedi. Güncel fotoğraflar etkileşimi artırır.'],
        'advisor:rating-trend' => ['Puan düşüyor', 'week', 'Son yorumların puan ortalaması düşüyor. Şikâyetleri okuyun ve yanıtlayın; memnun müşterilerden yorum isteyin.'],
        'advisor:profile-actions-drop' => ['Profil etkileşimi düştü', 'week', 'Arama, yol tarifi veya web sitesi tıklamaları düştü. Profil bilgilerini ve rakipleri kontrol edin.'],
        'advisor:profile-closed' => ['Profil kapalı görünüyor', 'urgent', 'Profil kapalı veya askıda görünüyor; müşteriler işletmeyi bulamıyor. Hemen kontrol edin.'],
        'advisor:keyword-service-gaps' => ['Profilde eksik hizmetler', 'opportunity', 'İnsanların aradığı hizmetler profilde yer almıyor. Hizmet listesine ekleyin.'],
        'advisor:site-profile-services' => ['Site ile profil hizmetleri uyuşmuyor', 'week', 'Sitedeki hizmetlerle profildeki hizmetler farklı. İkisini eşitleyin.'],
        'advisor:website-utm' => ['Profil linkinde UTM yok', 'week', 'Profildeki site linkinde UTM yok; profilden gelen ziyaretler GA4\'te ayrışmıyor. Linke UTM ekleyin.'],
        // Danışman — kanallar arası
        'advisor:ads-landing-offsite' => ['Reklam başka siteye gidiyor', 'week', 'Reklamlar markanın sitesi dışındaki bir adrese gidiyor. Doğru açılış sayfasını kontrol edin.'],
        'advisor:ads-term-no-organic-page' => ['Reklam aramasının sitede sayfası yok', 'opportunity', 'Reklamla alınan aramalar için sitede organik sayfa yok. Sayfa yazmak uzun vadede reklam maliyetini düşürür.'],
        'advisor:budget-shift' => ['Bütçeyi kanal değiştir', 'opportunity', 'Bir kanal diğerinden belirgin daha verimli. Bütçenin bir kısmını kaydırmayı müşteriye önerin.'],
        'advisor:gbp-search-no-site-content' => ['Profil aramasının sitede karşılığı yok', 'opportunity', 'Profilde aranan konular sitede işlenmemiş. İlgili içerik veya sayfa ekleyin.'],
        'advisor:gbp-website-mismatch' => ['Profil ile site bilgisi farklı', 'week', 'Profildeki site adresi veya bilgiler siteyle uyuşmuyor. Tutarsızlığı giderin.'],
        'advisor:meta-destination-offsite' => ['Meta reklamı başka siteye gidiyor', 'week', 'Meta reklamları markanın sitesi dışındaki bir adrese gidiyor. Hedef bağlantıyı kontrol edin.'],
        'advisor:nap-phone-mismatch' => ['Telefon bilgisi tutarsız', 'week', 'Site, profil ve reklamlardaki telefon numaraları farklı. Tek numarada birleştirin.'],
        'advisor:paid-brand-search' => ['Marka aramasına reklam ödeniyor', 'opportunity', 'Marka adıyla yapılan aramalara ciddi bütçe gidiyor. Organik sonuç güçlüyse bu bütçeyi azaltmayı değerlendirin.'],
        'advisor:season-ahead' => ['Sezon yaklaşıyor', 'opportunity', 'Geçen yıl bu dönemde talep yükselmişti. Bütçe ve içerik hazırlığını şimdiden yapın.'],

        // SEO görevleri
        'seo:service-page-mapping' => ['Sayfası olmayan hizmet', 'week', 'Markanın öncelikli hizmetlerinden bazılarının sitede eşleşen sayfası yok. Mevcut bir sayfayı eşleştirin ya da yeni sayfa yazdırın; eşleşmeyen hizmet aramada görünmez.'],
        'seo:create-*' => ['Yeni sayfa / içerik yaz', 'opportunity', 'Aranan ama sitede karşılığı olmayan hizmet, konum veya rehber içerikleri. Her görevde yazara verilecek brief hazır; WordPress\'e taslak olarak gönderilebilir.'],
        'seo:strengthen-page' => ['Sayfayı güçlendir', 'opportunity', 'İlk sayfaya yakın ama yeterince tıklama almayan sayfalar. Başlığı, içeriği ve iç linkleri güçlendirmek en hızlı SEO kazancıdır.'],
        'seo:content-decay' => ['Trafiği düşen içerik', 'week', 'Eskiden trafik alan içerikler geriliyor. Güncelleyin, eksik bölümleri ekleyin ve tarihini yenileyin.'],
        'seo:service-internal-links' => ['İç link eksik', 'opportunity', 'Hizmet sayfalarına siteden yeterince iç link gitmiyor. İlgili yazılardan link vermek sayfanın gücünü artırır.'],
        'seo:site-noindex' => ['Site dizine kapalı', 'urgent', 'Site veya önemli sayfalar noindex ile Google\'a kapatılmış. Bilinçli değilse hemen kaldırın.'],
        'seo:index-important-pages' => ['Önemli sayfa dizinde değil', 'urgent', 'Hizmet sayfaları Google dizininde değil. Search Console\'dan inceleyip dizine eklenme isteyin.'],
        'seo:server-error' => ['Sunucu hatası veren sayfalar', 'urgent', 'Bu sayfalar 5xx hata döndürüyor; Google dizinden düşürür. Sunucu kaydına bakıp hatayı giderin.'],
        'seo:title-missing' => ['Başlığı olmayan sayfalar', 'week', 'Title etiketi yok; arama sonucunda rastgele bir metin görünür. Hazır site düzeltmeleriyle tek tıkla eklenebilir.'],
        'seo:meta-missing' => ['Meta açıklaması olmayan sayfalar', 'week', 'Meta açıklaması boş; tıklama oranı düşer. Hazır site düzeltmeleriyle tek tıkla eklenebilir.'],
        'seo:h1-missing' => ['H1 başlığı olmayan sayfalar', 'week', 'Sayfada H1 yok; Google sayfanın konusunu anlamakta zorlanır.'],
        'seo:duplicate-h1' => ['Birden fazla H1 olan sayfalar', 'week', 'Sayfa başına birden fazla H1 var; ana konu sinyali bölünüyor. Şablonu düzeltin.'],
        'seo:redirect-chain' => ['Yönlendirme zinciri', 'week', 'Sayfalara iki veya daha fazla yönlendirmeyle ulaşılıyor. İç linkleri son adrese çevirin.'],
        'seo:canonical-conflict' => ['Canonical çelişkisi', 'week', 'Canonical etiketi sayfanın kendi adresini göstermiyor. Düzeltmezseniz Google yanlış sayfayı dizine alır.'],
        'seo:canonical-rejected' => ['Google canonical\'ı reddetti', 'week', 'Google belirttiğiniz canonical yerine başka bir sayfa seçti. Yinelenen içeriği ve iç linkleri kontrol edin.'],
        'seo:thin-content' => ['İnce içerikli sayfalar', 'week', 'Çok kısa sayfalar dizinde yer tutar ama sıralanmaz. Genişletin veya birleştirin.'],
        'seo:crawl-issue' => ['Kırık link / tarama hatası', 'week', 'Taramada kırık iç link veya kritik hata bulundu. Linkleri düzeltin veya kaldırın.'],
        'seo:alt-missing' => ['Alt metni olmayan görseller', 'week', 'Hizmet sayfalarındaki görsellerde alt metni yok. Hazır site düzeltmeleriyle tek tıkla eklenebilir.'],
        'seo:lcp-slow' => ['Yavaş sayfalar', 'week', 'Önemli sayfalar mobilde yavaş açılıyor (LCP). Görselleri küçültün, önbelleği açın.'],
        'seo:sitemap-errors' => ['Site haritası hataları', 'week', 'Search Console site haritasında hata bildiriyor. Site haritasını yeniden üretip gönderin.'],
        'seo:robots-bot-block' => ['robots.txt botları engelliyor', 'week', 'robots.txt arama veya AI botlarını engelliyor. Bilinçli değilse kuralı kaldırın.'],
        'seo:prune-pages' => ['Budanacak sayfalar', 'week', 'Trafik almayan ve birbirine benzeyen sayfalar siteyi zayıflatıyor. Birleştirin, yönlendirin veya kaldırın.'],
        'seo:finding:*' => ['Site taraması bulguları', 'week', 'Site taramasında bulunan teknik sorunlar. Her görevde etkilenen sayfalar ve adımlar var.'],
        'seo:competitor-gap' => ['Rakipte olan konu sizde yok', 'opportunity', 'Rakiplerin sıralandığı konular sitede işlenmemiş. Öncelikli olanları içerik planına ekleyin.'],
        'seo:out-of-area-demand' => ['Hizmet bölgesi dışından talep', 'opportunity', 'Hizmet verilmeyen bölgelerden arama geliyor. Yeni bölge sayfası açmayı değerlendirin.'],
        'seo:org-schema' => ['Kurum yapısal verisi eksik', 'opportunity', 'Organization yapısal verisi yok; AI arama ve Google markayı daha zor tanır.'],
        'seo:org-same-as' => ['Sosyal profil bağlantıları eksik', 'opportunity', 'Yapısal veride markanın resmi profilleri (sameAs) yok. Ekleyin.'],
        'seo:service-schema' => ['Hizmet yapısal verisi eksik', 'opportunity', 'Hizmet sayfalarında Service yapısal verisi yok. Ekleyin.'],
        'seo:faq-block' => ['SSS bölümü önerisi', 'opportunity', 'Hizmet sayfalarına sık sorulan sorular eklemek AI aramada görünürlüğü artırır.'],
        'seo:answer-block' => ['Kısa cevap bloğu önerisi', 'opportunity', 'Sayfaların başına soruyu doğrudan yanıtlayan kısa bir blok eklemek AI özetlerinde yer almayı kolaylaştırır.'],
        'seo:author-eeat' => ['Uzman / yazar bilgisi eksik', 'opportunity', 'İçeriklerde yazar veya uzman bilgisi yok. Güven sinyali için ekleyin.'],
        'seo:entity-consistency' => ['Marka bilgisi tutarsız', 'week', 'Site, profil ve yapısal verideki marka bilgileri (telefon, adres) farklı. Tek doğruda birleştirin.'],

        // Diğer kaynaklar
        'site_fix:ready' => ['Uygulanmaya hazır site düzeltmeleri', 'week', 'Başlık, meta açıklama, alt metin, yönlendirme gibi WordPress düzeltmeleri hazır. Site ekranındaki Düzeltmeler sekmesinde gözden geçirip tek tıkla uygulayın.'],
        'brain' => ['Hizmet Beyni önerisi', 'opportunity', 'Başarılı markalarda işe yaramış yöntemler bu markada henüz uygulanmamış. Uygunsa işe dönüştürün.'],
        'compliance' => ['Uyum ihlali', 'urgent', 'İçerik veya reklam metni sektör kurallarına (ör. sağlık reklamı) aykırı. Metni düzeltin; ihlal ceza ve hesap kısıtı doğurabilir.'],
        'lead' => ['Yeni ajans lead\'i', 'urgent', 'Ajansa yeni bir potansiyel müşteri başvurdu. İlk yanıt hızı kazanma şansını belirler; bugün dönün.'],
        'system' => ['Sistem uyarısı', 'urgent', 'MoxDOP\'un kendi çalışmasında bir sorun var (kuyruk, zamanlayıcı, yedek…). Sistem sağlığı ekranından kontrol edin.'],
        'approval:brain' => ['AI önerisi onay bekliyor', 'week', 'Hesap eşleştirme, sorgu → hizmet ataması ve kümeler için AI önerileri hazır. Onaylanınca uygulanır.'],
        'approval:setup' => ['Otomatik kurulum', 'week', 'Marka kurulum önerileri onay bekliyor ya da kurulum takıldı. Kurulum sayfasını açın.'],
        'coverage:reconnect' => ['Bağlantı yenilenmeli', 'urgent', 'Google veya Meta izni sona erdi; hesapların verisi çekilemiyor. Tek tıkla izin ekranına gidip yeniden bağlanın.'],
        'coverage:lost' => ['Erişim gitti', 'urgent', 'Bir hesap artık bağlı kullanıcıya görünmüyor; müşteri erişimi kaldırmış olabilir. Erişimi yeniden isteyin ya da bağlantıyı kaldırın.'],
        'coverage:unbound' => ['Markaya bağlı olmayan hesaplar', 'week', 'Bazı hesaplar hiçbir markaya bağlı değil; verileri çekilmiyor. Keşfet ve Grupla ile markalara dağıtın.'],
        'coverage:brand-unbound' => ['Markanın bağlanmamış hesapları', 'week', 'Markanın işletmesinde (MCC / Business) ya da adıyla eşleşen, hiçbir varlığa bağlı olmayan reklam hesapları var; verileri çekilmiyor ve marka toplamlarına girmiyor. Marka sayfasındaki "Hesap ekle" ile tek tıkla bağlayın.'],
        'coverage:no-search-console' => ['Search Console bağlı değil', 'week', 'Bazı markaların sitesinde Search Console bağlı değil; sorgu ve SEO önerileri eksik kalır.'],
        'calendar:failed' => ['Gönderi yayınlanamadı', 'urgent', 'Planlanan bir gönderi yayınlanamadı. Hatayı okuyup yeniden deneyin.'],
        'calendar:gbp_post' => ['Onay bekleyen profil gönderisi', 'week', 'İşletme Profili gönderisi yayın için onay bekliyor. İçerik takviminde gözden geçirip onaylayın.'],
        'calendar:due' => ['Zamanı gelen içerik', 'week', 'Takvimde bugün veya daha önce yayınlanması gereken içerik var.'],
        'client_approval' => ['Müşteri onayı', 'week', 'Müşteri onay bağlantısına yanıt verdi: onayladı ya da değişiklik istedi. Yanıtı okuyup işi ilerletin.'],
        'followup' => ['Müşteri takibi', 'urgent', 'Müşteriye söz verilen bir takip bugün veya daha önce yapılmalıydı.'],
        'invoice:overdue' => ['Vadesi geçen fatura', 'urgent', 'Ödeme vadesi geçti. Müşteriye hatırlatın; ödendiyse "Yaptım" ile kapatın.'],
        'invoice:drafts' => ['Kesilmeyi bekleyen faturalar', 'week', 'Bu ay için taslak faturalar henüz kesilmedi.'],
        'commitment' => ['Geride kalan taahhüt', 'week', 'Müşteriye bu ay söz verilen işlerin bir kısmı henüz yapılmadı.'],
        'task' => ['Günü gelen görevler', 'week', 'İş listesinde bugün veya daha önce bitmesi gereken görevler.'],
        'live:token' => ['Bağlantı anahtarı geçersiz', 'urgent', 'Canlı doğrulama bir erişim anahtarının çalışmadığını gösteriyor; arkasındaki bütün hesaplar durur. Bağlantıyı yenileyin.'],
        'live:check' => ['Canlı doğrulama başarısız', 'urgent', 'Bir bağlantı yolu canlı kontrolde yanıt vermedi. Sistem sağlığı ekranından ayrıntıya bakın.'],
        'data:ads_untagged' => ['Takip kodu bozuk', 'urgent', 'Google Ads harcıyor ama GA4\'te reklam trafiği görünmüyor. Otomatik etiketleme, GA4–Ads bağlantısı veya sitedeki etiket bozulmuş; raporlar ve teklifler yanlış veriyle çalışıyor.'],
        'data:conversion_divergence' => ['Ads ve GA4 dönüşümleri tutmuyor', 'week', 'Google Ads ve GA4 dönüşüm sayıları çok farklı. Dönüşüm işlemlerini ve sayım ayarını kontrol edin.'],
        'data:missing_days' => ['Eksik veri günleri', 'week', 'Bazı günlerin verisi eksik; raporlar eksik görünebilir. Veri merkezi\'nden yeniden toplayın.'],
        'data:currency_mismatch' => ['Para birimi uyuşmuyor', 'week', 'Hesabın para birimi beklenenden farklı; tutarlar yanlış görünebilir.'],
        'gbp:reviews_unanswered' => ['Yanıt bekleyen yorumlar', 'week', 'İşletme Profili yorumları 48 saatten uzun süredir yanıtsız. Hızlı yanıt hem müşteri güvenini hem yerel sıralamayı destekler; profil sayfasının Yorumlar sekmesinde AI taslağıyla yanıtlayıp Admin onayıyla Google’a gönderin.'],
        'gbp:no_recent_post' => ['Profil gönderisi yok', 'opportunity', 'İki haftadır gönderi yayınlanmadı ve planlı gönderi de yok. Haftada 1 gönderi profili canlı tutar; Gönderiler sekmesinden AI taslağıyla planlayın.'],
        'gbp:reviews_access' => ['Yorumlar toplanamıyor', 'week', 'Google bu profilin yorumlarını vermiyor (çoğunlukla API erişim onayı ya da hesap yetkisi). Yorumlar sekmesindeki nedeni okuyup Google Cloud / hesap tarafında düzeltin.'],
        'gbp:profile_gaps' => ['Profil eksikleri', 'week', 'Toplanan profil verisinde eksikler var (kategori, açıklama, saatler, fotoğraf…). Profil sağlığı sekmesindeki listeyi Google İşletme Profili’nde tamamlayın.'],
        'data:mixed_currency' => ['Reklam hesapları farklı para biriminde', 'week', 'Markanın reklam hesapları farklı para birimleriyle harcıyor; marka toplamları bu harcamayı toplamaz.'],
        'lead_outcome' => ['Lead sonucu girilmedi', 'week', 'Müşteri lead\'lerinin sonucu (randevu, satış, geçersiz) işaretlenmedi. Nitelikli lead başı maliyet buna göre hesaplanır.'],
    ];

    /**
     * The topic of an item: its key, label, priority group, area and explanation.
     *
     * @param  array<string, mixed>  $item
     * @return array{key: string, label: string, group: string, area: string, explanation: string, known: bool}
     */
    public static function forItem(array $item): array
    {
        $source = (string) $item['source'];
        $rule = isset($item['rule']) && $item['rule'] !== '' ? (string) $item['rule'] : null;
        [$area, $group] = self::SOURCE_DEFAULTS[$source] ?? ['system', 'week'];
        if ($source === 'alert') {
            $area = self::ALERT_AREAS[$rule ?? ''] ?? (in_array($item['asset_type'] ?? null, ['website', 'ga4', 'search_console', 'gsc'], true) ? 'site' : 'ads');
        }
        $key = $rule !== null ? $source.':'.$rule : $source;
        $match = self::match($key) ?? self::match($source);
        if ($match !== null) {
            [$matchedKey, [$label, $group, $explanation]] = $match;

            return ['key' => $matchedKey, 'label' => $label, 'group' => $group, 'area' => $area, 'explanation' => $explanation, 'known' => true];
        }

        return [
            'key' => $key,
            'label' => (string) $item['title'],
            'group' => $group,
            'area' => $area,
            'explanation' => 'Bu konudaki kayıtlar '.(CommandCenter::SOURCES[$source] ?? $source).' kaynağından geliyor. Ayrıntıyı ve adımları kaydın kendi ekranında bulabilirsiniz.',
            'known' => false,
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> every catalog topic (for docs / system map) */
    public static function all(): array
    {
        return self::TOPICS;
    }

    /** Whether a topic key matches one of the patterns ("source:rule", "source:prefix*" or a bare "source"). */
    public static function matches(string $topicKey, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $topicKey || $pattern === explode(':', $topicKey, 2)[0]
                || (str_ends_with($pattern, '*') && str_starts_with($topicKey, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string, 1: array{0: string, 1: string, 2: string}}|null */
    private static function match(string $key): ?array
    {
        if (isset(self::TOPICS[$key])) {
            return [$key, self::TOPICS[$key]];
        }
        foreach (self::TOPICS as $pattern => $topic) {
            if (str_ends_with($pattern, '*') && str_starts_with($key, substr($pattern, 0, -1))) {
                return [$pattern, $topic];
            }
        }

        return null;
    }
}
