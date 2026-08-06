# PRODUCT_VISION

> DOP — Dijital Operasyon Platformu  
> Durum: Başlangıç kararları (uygulama kodu yok)  
> İlgili kararlar: ADR-001, ADR-002

## Kararlar

1. **Ürün tanımı (ADR-001)**  
   DOP, işletmelerin dijital varlıklarının mevcut durumunu analiz eden, sorunları ve fırsatları teşhis eden, nedenlerini açıklayan ve önceliklendirilmiş yapılacaklar üreten modüler bir dijital operasyon platformudur.

2. **Dashboard değildir (ADR-001)**  
   DOP yalnızca ham veri gösteren bir dashboard değildir. Değer; teşhis, açıklama, önceliklendirme ve eyleme dönüştürülebilir çıktıdadır.

3. **Temel akış (ADR-002)**  
   Platformun birincil değer zinciri şudur:

   ```text
   Veri → Kanıt → Teşhis → İçgörü → Öneri → Görev → Sonuç
   ```

4. **İlk gerçek ürün dilimi**  
   İlk gerçek modül **Website Diagnosis** olacaktır. İlk sürümde zorunlu olarak GA4, Search Console veya DataForSEO istememelidir.  
   (Ayrıntı: `MODULE_ARCHITECTURE.md`, ADR-011, ADR-012)

5. **Hedef kullanıcı bağlamı**  
   DOP, bir veya daha fazla müşteri / marka / dijital varlık yöneten işletme veya ajans bağlamında çalışır. Hiyerarşi `DOMAIN_MODEL.md` içinde sabittir.

## Gerekçe

- Ham metrik ekranı, “ne olduğunu” gösterir; DOP “ne anlama geldiğini”, “nedenini” ve “ne yapılacağını” üretmeyi hedefler.
- Sabit akış, modüllerin ortak dilini ve çıktı türlerini hizalar: her modül bu zincirin bir veya birkaç halkasına katkı verir.
- Website Diagnosis ile başlamak, harici reklam/analitik hesap bağlantısı olmadan da değer üretebilen bir ilk dilim sağlar.

## Sınırlar

- Bu belge teknoloji stack’i seçmez.
- Fiyatlandırma, go-to-market ve marka konumlandırması burada kesinleştirilmez.
- Tüm dijital varlık türleri için eşit derinlik ilk günden garanti edilmez; genişleme modüller üzerinden olur.
- “Sonuç” halkasının ölçüm biçimi (manuel kapanış, otomatik yeniden tarama, KPI iyileşmesi vb.) henüz ürün kararı olarak kilitlenmemiştir — bkz. Açık Sorular.

## Açık Sorular

1. Birincil kullanıcı kimdir: ajans operatörü, marka içi dijital ekip, yoksa her ikisi mi?
2. “Sonuç” nasıl doğrulanır ve görev kapanışıyla nasıl bağlanır?
3. İlk sürümde çok kiracılı (multi-tenant) satış modeli mi, yoksa tek işletme kurulumu mu öncelikli?
4. Client Portal / müşteriye açık sunum ilk milestone’a dahil mi, yoksa sonraki faz mı? (`OUT_OF_SCOPE.md` ile hizalanmalı)
