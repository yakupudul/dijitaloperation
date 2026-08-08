<?php

namespace MoxDop\GoogleBusinessProfile\Tests;

use Tests\TestCase;

class GoogleBusinessProfileModuleLoadedTest extends TestCase
{
    public function test_module_service_provider_is_loaded(): void
    {
        $this->assertTrue((bool) app('moxdop.google-business-profile.loaded'));
    }
}
