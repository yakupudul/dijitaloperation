<?php

namespace Tests\Unit\SeoTasks;

use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeoTextMatchesPhraseTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'exact' => ['diş implant fiyatları', 'implant', true],
            'accusative' => ['implantı ne kadar', 'implant', true],
            'plural possessive' => ['implant fiyatları istanbul', 'implant fiyat', true],
            'k softening' => ['burun estetiği yorumlar', 'burun estetik', true],
            'locative' => ['kadıköyde diş hekimi', 'diş hekimi', true],
            'two suffixes' => ['implantlarını', 'implant', true],
            'short word exact only' => ['kasık ağrısı', 'kas', false],
            'different word' => ['implantasyonel', 'implant', false],
            'phrase order' => ['fiyat implant', 'implant fiyat', false],
            'turkish case folding' => ['İMPLANT FİYATI', 'implant fiyat', true],
        ];
    }

    #[DataProvider('cases')]
    public function test_suffix_tolerant_phrase_matching(string $haystack, string $needle, bool $expected): void
    {
        $this->assertSame($expected, SeoText::matchesPhrase($haystack, $needle));
    }

    public function test_one_fold_implementation(): void
    {
        $this->assertSame(SeoText::fold('Kadıköy Diş İmplantı'), LocationOptions::fold('Kadıköy Diş İmplantı'));
    }

    public function test_fold_fast_path_gives_the_same_text_as_str_ascii(): void
    {
        $slow = static fn (string $text): string => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ',
            mb_strtolower(Str::ascii(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'tr'), 'UTF-8')));
        $texts = ['ÇĞIİÖŞÜ çğıiöşü', 'Diş Sağlığı / Şubelerimiz - Çiğli', '/subeler/karşıyaka-şubesi/', 'IŞIK ışık', 'A-Z a_z 0-9 !?#&%',
            'Ağız ve Diş Sağlığı Polikliniği · Kadıköy', 'Café “Ünlü” – Größe', "tab\there", ''];
        foreach (array_merge($texts, array_map('chr', range(0x20, 0x7E))) as $text) {
            $this->assertSame($slow($text), SeoText::fold($text), $text);
        }
    }
}
