<?php

namespace Tests\Unit\Site;

use App\Models\Page;
use App\Services\Site\ClusterOverlaps;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * yakup, 2026-10-09 ("çakışma tespitleri hatalı"): the Dentocare 301 proposals of the Onarım masası. Different types or
 * different questions of one subject are not merged; true copies are.
 */
final class ClusterOverlapsSameNeedTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function pairs(): array
    {
        return [
            'all-on-6 is not all-on-4' => ['/en/our-treatments/all-on-4-implant-treatment/', '/en/our-treatments/all-on-6-implant-treatment/', false],
            'all-on-6 is not all-on-4 (tr)' => ['/tedavilerimiz/kahramanmaras-all-on-4-implant-tedavisi/', '/tedavilerimiz/kahramanmaras-all-on-6-implant-tedavisi/', false],
            'single canal is not multiple canals' => ['/en/our-treatments/root-canal-treatment-in-teeth-with-multiple-canals/', '/en/our-treatments/root-canal-treatment-in-single-canal-teeth/', false],
            'dermal filler is not baby teeth filling' => ['/en/can-baby-teeth-be-filled-the-most-frequently-asked-questions-by-parents/', '/en/what-is-dermal-filler-treatment-in-children-and-when-is-it-needed/', false],
            'pink aesthetics is not gingivectomy' => ['/en/our-treatments/gum-surgery-gingivectomy-etc/', '/en/our-treatments/gum-aesthetics-pink-aesthetics/', false],
            'nutrition is not hygiene' => ['/implant-bakimi-gunluk-agiz-hijyeni/', '/implant-sonrasi-beslenme-ne-yemeli/', false],
            'duration question is not the service' => ['/tedavilerimiz/kahramanmaras-implant-uygulamalari/', '/implant-tedavisi-ne-kadar-surer/', false],
            'success rate is not what is' => ['/apikal-rezeksiyon-nedir/', '/soru-ve-cevap/apikal-rezeksiyon-basari-orani-nedir/', false],
            'aesthetic centre is not smile design' => ['/kahramanmaras-estetik-gulus-tasarimi/', '/kahramanmaras-estetik-dis-merkezi/', false],
            'whitening service is not office vs home' => ['/soru-ve-cevap/ofis-tipi-dis-beyazlatma-ile-ev-tipi-arasindaki-fark-nedir/', '/kahramanmaras-dis-beyazlatma-uygulamasi/', false],
            'swallowing fluoride is not intelligence' => ['/soru-ve-cevap/flor-uygulamasi-cocugumun-zekasini-veya-gelisimini-olumsuz-etkiler-mi/', '/soru-ve-cevap/flor-uygulamasi-yutulursa-zararli-mi/', false],
            'same-day implant is not implant' => ['/implant/', '/tek-seansta-implant/', false],
            'mr question copy' => ['/soru-ve-cevap/implant-uygulamalari-mr-cekimine-engel-midir/', '/soru-ve-cevap/implant-uygulamalari-mr-cekimine-engel-olur-mu/', true],
            'anaesthesia question copy' => ['/soru-ve-cevap/implant-uygulamalari-sirasinda-genel-anestezi-sart-midir/', '/soru-ve-cevap/implant-uygulamalari-sirasinda-genel-anestezi-gerekir-mi/', true],
            'bonding copy' => ['/tedavilerimiz/kahramanmaras-bonding-uygulamasi/', '/kahramanmaras-bonding-tedavisi/', true],
            'root canal copy' => ['/tedavilerimiz/kahramanmaras-kanal-tedavisi/', '/kahramanmaras-kanal-tedavisi/', true],
            'whitening methods copy' => ['/en/teeth-whitening-methods-in-kahramanmaras/', '/en/teeth-whitening-application-methods-in-kahramanmaras/', true],
            'what is implant' => ['/implant/', '/implant-tedavisi-nedir/', true],
        ];
    }

    #[DataProvider('pairs')]
    public function test_only_pages_about_the_same_thing_are_merged(string $main, string $page, bool $same): void
    {
        $this->assertSame($same, ClusterOverlaps::sameNeed(new Page(['path' => $main]), new Page(['path' => $page])));
    }
}
