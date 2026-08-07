# DOMAIN_MODEL

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-016, ADR-017, ADR-027, ADR-028

## Kararlar

1. **Sahiplik hiyerarşisi (ADR-017)**

   ```text
   Customer → Brand → Digital Asset → Connection
   ```

   MVP’de Workspace yoktur.

2. **Digital Asset vs Connection**  
   GA4 / Search Console / DataForSEO = Website **connection**; asset değildir. Bir Website’e çoklu Connection.

3. **Akış nesneleri**

   Run → Evidence → Finding → Recommendation → Task → Result  

   Minimal alanlar: MASTER_SPEC §7.2 (ADR-028).  
   Finding.`fingerprint` tekrar tespiti ilişkilendirir.

4. **Credential**  
   Connection’dan ayrı tablo; şifreli payload (ADR-027).

5. **Çekirdek sahipliği**  
   Ortak kayıtlar çekirdekte; domain kuralları modüllerde.

## Gerekçe

Asset/Connection ve secret ayrımı şema sızıntısını önler.

## Sınırlar

* SQL migration sözdizimi kod fazında.
* Asset tek Brand altında (MVP).

## Açık Sorular

Yok (Core için). Digital Asset `type` kaydı modül kayıtlarıyla genişler (uygulama detayı, ürün kararı değil).
