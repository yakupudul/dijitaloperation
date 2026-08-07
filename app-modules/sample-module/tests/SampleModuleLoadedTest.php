<?php

namespace MoxDop\SampleModule\Tests;

use MoxDop\SampleModule\Providers\SampleModuleServiceProvider;
use Tests\TestCase;

class SampleModuleLoadedTest extends TestCase
{
    public function test_sample_module_service_provider_is_loaded(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(SampleModuleServiceProvider::class)
        );
    }

    public function test_sample_module_registers_loaded_marker(): void
    {
        $this->assertTrue($this->app->bound('moxdop.sample-module.loaded'));
        $this->assertTrue($this->app->make('moxdop.sample-module.loaded'));
    }
}
