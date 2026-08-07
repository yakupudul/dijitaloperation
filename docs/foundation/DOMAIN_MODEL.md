# DOMAIN_MODEL

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-016, ADR-017

## Kararlar

1. **Sahiplik hiyerarşisi (ADR-017)**

   ```text
   Customer
   → Brand
   → Digital Asset
   → Connection
   ```

   MVP’de **Workspace yoktur.** Tek kurulum = tek ajans.

2. **Katman anlamları**

   | Varlık | Anlam |
   |--------|--------|
   | Customer | Ajansın müşterisi |
   | Brand | Müşteriye bağlı marka |
   | Digital Asset | Markaya bağlı yönetilen gerçek dijital varlık |
   | Connection | Varlık hakkında veri sağlayan veya incelemeye yarayan bağlantı |

3. **Digital Asset örnekleri**

   * Website  
   * Google Business Profile  
   * Google Ads account  
   * Meta Ads account  
   * Instagram account  
   * YouTube channel  
   * CRM  

4. **Connection örnekleri**

   * WordPress  
   * GA4  
   * Search Console  
   * DataForSEO  
   * PageSpeed  
   * Lighthouse  
   * Uptime provider  
   * Crawl provider  

5. **Asset ≠ Connection**  
   GA4, Search Console ve DataForSEO ilk kullanımda **Website digital asset’inin connection’larıdır**; ayrı Digital Asset olarak modellenmez.  
   Bir Website’e birden fazla Connection bağlanabilir.

6. **Akış nesneleri (çekirdek kavramlar)**

   | Kavram | Rol |
   |--------|-----|
   | Run | Toplama/teşhis çalıştırma birimi |
   | Evidence | Kanıt |
   | Finding | Sorun / fırsat bulgusu |
   | Recommendation | Öneri |
   | Task | Ajans içi yapılacak iş (kullanıcı Recommendation’dan manuel üretebilir) |
   | Result | İş / iyileştirme sonucu |

7. **Çekirdek sahipliği**  
   Customer, Brand, Digital Asset, Connection ve akış nesnelerinin ortak kayıtları çekirdektedir. Domain-specific kurallar modüllerdedir.

## Gerekçe

- Connection’ı asset’ten ayırmak, “GA4 property = asset” karışıklığını önler.
- Workspace kaldırmak MVP’yi ajans-içi gerçeğe hizalar.

## Sınırlar

- SQL şeması bu belgede sabitlenmez.
- Bir Digital Asset’in birden fazla Brand’e bağlanması desteklenmez (MVP: asset tek brand altında).
- Connection provider kimlikleri modül kaydıyla genişler.

## Açık Sorular

1. Digital Asset `type` değerleri çekirdek enum + modül kaydı hibrit mi olacak?
2. Connection’ın birden fazla asset’e bağlanması yasak mı (MVP varsayımı: tek asset)?
3. Evidence/Finding tablolarında modül-özel JSON extension zorunlu mu?
