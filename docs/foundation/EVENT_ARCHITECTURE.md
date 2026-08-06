# EVENT_ARCHITECTURE

> İlgili kararlar: ADR-009, ADR-013  
> Sözleşme: `MODULE_CONTRACT.md`  
> Akış: `PRODUCT_VISION.md` (Veri → … → Sonuç)

## Kararlar

### 1. Event’lerin rolü

Event’ler, modüller arası ve modül–çekirdek arası **birincil gevşek bağlı iletişim** yoludur. Private import ve private tablo erişiminin yerine geçer.

### 2. Yayınlama ve dinleme

- Her modül event **yayınlayabilir**.
- Her modül event **dinleyebilir**.
- Çekirdek, ortak yaşam döngüsü ve platform event’lerini yayınlar / dinler (ör. asset oluşturuldu, modül disable edildi, görev oluşturuldu — kesin katalog açık sorudur).

### 3. Ürün akışı ile hizalama

Temel ürün akışındaki geçişler, event tasarımının yol göstericisidir:

```text
Veri → Kanıt → Teşhis → İçgörü → Öneri → Görev → Sonuç
```

Bu zincirdeki her halka için event adları bu aşamada **kesinleştirilmemiştir**; ilk uygulama Website Diagnosis akışına göre türetilecektir.

### 4. Job ve event ilişkisi (ADR-013)

- Uzun süren toplama / tarama / analiz işleri background job olarak çalışır.
- Job tamamlanması veya anlamlı ara aşamalar event üretebilir.
- Event dinleyicileri mümkün olduğunca kısa tutulmalı; ağır iş yeniden job’a delege edilmelidir.

### 5. Güvenilirlik ilkeleri (başlangıç)

Aşağıdakiler **ilke** olarak kabul edilir; altyapı seçimi yapılmamıştır:

- Yayınlayan modül, dinleyen modülün ayakta olmasını varsaymamalıdır.
- Dinleyici hataları yayıncı işlemini zorunlu olarak düşürmemelidir (modül hatası uygulamayı düşürmez kuralı ile uyumlu).
- Harici API kaynaklı işlerde retry, timeout ve rate-limit uygulanır.

### 6. Event isimlendirme standardı

Kesin standart (`docs/module-sdk/EVENT_CONTRACT.md` ile aynı):

| Parça | Biçim |
|-------|--------|
| Modül kimliği | kebab-case |
| Event action | kebab-case |
| Ayırıcı | nokta (`.`) |
| Tam type | `{moduleId}.{eventName}` |
| Çekirdek | `core.{eventName}` |

Örnek: `website-diagnosis.scan-completed`

Snake_case event adları kullanılmaz.

### 7. Website Diagnosis için örnek event adayları (kesin katalog değil)

Aşağıdakiler **aday**dır; payload şeması ADR seviyesinde kilitli değildir. İsimler yukarıdaki standarda uyar:

- `website-diagnosis.scan-requested`
- `website-diagnosis.scan-completed`
- `website-diagnosis.evidence-collected`
- `website-diagnosis.findings-ready`
- `website-diagnosis.recommendations-ready`
- `core.task.create-requested` (çekirdek veya automation üzerinden)

## Gerekçe

- Event tabanlı sınır, diagnosis / intelligence / automation katmanlarının birbirini compile-time bilmesini engeller.
- Job + event ayrımı, crawl gibi uzun işlemleri HTTP’den ayırır.
- Aday event isimleri dokümantasyonu hızlandırır; isimlendirme standardı Module SDK ile tekilleştirilmiştir. Payload şeması erken kilitlenmez.

## Sınırlar

- Message broker / bus teknolojisi seçilmemiştir (in-process bus yeterli olabilir).
- Event şema registry, versiyonlama ve breaking change politikası henüz yazılmamıştır.
- Exactly-once, ordering ve outbox pattern kararları açık sorudur.
- Çapraz modül saga / orchestration motoru bu aşamada kapsam dışıdır.

## Açık Sorular

1. Event taşıyıcısı başlangıçta in-process mi, yoksa harici broker mı?
2. Event şemaları nerede versiyonlanacak ve kim doğrulayacak?
3. Çekirdek zorunlu platform event kataloğu neleri içerecek?
4. Başarısız dinleyici için dead-letter / retry politikası ne olacak?
5. Görev oluşturma event ile mi, yoksa açık contract çağrısı ile mi tetiklenecek?
