<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RecurringAutomationServiceProvider;
use App\Providers\SalesServiceProvider;
use App\Providers\SearchConsoleCentralServiceProvider;
use App\Providers\SearchConsoleGroupingServiceProvider;

return [
    AppServiceProvider::class,
    SearchConsoleCentralServiceProvider::class,
    SearchConsoleGroupingServiceProvider::class,
    AppPanelProvider::class,
    HorizonServiceProvider::class,
    RecurringAutomationServiceProvider::class,
    SalesServiceProvider::class,
];
