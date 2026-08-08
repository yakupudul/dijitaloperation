<?php

namespace MoxDop\GoogleAds\Tests;

use Tests\TestCase;

class GoogleAdsModuleLoadedTest extends TestCase
{
    public function test_module_service_provider_is_loaded(): void
    {
        $this->assertTrue((bool) app('moxdop.google-ads.loaded'));
    }
}
