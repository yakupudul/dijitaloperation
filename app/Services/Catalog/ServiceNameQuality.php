<?php

namespace App\Services\Catalog;

use App\Services\SeoTasks\SeoText;

/**
 * Gate for service names that are created without an operator typing them (page titles, AI rows applied by the nightly
 * autofill, the catalog clean-up). A raw page H1 / title is often not a service: "Ekimi" left by a stripped place, a
 * name ending in a connector ("… in"), a sentence ("Kliniğimizde … sunuyoruz."), "Hizmetlerimiz", "/CAM Kaplama".
 * Rules only; an operator-typed name is never checked here.
 */
final class ServiceNameQuality
{
    public const int MAX_WORDS = 6;

    /** Words a name never ends with (Turkish / English connectors left behind by a cut). */
    private const array CONNECTORS = ['in', 'at', 'on', 'of', 'for', 'and', 'the', 'to', 'with', 'near', 'da', 'de', 'ta', 'te', 've', 'ile', 'icin', 'veya', 'ya', 'ki', 'en', 'il'];

    /** Folded single words that are only the head of a compound ("Saç Ekimi" → "Ekimi"); never a service alone. */
    private const array LONE_HEADS = ['ekimi', 'kisti', 'ameliyati', 'estetigi', 'protezi', 'implanti', 'tedavi', 'tedavisi', 'uygulamasi', 'hizmeti',
        'hizmetleri', 'kaplamasi', 'dolgusu', 'temizligi', 'cekimi', 'nakli', 'bakimi', 'eskici', 'merkezi', 'klinigi', 'fiyatlari', 'fiyati', 'treatment', 'surgery', 'service', 'services'];

    public static function acceptable(string $name): bool
    {
        return self::problem($name) === null;
    }

    /** Turkish reason the name is not a service, null when it may be one. */
    public static function problem(string $name): ?string
    {
        $name = trim($name);
        $folded = SeoText::fold($name);
        if (mb_strlen($folded) < 2) {
            return 'Ad boş veya çok kısa.';
        }
        if (preg_match('/^[^\p{L}\p{N}]/u', $name) === 1) {
            return 'Ad noktalama ile başlıyor.';
        }
        if (preg_match('/[.!?…:;]$/u', $name) === 1) {
            return 'Ad bir cümle (noktalama ile bitiyor).';
        }
        $words = explode(' ', $folded);
        if (count($words) > self::MAX_WORDS) {
            return 'Ad bir cümle ('.count($words).' kelime).';
        }
        if (in_array(end($words), self::CONNECTORS, true) || preg_match('/[-–—|,&\/\'’]$/u', $name) === 1) {
            return 'Ad bir bağlaçla bitiyor.';
        }
        foreach ($words as $word) {
            if (preg_match('/(imiz|umuz)(da|de|dan|den|e|a|i|in|le|la)?$/u', $word) === 1 && mb_strlen($word) > 5) {
                return 'Ad birinci çoğul şahıs ("-imiz") içeriyor; bir sayfa başlığı, hizmet değil.';
            }
        }
        if (count($words) === 1 && in_array($words[0], self::LONE_HEADS, true)) {
            return 'Ad tek başına bir tamlama ekinden ibaret ("'.$name.'").';
        }

        return null;
    }

    /**
     * An all-lowercase name with its first letter capitalised ("diş çekimi" → "Diş çekimi"; Turkish i → İ); any other
     * name unchanged (the operator's or the site's own casing stays). A leading "ı" stays: "I" would fold to another
     * name key (IdentityLabelNormalizer reads I as i).
     */
    public static function capitalised(string $name, ?string $language = 'tr'): string
    {
        $name = trim($name);
        $turkish = $language === null || strtolower(substr($language, 0, 2)) === 'tr';
        $lower = mb_strtolower($turkish ? strtr($name, ['I' => 'ı', 'İ' => 'i']) : $name, 'UTF-8');
        if ($name === '' || $name !== $lower || preg_match('/^\p{Ll}/u', $name) !== 1 || str_starts_with($name, 'ı')) {
            return $name;
        }
        $first = mb_substr($name, 0, 1);
        $upper = $turkish && $first === 'i' ? 'İ' : mb_strtoupper($first, 'UTF-8');

        return $upper.mb_substr($name, 1);
    }
}
