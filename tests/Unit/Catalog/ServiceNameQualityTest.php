<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\ServiceNameQuality;
use App\Support\Options\LocationOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Service names: places only at the start / end with their connector; the gate keeps page-title junk out of the catalog. */
final class ServiceNameQualityTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function names(): array
    {
        return [
            'district word inside a phrase stays' => ['Yumurtalık Kisti', 'Yumurtalık Kisti'],
            'district at the start without two words left stays' => ['Araç Eskici', 'Araç Eskici'],
            'country glued to a slash is not a place' => ['CAD/CAM Kaplama', 'CAD/CAM Kaplama'],
            'english connector goes with the city' => ['Hair Transplant in Istanbul', 'Hair Transplant'],
            'leading city with two words left' => ['Ankara Diş Hekimi', 'Diş Hekimi'],
            'leading city with its suffix' => ['Ankara’da Saç Ekimi', 'Saç Ekimi'],
            'trailing city after a dash' => ['Saç Ekimi - Ankara', 'Saç Ekimi'],
            'trailing city with its suffix' => ["Saç Ekimi Ankara'da", 'Saç Ekimi'],
            'city and district at the end' => ['Burun Estetiği İstanbul Kadıköy', 'Burun Estetiği'],
            'leading district' => ['Kaş Saç Ekimi', 'Saç Ekimi'],
            'a place alone stays' => ['Ankara', 'Ankara'],
        ];
    }

    #[DataProvider('names')]
    public function test_places_are_stripped_only_at_the_edges_of_a_service_name(string $name, string $expected): void
    {
        $this->assertSame($expected, LocationOptions::stripServiceName($name)['text']);
    }

    public function test_query_stripping_still_removes_places_anywhere(): void
    {
        $this->assertSame('implant fiyatları', LocationOptions::strip('ankara implant fiyatları')['text']);
        $this->assertSame('diş kliniği', LocationOptions::strip('diş kliniği ankara')['text']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function gate(): array
    {
        return [
            'lone suffix word' => ['Ekimi', false],
            'lone suffix word 2' => ['Kisti', false],
            'ends with a connector' => ['Hair Transplant in', false],
            'ends with a turkish connector' => ['Saç Ekimi ve', false],
            'sentence with a period' => ['Diş beyazlatma nasıl yapılır.', false],
            'question' => ['Diş beyazlatma nedir?', false],
            'more than six words' => ['En iyi fiyatlarla Ankara diş implant tedavisi burada', false],
            'first person plural' => ['Hizmetlerimiz', false],
            'first person plural with suffix' => ['Kliniğimizde implant', false],
            'starts with a slash' => ['/CAM Kaplama', false],
            'starts with punctuation' => ['- Lazer Epilasyon', false],
            'plain service' => ['Saç Ekimi', true],
            'one word service' => ['Ortodonti', true],
            'with brackets' => ['Şeffaf Plak (Invisalign)', true],
            'cad cam' => ['CAD/CAM Kaplama', true],
            'english service' => ['Hair Transplant', true],
            'shoulder is not first person plural' => ['Donmuş Omuz Tedavisi', true],
        ];
    }

    #[DataProvider('gate')]
    public function test_the_gate_rejects_names_that_are_not_services(string $name, bool $acceptable): void
    {
        $this->assertSame($acceptable, ServiceNameQuality::acceptable($name), (string) ServiceNameQuality::problem($name));
    }
}
