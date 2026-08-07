# EXTENSION_POINTS

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Dayanak: ADR-008, ADR-020, ADR-021 (Filament panel)  
> İlgili: `MODULE_MANIFEST_SPEC.md`, `PERMISSION_CONTRACT.md`

## Amaç

Modüllerin menü ve sekme gibi UI katkılarını çekirdeğin bilinen noktalarına kaydetme sözleşmesi. Çekirdek domain menü içeriği uydurmaz; yalnızca extension point’leri host eder.

## Kararlar

### 1. Kayıt biçimi

Manifest `extensions[]` dizisi. Her öğe:

| Alan | Zorunlu | Açıklama |
|------|---------|----------|
| `type` | Evet | Extension point türü (aşağıdaki katalog) |
| `id` | Evet | Modül içinde unique slug (kebab-case) |
| `title` | Evet | UI etiketi |
| `permission` | Hayır | Gerekli permission id; yoksa yalnızca auth yeterli değilse gizlenir politikası uygulanır |
| `order` | Hayır | Sayısal sıra; küçük önce; varsayılan `100` |
| `target` | Koşullu | Sekme hedefi için entity |
| `route` | Evet | Modülün sağladığı göreli route anahtarı (çekirdek host eder) |
| `icon` | Hayır | Çekirdek icon registry kimliği (serbest SVG enjeksiyonu yok) |

### 2. Desteklenen extension point türleri (v1)

| `type` | Ne ekler |
|--------|----------|
| `navigation.menu` | Ana yan menü öğesi (Filament navigation) |
| `navigation.tab.customer` | Müşteri detay sekmesi |
| `navigation.tab.brand` | Marka detay sekmesi |
| `navigation.tab.digital_asset` | Dijital varlık detay sekmesi |
| `navigation.tab.connection` | Connection detay sekmesi (isteğe bağlı) |
| `settings.page` | Modül ayar sayfası kaydı |
| `health.panel` | Admin health panel katkısı (opsiyonel) |

`navigation.tab.workspace` **MVP’de yoktur** (Workspace modeli yok).

v1 dışında type → kayıt **yok sayılır** veya `failed` (çekirdek politikası: bilinmeyen type = uyarı + skip, process düşmez).

### 3. Menü nasıl kaydedilir?

```json
{
  "type": "navigation.menu",
  "id": "main",
  "title": "Örnek Modül",
  "route": "sample-module/home",
  "permission": "sample-module.view",
  "order": 120,
  "icon": "puzzle"
}
```

Kurallar:

- `route` modül `id` öneki ile başlamalıdır: `{moduleId}/...`
- Menü öğesi yalnızca modül `enabled` iken render edilir
- Permission yoksa öğe gizlenir (403 sayfası yerine gizleme tercih edilir)

### 4. Müşteri / marka / dijital varlık sekmeleri

```json
{
  "type": "navigation.tab.brand",
  "id": "overview",
  "title": "Örnek Modül",
  "target": "brand",
  "route": "sample-module/brands/:brandId",
  "permission": "sample-module.view",
  "order": 50
}
```

| Hedef ekran | `type` | Route param beklentisi |
|-------------|--------|------------------------|
| Customer detay | `navigation.tab.customer` | `:customerId` |
| Brand detay | `navigation.tab.brand` | `:brandId` |
| Digital Asset detay | `navigation.tab.digital_asset` | `:digitalAssetId` |

Kurallar:

- Sekme içeriği modülün kendi UI slot’unda render edilir
- Çekirdek yalnızca tab header + host container sağlar
- Entity yoksa veya kullanıcı entity’yi göremiyorsa sekme görünmez
- Modül disabled/failed ise sekme listeden çıkar

### 5. UI tasarım bütünlüğü

| Kural | Açıklama |
|-------|----------|
| Design tokens | Renk, tipografi, spacing yalnızca çekirdek token’ları |
| Bileşenler | Çekirdek UI kit primitive’leri (Button, Table, Tab, Form…) |
| Global CSS | Yasak; stil sızıntısı diğer modülleri bozamaz |
| Icon | Yalnızca kayıtlı icon id |
| Layout | Host container dışına fixed overlay / global sidebar eklenemez |
| Breaking shell | Modül, çekirdek chrome’unu (topbar, nav) değiştiremez |

UI host: Filament 5 / Livewire. Modüller Filament theme/token ve çekirdek bileşen kalıplarını kullanır; global CSS sızıntısı yok.

### 6. Çözümleme sırası

1. Enabled modüllerin extension’ları toplanır  
2. `type` + `target` + `order` + `id` ile sıralanır  
3. Permission filtresi uygulanır  
4. Host UI render eder  

## Gerekçe

Sekme/menü kaydı Perfex/WordPress admin menü hook’larına benzer; çekirdek shell stabil kalır.

## Sınırlar

- Dashboard widget / report slot v1 katalogunda yoktur (sonra eklenebilir).  
- Mobil navigasyon ayrı type gerektirebilir; şimdilik aynı kayıtlar responsive host’ta kullanılır.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Frontend yok | Extension host ve design system sıfırdan |
| Tab/menü yok | Customer/Brand/Asset detay sayfaları çekirdekte extension-aware inşa edilmeli |
| Foundation “navigation extension points” geneldi | Bu belge v1 type kataloğunu kilitler |

## Açık Sorular

1. Aynı `order` değerinde ikincil sıralama `moduleId` mi, `title` mi? (öneri: `moduleId` asc)  
2. Asset tipine özel sekme filtresi (`digitalAsset.kind == website`) v1’de desteklenecek mi?
