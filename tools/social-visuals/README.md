# Moximu sosyal medya görselleri (sabit stil)

**2026-11-07 güncellemesi (yakup onayı, strateji: https://claude.ai/artifact/DVdR3wFmbUhrCRbgaoGVBX):** karma stil.
Kapak ve Reels fotoğraflı editoryal (tam ekran stok klinik fotoğrafı, Inter Display 300 ince büyük yazı, iki köşeye
bölünmüş başlık, *sarı vurgu*, sol üstte işaret + "Moximu" + seri adı); iç sayfalar aşağıdaki açık bilgi kartları.
"Bunu yazabilir misiniz?" serisi siyah zemin + ince sarı çizgi. Seriler: Rakamla (pazartesi, kendi müşteri verimiz,
kaynak satırı zorunlu), Bunu yazabilir misiniz? (çarşamba), Kaydet ve kullan / Site vitrini (perşembe), Hekim soruyor
Reels (cuma); salı yakup'un kendi videosu. Site vitrini kliniğin yazılı izniyle yayınlanır.

yakup 2026-11-06: "Yaptığın tasarımlar çok iyi … bu stili artık panele sabitle." Moximu'nun Instagram, Facebook ve
LinkedIn görselleri ile hareketli Reels'leri yalnız bu şablonlarla üretilir. Yeni stil ya da yeni şablon, yakup'un onayı
olmadan eklenmez; yeni içerik = yeni JSON, şablon kodu aynı kalır.

## Stil kuralları
- Zemin #F5F4F0 (açık sıcak gri), metin #111827, ikincil gri #8A8F98 / #4B5160. Vurgu tek: Moximu sarısı #FDCF3C;
  ikinci renk teal #0F5A52 (açık zemin #E3EFEC). Mavi yok, degrade yok (panel köşesindeki yumuşak sarı/teal ışık hariç).
- Yazı Inter: başlık 500 ağırlık, sıkı harf aralığı; ikinci satır gri (iki tonlu başlık). Büyük rakamlar 300 ağırlık.
- Sol üstte Moximu işareti + "Moximu" + gri bölüm adı (Rehber, Vaka, Gündem). Sağ üstte yeşil etiket.
- Beyaz kartlar 28-36 px köşe, hafif gölge; altta "moximu.com/…" adresi + teal hap düğme ("… ›").
- Süs yalnız sarı ve soluk teal daireler. Yapay zekâ görseli ve emoji yok. Müşteriden yalnız logo (site ekran görüntüsü
  yalnız izinle). Stok fotoğraf yalnız fotoğraflı şablonlarda, `assets/foto/` içinden (lisanslar `kaynaklar.md`).
- Rakamlar yalnız kayıtlı sonuçlardan, kaynak satırıyla (Search Console ve GA4 · dönem). Garanti dili yok.
- Boyutlar: gönderi 1080×1350, story 1080×1920, Reels 1080×1920 30 fps H.264 + sessiz ses izi.

## Şablonlar
- `gen.cjs` (görsel): `cover`, `item`, `end` (kaydırmalı), `split` (serbest / aykırı), `date` (gündem), `case` (vaka:
  logo + büyük yüzde + 2 KPI), `three` (üç madde), `story`, `story-score`.
- `gen.cjs` fotoğraflı şablonlar: `foto` (fotoğraflı kapak: top/bottom), `foto-sayi` (büyük rakam + kaynak),
  `foto-story`, `siyah` (soru kartı), `siyah-cevap` ("Hayır." + açıklama), `veri` (açık zemin çubuk kartı),
  `mockup` (tarayıcı + telefon çerçevesinde site). Metinde `\n` satır sonu, `*yıldız*` sarı vurgu.
- `reel-foto.cjs` (fotoğraflı Reels): sahneler `foto`, `liste`, `telefon` (uzun site görüntüsü kayar), `son`.
- `reel.cjs` (hareketli Reels, açık stil): sahneler `hook`, `item`, `kpi` (sayaçla artan yüzde), `end`. Her kare zamana göre
  çizilir; Instagram arayüzü altta ve sağda kaldığı için içerik üst-orta bölgededir.

## Kullanım
```
NODE_PATH=$(npm root -g) node tools/social-visuals/gen.cjs ornekler/gonderiler-2026-11.json cikti/
NODE_PATH=$(npm root -g) node tools/social-visuals/reel.cjs ornekler/reels-vaka.json cikti/reels.mp4
NODE_PATH=$(npm root -g) node tools/social-visuals/gen.cjs ornekler/takvim-2026-11-12.json cikti/
NODE_PATH=$(npm root -g) node tools/social-visuals/reel-foto.cjs ornekler/reels-2026-11b/w1-cum-reel-6-ay.json cikti/w1.mp4
```
`ornekler/takvim-2026-11-12.json`: 9 Kasım - 6 Aralık 2026 takvimi (16 gönderi/Reels, 19 story); MoxDOP'a
`database/data/social/2026-11b` + migration `2027_01_07_170000` ile taslak olarak girdi.
Çıktılar MoxDOP'a taslak olarak girer (MCP `social-posts` ya da /sosyal › Yeni gönderi); yayın yalnız Admin onayıyla.
Örnek JSON'lar `ornekler/` altında: 9-20 Kasım 2026'nın 14 gönderisi ve 2 Reels.
