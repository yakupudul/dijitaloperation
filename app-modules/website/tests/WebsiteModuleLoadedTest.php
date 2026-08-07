<?php

namespace MoxDop\Website\Tests;

use MoxDop\Website\Providers\WebsiteServiceProvider;
use Tests\TestCase;

class WebsiteModuleLoadedTest extends TestCase
{
    public function test_website_module_service_provider_is_loaded(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(WebsiteServiceProvider::class)
        );
    }

    public function test_website_module_registers_loaded_marker(): void
    {
        $this->assertTrue($this->app->bound('moxdop.website.loaded'));
        $this->assertTrue($this->app->make('moxdop.website.loaded'));
    }
}
