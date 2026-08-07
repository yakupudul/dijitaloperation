# PRODUCT_VISION

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-015, ADR-016, ADR-019, ADR-018

## Kararlar

1. **Ürün tanımı (ADR-015)**  
   DOP, Moximu dijital pazarlama ajansının kendi müşterilerini, markalarını ve bu markalara bağlı dijital varlıkları tek merkezden denetlemek için kullandığı **iç operasyon sistemidir**.

2. **SaaS değildir (ADR-019)**  
   DOP başlangıçta SaaS değildir. Müşteriler sisteme giriş yapmaz. Kullanıcılar yalnızca ajans sahibi (Admin) ve ajans çalışanlarıdır (Team Member).

3. **Dashboard değildir**  
   Değer; denetim, kanıt, bulgu, öneri ve iç göreve dönüştürmedir — ham metrik ekranı değildir.

4. **Temel akış (ADR-016)**

   ```text
   Customer → Brand → Digital Asset → Connection → Run → Evidence → Finding → Recommendation → Task → Result
   ```

5. **Harici yazma yok (ADR-018)**  
   DOP harici sistemlerde değişiklik yapmaz. Desteklenen operasyonel zincir:

   ```text
   Read → Collect → Analyze → Diagnose → Recommend → Create internal task → Track result
   ```

6. **İlk ürün dilimi**  
   Website digital asset + Website Diagnosis; connector’lar isteğe bağlı genişletir. Ayrıntı: `MASTER_SPEC.md`, `MODULE_ARCHITECTURE.md`.

## Gerekçe

- Ajans içi operasyon, müşteri portalı ve multi-tenant karmaşıklığını MVP’den çıkarır.
- Asset/Connection ayrımı entegrasyonları varlık sanmadan modellemeyi sağlar.
- Read-only entegrasyon, müşteri hesaplarında istenmeyen değişiklik riskini ortadan kaldırır.

## Sınırlar

- Fiyatlandırma / dış satış modeli yok (SaaS değil).
- Tüm asset türleri eşit derinlikle gelmez; sıra roadmap’tedir.
- “Result” ölçüm detayı (manuel kapanış vs yeniden run) uygulama tasarımında netleşir; harici write gerektirmez.

## Açık Sorular

1. Result kaydı Task kapanışına mı yoksa yeni Diagnosis Run karşılaştırmasına mı bağlanacak?
2. Team Member için varsayılan permission seti nedir? (Admin süper-set midir?)
