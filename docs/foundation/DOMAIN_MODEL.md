# DOMAIN_MODEL

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-017, ADR-027, ADR-034, ADR-036

## Kararlar

1. **Sahiplik hiyerarşisi**

   ```text
   Customer → Brand → Digital Asset → Connection
   ```

   MVP’de Workspace yoktur.

2. **Digital Asset vs Connection**  
   GA4 / Search Console / DataForSEO = Website **connection**. Bir Website’e çoklu Connection.

3. **Analiz akışı (Result entity yok)**

   ```text
   Run → Evidence → Finding → Recommendation → Task
   ```

   | Kavram | Bağ / yaşam |
   |--------|-------------|
   | Run | Bir teşhis/toplama çalıştırması |
   | Evidence | **Run’a bağlı** kanıt |
   | Finding | Asset üzerinde **kalıcı** problem/fırsat; `fingerprint` ile run’lar arası güncellenir; `last_run_id`; `resolved` olabilir |
   | Recommendation | Finding’e bağlanabilir |
   | Task | Recommendation’dan manuel snapshot |

4. **Credential**  
   Connection’dan ayrı şifreli tablo (ADR-027).

## Gerekçe

Finding’i Run satırı sanmak duplicate ve “sorun bitti mi?” takibini bozar. Ayrı Result entity MVP’de gereksizdir.

## Sınırlar

* SQL migration sözdizimi kod fazında.
* Finding status enum değerleri uygulama’da netleşir (`open`, `resolved`, …).

## Açık Sorular

Yok (Core için).
