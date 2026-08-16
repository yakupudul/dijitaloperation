<?php

namespace App\Providers;

use App\Services\RecurringAutomation\Adapters\BusinessOutcomeRecheckScheduleAdapter;
use App\Services\RecurringAutomation\Adapters\CollectionScheduleAdapter;
use App\Services\RecurringAutomation\Adapters\InternalNotificationScheduleAdapter;
use App\Services\RecurringAutomation\Adapters\RecurringReviewScheduleAdapter;
use App\Services\RecurringAutomation\Adapters\ReportDeliveryScheduleAdapter;
use App\Services\RecurringAutomation\RecurringAutomationRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the shared recurring automation engine and bounded domain adapters (Prompt 61).
 */
class RecurringAutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecurringAutomationRegistry::class, function ($app): RecurringAutomationRegistry {
            return new RecurringAutomationRegistry([
                $app->make(CollectionScheduleAdapter::class),
                $app->make(RecurringReviewScheduleAdapter::class),
                $app->make(BusinessOutcomeRecheckScheduleAdapter::class),
                $app->make(InternalNotificationScheduleAdapter::class),
                $app->make(ReportDeliveryScheduleAdapter::class),
            ]);
        });
    }
}
