<?php

namespace Tests\Unit;

use App\Services\Sales\FreeRadarMatcher;
use PHPUnit\Framework\TestCase;

/** The free intent radar's buyer-intent detector: real requests trip demand, seller/review posts do not. */
final class FreeRadarMatcherTest extends TestCase
{
    public function test_buyer_intent_is_detected(): void
    {
        $this->assertTrue(FreeRadarMatcher::hasDemand('İyi bir web tasarım firması arıyorum, teklif almak istiyorum.'));
        $this->assertTrue(FreeRadarMatcher::hasDemand('SEO için ajans önerir misiniz?'));
        $this->assertTrue(FreeRadarMatcher::hasDemand('Kurumsal site yaptırılacaktır, tavsiyeniz var mı?'));
    }

    public function test_seller_and_review_posts_do_not_trip_demand(): void
    {
        // A review recommending a firm is not a buyer request.
        $this->assertFalse(FreeRadarMatcher::hasDemand('Bu firmayı herkese tavsiye ederim, çok memnun kaldım.'));
        $this->assertFalse(FreeRadarMatcher::hasDemand('Referanslarımız ve çalışmalarımız için sitemizi ziyaret edin.'));
    }
}
