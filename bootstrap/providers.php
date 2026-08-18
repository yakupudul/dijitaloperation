<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RecurringAutomationServiceProvider;
use App\Providers\SalesServiceProvider;

return [
    AppServiceProvider::class,
    AppPanelProvider::class,
    HorizonServiceProvider::class,
    RecurringAutomationServiceProvider::class,
    SalesServiceProvider::class,
];
