<?php

namespace Tests\Unit\SeoTasks;

use App\Services\SeoTasks\QuestionMarks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuestionMarksTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function lines(): array
    {
        return [
            // Live headings of 2026-11 that lacked the mark.
            'particle' => ['Kist tekrar oluşur mu', true],
            'particle with suffix' => ['Liposuction için uygun aday mısınız', true],
            'question word + aorist' => ['Talep başı maliyet nasıl bulunur', true],
            'ne kadar + aorist' => ['İyileşme süreci ne kadar sürer', true],
            'hangi + -malı' => ['Hangi diş macununu kullanmalı', true],
            'ends with nedir' => ['Çene kireçlenmesi (artroz) nedir', true],
            'ends with ne' => ['Polinükleotid nedir, somon DNA ile ilişkisi ne', true],
            'after a colon' => ['Kısa yanıt: ölçü ve planlama nasıl yapılır', true],
            'verb in -ler after question word' => ['Hangi gösterge neyi söyler', true],
            'ne zaman dolar' => ['Kemik ne zaman dolar', true],
            'two particles + verb' => ['İmplant ömrünü marka mı hasta mı belirler', true],
            'predicate' => ['Pıhtı neden bu kadar önemli', true],
            'first word neden' => ['Neden önce İşletme Profili', true],
            'capital I' => ['İMPLANT NE KADAR DAYANIR', true],
            // Not questions, or already marked.
            'has mark' => ['Kist tekrar oluşur mu?', false],
            'mark inside' => ['İmplant nedir? Kısa rehber', false],
            'noun phrase with particles' => ['Bonding mi ortodonti mi kararı', false],
            'subordinate clause' => ['Hangi kanaldan ve hangi hizmetten talep geldiği', false],
            'after colon a noun' => ['Çürük mü, diş taşı mı, renklenme mi: farkları', false],
            'plural noun after hangi' => ['Hangi seçenekler', false],
            'statement' => ['Kemik tozu greftin işlevi', false],
            'faq heading' => ['Sık sorulan sorular', false],
            'one word' => ['Nedir', false],
        ];
    }

    #[DataProvider('lines')]
    public function test_a_question_without_a_mark_is_found(string $line, bool $missing): void
    {
        $this->assertSame($missing, QuestionMarks::missing($line), $line);
    }

    public function test_english_and_other_languages(): void
    {
        $this->assertTrue(QuestionMarks::missing('How long does an implant last', 'en'));
        $this->assertFalse(QuestionMarks::missing('Implant care after surgery', 'en'));
        $this->assertFalse(QuestionMarks::missing('Che cosa decide se basta una seduta', 'it'));
    }

    public function test_the_mark_is_put_back_in_headings_title_and_seo_title(): void
    {
        $article = QuestionMarks::article([
            'title' => 'Reklam optimizasyonu adım adım nasıl yapılır',
            'meta_title' => 'Kist tekrar oluşur mu.',
            'html' => '<p>Giriş.</p><h2>Kist tekrar oluşur mu</h2><p>Bazen.</p><h3 id="a"><strong>Ne zaman hekime başvurmalı</strong> </h3><h2>Sık sorulan sorular</h2>',
            'language' => 'tr',
        ]);

        $this->assertSame('Reklam optimizasyonu adım adım nasıl yapılır?', $article['title']);
        $this->assertSame('Kist tekrar oluşur mu?', $article['meta_title']);
        $this->assertStringContainsString('<h2>Kist tekrar oluşur mu?</h2>', $article['html']);
        $this->assertStringContainsString('<h3 id="a"><strong>Ne zaman hekime başvurmalı?</strong></h3>', $article['html']);
        $this->assertStringContainsString('<h2>Sık sorulan sorular</h2>', $article['html']);
        $this->assertSame([], QuestionMarks::problems($article));
    }

    public function test_a_title_is_not_pushed_over_sixty_characters_and_the_gate_names_it(): void
    {
        $title = 'Diş kliniği reklamında bütçe ile yayın günü nasıl dengelenir'; // 60 characters
        $this->assertSame(60, mb_strlen($title));
        $article = QuestionMarks::article(['title' => $title, 'html' => '']);

        $this->assertSame($title, $article['title']);
        $this->assertSame([$title], QuestionMarks::problems($article));
        $this->assertSame('Bütçe nasıl dengelenir?', QuestionMarks::fix('Bütçe nasıl dengelenir.', 'tr', 60), 'a full stop is replaced, not added to');
    }
}
