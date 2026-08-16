<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RecurringAutomationServiceProvider;

return [
    AppServiceProvider::class,
    AppPanelProvider::class,
    HorizonServiceProvider::class,
    RecurringAutomationServiceProvider::class,
];
