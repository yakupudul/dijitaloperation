<?php

namespace App\Support\Sales;

use App\Models\AgencySetting;
use Illuminate\Validation\ValidationException;

/**
 * Tür: what kind of business a sales record is (klinik, muayenehane, hekim, hastane, fabrika, mağaza…). The record
 * itself is always a generic "müşteri kaydı" (yakup 2026-11-07: "müşteri adayları genel bir ifadedir"); the kind is a
 * field on it that picks the tone of the messages (hitap), whether the site's price list applies (non-clinic kinds
 * never get a price written by AI), the health-advertising rules and the funnel filters. The list starts with the
 * defaults below and lives in agency_settings.lead_kinds, where the operator (Hizmetler › Kayıt türleri) and Claude
 * (MCP `lead-kinds`, after its analyses) add, change and remove kinds, so the list follows the real customer base.
 */
final class LeadKinds
{
    /** Hitap: how the message addresses the person. */
    public const array ADDRESS = ['hocam' => '"X Hocam" (hekim)', 'bey_hanim' => '"X Bey / X Hanım"'];

    /** Which price list the AI may quote: moximu.com's clinic prices, or none (the operator writes the price). */
    public const array PRICES = ['clinic' => 'moximu.com klinik fiyatları', 'none' => 'AI fiyat yazmaz'];

    public const string FALLBACK = 'other';

    /** @var array<string, array{label: string, address: string, prices: string, health: bool, hint: string}> */
    public const array DEFAULTS = [
        'clinic' => ['label' => 'Klinik', 'address' => 'hocam', 'prices' => 'clinic', 'health' => true, 'hint' => 'klinik, poliklinik, diş, dental, estetik, ağız, sağlık merkezi'],
        'practice' => ['label' => 'Muayenehane', 'address' => 'hocam', 'prices' => 'clinic', 'health' => true, 'hint' => 'muayenehane, ofis, tek hekim'],
        'physician' => ['label' => 'Hekim', 'address' => 'hocam', 'prices' => 'clinic', 'health' => true, 'hint' => 'dr, dt, uzm, op, prof, doç, hekim, doktor, cerrah'],
        'hospital' => ['label' => 'Hastane', 'address' => 'bey_hanim', 'prices' => 'clinic', 'health' => true, 'hint' => 'hastane, hospital, tıp merkezi'],
        'health_other' => ['label' => 'Sağlık (diğer)', 'address' => 'bey_hanim', 'prices' => 'clinic', 'health' => true, 'hint' => 'psikolog, diyetisyen, fizyoterapi, işitme, optik, eczane, veteriner'],
        'factory' => ['label' => 'Fabrika / üretici', 'address' => 'bey_hanim', 'prices' => 'none', 'health' => false, 'hint' => 'fabrika, üretim, imalat, sanayi, hurda, geri dönüşüm, makine'],
        'store' => ['label' => 'Mağaza / e-ticaret', 'address' => 'bey_hanim', 'prices' => 'none', 'health' => false, 'hint' => 'mağaza, market, shop, store, e-ticaret, perde, mobilya, butik'],
        'service' => ['label' => 'Hizmet işletmesi', 'address' => 'bey_hanim', 'prices' => 'none', 'health' => false, 'hint' => 'tesisat, nakliyat, temizlik, avukat, hukuk, mimar, muhasebe, danışmanlık, emlak, otel, restoran'],
        'other' => ['label' => 'Diğer', 'address' => 'bey_hanim', 'prices' => 'none', 'health' => false, 'hint' => ''],
    ];

    /** @return array<string, array{label: string, address: string, prices: string, health: bool, hint: string, by?: string, note?: string}> */
    public static function all(): array
    {
        $stored = self::stored();

        return $stored ?? self::DEFAULTS;
    }

    /** @return array<string, string> key → label, for selects */
    public static function options(): array
    {
        return array_map(fn (array $kind): string => $kind['label'], self::all());
    }

    /** @return array{label: string, address: string, prices: string, health: bool, hint: string}|null */
    public static function get(?string $key): ?array
    {
        return $key !== null ? (self::all()[$key] ?? null) : null;
    }

    public static function label(?string $key): ?string
    {
        return self::get($key)['label'] ?? null;
    }

    public static function has(?string $key): bool
    {
        return $key !== null && isset(self::all()[$key]);
    }

    /** The AI may write a price from moximu.com's list only for these kinds. */
    public static function pricesApply(?string $key): bool
    {
        return (self::get($key)['prices'] ?? 'none') === 'clinic';
    }

    public static function isHealth(?string $key): bool
    {
        return (bool) (self::get($key)['health'] ?? false);
    }

    /** "hocam" | "bey_hanim"; unknown kind → the neutral one. */
    public static function address(?string $key): string
    {
        return self::get($key)['address'] ?? 'bey_hanim';
    }

    /**
     * Adds or changes a kind. $by = operator | claude (shown in the list so the operator sees what Claude changed).
     *
     * @param  array{label?: string, address?: string, prices?: string, health?: bool, hint?: string, note?: string}  $data
     */
    public static function put(string $key, array $data, string $by = 'operator'): void
    {
        $key = self::key($key);
        if ($key === '') {
            throw ValidationException::withMessages(['key' => 'Tür anahtarı harf ve rakamdan oluşmalı (örn. factory).']);
        }
        $all = self::all();
        $current = $all[$key] ?? ['label' => '', 'address' => 'bey_hanim', 'prices' => 'none', 'health' => false, 'hint' => ''];
        $label = trim((string) ($data['label'] ?? $current['label']));
        if ($label === '' || mb_strlen($label) > 60) {
            throw ValidationException::withMessages(['label' => 'Türün adı 1-60 karakter olmalı.']);
        }
        $address = (string) ($data['address'] ?? $current['address']);
        $prices = (string) ($data['prices'] ?? $current['prices']);
        if (! isset(self::ADDRESS[$address]) || ! isset(self::PRICES[$prices])) {
            throw ValidationException::withMessages(['address' => 'Hitap ve fiyat listesi seçeneklerden biri olmalı.']);
        }
        $all[$key] = [
            'label' => $label, 'address' => $address, 'prices' => $prices, 'health' => (bool) ($data['health'] ?? $current['health']),
            'hint' => mb_substr(trim((string) ($data['hint'] ?? $current['hint'])), 0, 300), 'by' => $by,
            'note' => mb_substr(trim((string) ($data['note'] ?? ($current['note'] ?? ''))), 0, 300),
        ];
        self::store($all);
    }

    /** Removes a kind; the records that had it fall back to "Diğer" so nothing is lost. "other" cannot go. */
    public static function remove(string $key): int
    {
        $key = self::key($key);
        $all = self::all();
        if ($key === self::FALLBACK || ! isset($all[$key])) {
            throw ValidationException::withMessages(['key' => $key === self::FALLBACK ? '"Diğer" silinemez.' : 'Böyle bir tür yok.']);
        }
        unset($all[$key]);
        self::store($all);

        return \App\Models\SalesLead::query()->where('kind', $key)->update(['kind' => self::FALLBACK]);
    }

    public static function key(string $key): string
    {
        return mb_substr((string) preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower(str_replace(['ı', 'ş', 'ğ', 'ç', 'ö', 'ü', 'İ'], ['i', 's', 'g', 'c', 'o', 'u', 'i'], trim($key)))), 0, 30);
    }

    /** @return array<string, array{label: string, address: string, prices: string, health: bool, hint: string}>|null */
    private static function stored(): ?array
    {
        $raw = AgencySetting::query()->value('lead_kinds');
        $list = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($list) || $list === []) {
            return null;
        }
        $out = [];
        foreach ($list as $key => $kind) {
            if (! is_array($kind) || ! is_string($key) || trim((string) ($kind['label'] ?? '')) === '') {
                continue;
            }
            $out[$key] = [
                'label' => (string) $kind['label'], 'address' => isset(self::ADDRESS[$kind['address'] ?? '']) ? (string) $kind['address'] : 'bey_hanim',
                'prices' => isset(self::PRICES[$kind['prices'] ?? '']) ? (string) $kind['prices'] : 'none', 'health' => (bool) ($kind['health'] ?? false),
                'hint' => (string) ($kind['hint'] ?? ''), 'by' => (string) ($kind['by'] ?? 'operator'), 'note' => (string) ($kind['note'] ?? ''),
            ];
        }
        if (! isset($out[self::FALLBACK])) {
            $out[self::FALLBACK] = self::DEFAULTS[self::FALLBACK];
        }

        return $out;
    }

    /** @param  array<string, array<string, mixed>>  $all */
    private static function store(array $all): void
    {
        $settings = AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        $settings->forceFill(['lead_kinds' => $all])->save();
    }
}
