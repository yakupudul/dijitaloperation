# MODULE_TEST_CHECKLIST

> Dayanak: tüm `docs/module-sdk/*` sözleşmeleri  
> Kullanım: Yeni modül yayınlanmadan önce zorunlu kapı

## Amaç

Bir modülün “enable edilip üretiye alınabilir” sayılması için geçmesi gereken testler.

## Kararlar

Aşağıdaki maddeler **zorunlu**dur. Biri fail ise yayınlanmaz.

### A. Manifest ve kimlik

- [ ] `module.manifest.json` parse edilir; `manifestVersion === 1`
- [ ] `id` kebab-case regex’e uyar; reserved değil
- [ ] `version` geçerli SemVer
- [ ] `core.min` / `core.maxExclusive` mevcut çekirdekle uyumlu
- [ ] `class` enum değerlerinden biri
- [ ] `data.tablePrefix` == `m_{id_snake}_`
- [ ] Tüm permission / setting / job / event / extension id’leri isim kurallarına uyar

### B. Lifecycle

- [ ] Cold start’ta bozuk bağımlılık simülasyonu uygulamayı düşürmez
- [ ] Enable başarılı → state `enabled`
- [ ] Disable → menü/sekme/job/subscriber pasif; core ayakta
- [ ] Failed boot → state `failed`; core ayakta
- [ ] Uninstall → registry temiz; **tablolar ve settings duruyor**
- [ ] Aynı id ile yeniden enable → eski veri erişilebilir

### C. Extension / UI

- [ ] Bildirilen menü/sekme yalnızca ilgili permission ile görünür
- [ ] Brand/Customer/Asset tab route param’ları doğru bağlanır
- [ ] Design system dışı global CSS yok (statik analiz veya review)
- [ ] Modül UI crash’i shell’i düşürmez (error boundary)

### D. Permissions

- [ ] Manifest izinleri registry’ye işlenir
- [ ] API, izinsiz çağrıda reddeder
- [ ] UI izinsiz öğeyi gizler

### E. Settings

- [ ] Default değer okunur
- [ ] Set + get scope doğru (`application`/`brand`/`connection`/…)
- [ ] `secret: true` ise ham secret loglanmaz
- [ ] Disable sonrası değer korunur

### F. Data / migrations

- [ ] Migration yalnız prefix altı tablo oluşturur
- [ ] Prefix dışı tablo adı migration’da reddedilir (negatif test)
- [ ] Başka modül tablosuna yazma yok (kod review + mümkünse arch test)
- [ ] Core tablo ALTER yok
- [ ] Down migration varsa belgelenir; yoksa gerekçe notu var

### G. Events

- [ ] Publish envelope zorunlu alanları dolu
- [ ] Yalnız manifest’te bildirilen type publish edilir
- [ ] Subscriber hatası publisher’ı bozmaz
- [ ] Subscriber idempotent (aynı `event.id` tekrarında yan etki yok/az)
- [ ] Optional bağımlı özellik: hedef modül kapalıyken degrade, crash yok

### H. Jobs

- [ ] Uzun iş HTTP sync path’te değil
- [ ] Enqueue + başarılı tamamlanma
- [ ] Retry / timeout ayarları işler (en az bir forced fail senaryosu)
- [ ] Module disabled iken yeni job işlenmez / cancel
- [ ] Harici API mock’unda rate-limit/timeout yolu test edilir (connector’lar)

### I. Health & logs

- [ ] Health payload şeması geçerli; timeout davranışı doğrulanır
- [ ] Loglarda `module_id` + `source` otomatik/zorunlu
- [ ] Health/details içinde secret yok

### J. Isolation smoke

- [ ] Modül içinde kasıtlı exception → process ayakta
- [ ] İkinci bir modül (`sample-module` veya stub) etkilenmeden çalışır

## Yayın kapısı özeti

| Kapı | Zorunlu |
|------|---------|
| A–J checklist yeşil | Evet |
| Manuel güvenlik review (secret, authz) | Evet |
| Performans benchmark | Hayır (v1 zorunlu değil) |
| Dış pentest | Hayır (v1 zorunlu değil) |

## Gerekçe

Sözleşme ihlalleri “sonra düzeltiriz” borcuna dönüşmesin diye yayın öncesi kapı gerekir.

## Sınırlar

- Test framework: Pest; maddeler Pest/Feature testlerine map edilir.  
- Arch lint yoksa ilgili maddeler review ile kapatılır ve borç olarak işaretlenir.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Test runner yok | CI ve test harness sıfırdan; checklist maddeleri ilk hedef kabul testleri olur |
| Modül yok | `sample-module` bu checklist’in ilk referans uygulaması olabilir |

## Açık Sorular

Yok. İlk günde checklist + review zorunlu; Pest arch/import testleri önerilir ama Core belgesel bloker değildir. Coverage eşiği yok.
