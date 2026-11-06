# Moximu sosyal medya görselleri (sabit stil)

yakup 2026-11-06: "Yaptığın tasarımlar çok iyi … bu stili artık panele sabitle." Moximu'nun Instagram, Facebook ve
LinkedIn görselleri ile hareketli Reels'leri yalnız bu şablonlarla üretilir. Yeni stil ya da yeni şablon, yakup'un onayı
olmadan eklenmez; yeni içerik = yeni JSON, şablon kodu aynı kalır.

## Stil kuralları
- Zemin #F5F4F0 (açık sıcak gri), metin #111827, ikincil gri #8A8F98 / #4B5160. Vurgu tek: Moximu sarısı #FDCF3C;
  ikinci renk teal #0F5A52 (açık zemin #E3EFEC). Mavi yok, degrade yok (panel köşesindeki yumuşak sarı/teal ışık hariç).
- Yazı Inter: başlık 500 ağırlık, sıkı harf aralığı; ikinci satır gri (iki tonlu başlık). Büyük rakamlar 300 ağırlık.
- Sol üstte Moximu işareti + "Moximu" + gri bölüm adı (Rehber, Vaka, Gündem). Sağ üstte yeşil etiket.
- Beyaz kartlar 28-36 px köşe, hafif gölge; altta "moximu.com/…" adresi + teal hap düğme ("… ›").
- Süs yalnız sarı ve soluk teal daireler. Yapay zekâ görseli, stok insan fotoğrafı, emoji yok. Müşteriden yalnız logo.
- Rakamlar yalnız kayıtlı sonuçlardan, kaynak satırıyla (Search Console ve GA4 · dönem). Garanti dili yok.
- Boyutlar: gönderi 1080×1350, story 1080×1920, Reels 1080×1920 30 fps H.264 + sessiz ses izi.

## Şablonlar
- `gen.cjs` (görsel): `cover`, `item`, `end` (kaydırmalı), `split` (serbest / aykırı), `date` (gündem), `case` (vaka:
  logo + büyük yüzde + 2 KPI), `three` (üç madde), `story`, `story-score`.
- `reel.cjs` (hareketli Reels): sahneler `hook`, `item`, `kpi` (sayaçla artan yüzde), `end`. Her kare zamana göre
  çizilir; Instagram arayüzü altta ve sağda kaldığı için içerik üst-orta bölgededir.

## Kullanım
```
NODE_PATH=$(npm root -g) node tools/social-visuals/gen.cjs ornekler/gonderiler-2026-11.json cikti/
NODE_PATH=$(npm root -g) node tools/social-visuals/reel.cjs ornekler/reels-vaka.json cikti/reels.mp4
```
Çıktılar MoxDOP'a taslak olarak girer (MCP `social-posts` ya da /sosyal › Yeni gönderi); yayın yalnız Admin onayıyla.
Örnek JSON'lar `ornekler/` altında: 9-20 Kasım 2026'nın 14 gönderisi ve 2 Reels.
