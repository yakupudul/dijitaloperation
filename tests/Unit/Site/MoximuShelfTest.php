<?php

namespace Tests\Unit\Site;

use App\Services\Site\DailyBlog;
use PHPUnit\Framework\TestCase;

/** moximu.com/rehber shelf (yakup 2026-11-18): a guide about the advertising rules sat in "Genel". */
final class MoximuShelfTest extends TestCase
{
    public function test_a_rules_guide_goes_on_the_mevzuat_shelf(): void
    {
        $this->assertSame('Mevzuat', DailyBlog::shelf('Estetik klinik reklam kuralları ve kullanılabilecek ifadeler'));
        $this->assertSame('Mevzuat', DailyBlog::shelf('Hasta yorumu ve önce sonra fotoğrafı paylaşılır mı?'));
        $this->assertSame('Mevzuat', DailyBlog::shelf('İl sağlık müdürlüğü reklam cezası hangi durumlarda verilir?'));
        $this->assertSame('Google Ads', DailyBlog::shelf('Google Ads hangi gün ve saatlerde yayınlamak mantıklı?'));
        $this->assertSame('Web sitesi', DailyBlog::shelf('Hastane web sitesi yenileme nasıl planlanır?'));
    }
}
