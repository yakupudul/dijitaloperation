# DOMAIN_MODEL

> İlgili kararlar: ADR-003  
> Ürün vizyonu: `PRODUCT_VISION.md`

## Kararlar

1. **Temel hiyerarşi (ADR-003)**  
   Sistemdeki sahiplik ve kapsam hiyerarşisi sabittir:

   ```text
   Workspace
   → Customer
   → Brand
   → Digital Asset
   ```

2. **Katman anlamları**

   | Varlık | Anlam (başlangıç) |
   |--------|-------------------|
   | Workspace | Platformdaki çalışma alanı / kiracı bağlamı. Kullanıcılar, roller ve üst düzey ayarlar burada toplanır. |
   | Customer | Workspace altındaki müşteri (ajans senaryosunda hesap; tek işletmede “kendisi” olabilir). |
   | Brand | Bir müşteriye bağlı marka / ürün yüzü. |
   | Digital Asset | Bir markaya bağlı izlenen dijital varlık. |

3. **Dijital varlık örnekleri** (kapsayıcı liste değil; örnekler):

   - Website
   - Meta Ads account
   - Google Ads account
   - GA4 property
   - Search Console property
   - Google Business Profile
   - CRM
   - Social media account

4. **Çekirdek sahipliği**  
   Workspace, Customer, Brand ve Digital Asset kimlikleri ve ilişkileri **çekirdek** sorumluluğundadır. Domain-specific analiz alanları modüllere aittir.  
   (Bkz. `CORE_RESPONSIBILITIES.md`)

5. **Akış nesneleri (ürün düzeyinde)**  
   Temel akıştaki kavramlar domain dilinin parçasıdır; fiziksel tablo tasarımı bu belgede sabitlenmez:

   | Kavram | Rol |
   |--------|-----|
   | Veri | Ham veya normalize edilmiş gözlem kaynağı |
   | Kanıt | Teşhisi destekleyen somut bulgu |
   | Teşhis | Sorun / fırsat tespiti |
   | İçgörü | Teşhisin yorumu / neden açıklaması |
   | Öneri | Önceliklendirilmiş aksiyon önerisi |
   | Görev | Öneriden türeyebilen yapılacak iş |
   | Sonuç | Görev veya iyileştirme sonrası durum |

## Gerekçe

- Ajans ve tek-marka senaryolarını aynı hiyerarşiyle karşılamak için Workspace → Customer → Brand ayrımı gereklidir.
- Digital Asset’i Brand altına bağlamak, aynı müşterinin birden fazla markasını ve marka başına çoklu kanalı destekler.
- Analiz çıktılarını (kanıt, teşhis, …) çekirdek varlık hiyerarşisinden ayırmak, plugin modüllerinin kendi şemalarını taşımasına izin verir.

## Sınırlar

- Bu belge SQL şeması, PK/FK veya ORM modeli tanımlamaz.
- Bir Digital Asset’in birden fazla Brand’e bağlanıp bağlanamayacağı **henüz kararlaştırılmamıştır**.
- Connector hesapları (ör. GA4 property) ile “analiz edilen website” arasındaki bağ tipi (asset-to-asset link vs. connector binding) uygulama tasarımına bırakılmıştır; ürün hiyerarşisi bozulmadan çözülmelidir.
- Görev (Task) çekirdekte ortak bir yetenek olarak listelenir (`CORE_RESPONSIBILITIES.md`); görevlerin hangi teşhis/öneriye bağlanacağı sözleşme seviyesinde `MODULE_CONTRACT.md` ve `EVENT_ARCHITECTURE.md` ile ilerletilir.

## Açık Sorular

1. Workspace ↔ Customer ilişkisi her zaman 1:N mi, yoksa Customer birden fazla Workspace’te yer alabilir mi?
2. Digital Asset türleri çekirdekte sabit enum mu, yoksa modül kaydıyla mı genişler?
3. Aynı website hem “Website asset” hem de bağlı “Search Console property” olarak nasıl modellenecek?
4. Kanıt / Teşhis / Öneri kayıtları çekirdek ortak tablolarda mı, yoksa tamamen modül şemalarında mı tutulacak?
