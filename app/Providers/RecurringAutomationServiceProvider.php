<?php

namespace App\Providers;

use App\Services\RecurringAutomation\RecurringAutomationRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the shared recurring automation engine (Prompt 61).
 * Domain adapters are registered in a later step.
 */
class RecurringAutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecurringAutomationRegistry::class, function (): RecurringAutomationRegistry {
            return new RecurringAutomationRegistry([]);
        });
    }
}
