# RUNBOOK

> İnceleme tarihi: 2026-08-06  
> Görev kuralı gereği paket kurulumu, migration ve uygulama komutları çalıştırılmadı. Aşağıdaki komutlar depoda tanımlı değildir; “çalıştırıldı ve doğrulandı” anlamı taşımaz.

## Projenin yerelde nasıl çalıştırıldığı

**Doğrulanamadı — çalıştırma yönergesi yok.**

Depoda şunlar bulunamadı:

- `package.json` scripts
- `Makefile`
- `docker-compose.yml` / `Dockerfile`
- Kurulum adımları içeren genişletilmiş README
- Devcontainer veya IDE launch config

Yerel çalıştırma için bilinen tek adım (depo klonlama düzeyinde):

```bash
git clone https://github.com/yakupudul/dijitaloperation.git
cd dijitaloperation
```

Bu adımdan sonra çalıştırılacak uygulama girişi **yok**.

## Gerekli environment değişkenleri

**Tanımlı değil.**

- `.env` / `.env.example` / `.env.sample` yok
- Dokümante edilmiş env değişken listesi yok

Gizli değerler bu dosyaya **yazılmamıştır** (zaten depoda gizli değer bulunamadı).

## Build, development, test ve production komutları

| Amaç | Komut | Durum |
|------|--------|--------|
| Development | — | **Tanımlı değil** |
| Build | — | **Tanımlı değil** |
| Test | — | **Tanımlı değil** |
| Production start / deploy | — | **Tanımlı değil** |
| Lint / format | — | **Tanımlı değil** |
| Database migrate | — | **Tanımlı değil** |

## Bilinen repo meta bilgileri

| Bilgi | Değer |
|-------|--------|
| GitHub | `https://github.com/yakupudul/dijitaloperation` |
| Varsayılan branch | `main` |
| İncelenen commit | `8223f0c` — `Initial commit` |
| GitHub `language` | `null` |
| GitHub `size` | `0` (KB düzeyinde boş depo göstergesi) |

## Bilinmeyen veya çalıştığı doğrulanamayan noktalar

1. Uygulamanın hedef runtime’ı (Node, Python, .NET, vb.) — **doğrulanamadı**
2. Yerel geliştirme için gereken araç zinciri — **doğrulanamadı**
3. Production hosting hedefi — **doğrulanamadı**
4. Gerekli env değişkenleri ve secret yönetimi — **doğrulanamadı**
5. Test / CI pipeline’ın varlığı veya başarısı — **yok / doğrulanamadı**
6. GitHub Issues / proje yönetim içeriği — bu ortamda issues erişimi kısıtlı kaldı; içerik **doğrulanamadı**
7. README dışındaki ürün spesifikasyonu (ayrı wiki, Notion, vb.) — depo içinde **yok**; dış kaynak **doğrulanamadı**

## Güvenlik notu

Bu runbook’a API anahtarı, token veya şifre eklenmemiştir. Gelecekte env değişkenleri eklendiğinde yalnızca değişken **adları** belgelenmeli; değerler belgelenmemelidir.
