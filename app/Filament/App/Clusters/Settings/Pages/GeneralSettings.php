<?php

namespace App\Filament\App\Clusters\Settings\Pages;

use App\Filament\App\Clusters\SettingsCluster;
use App\Services\Operator\AgencySettingService;
use App\Support\Operator\OperatorClock;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class GeneralSettings extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = 'General';

    protected static ?string $title = 'General';

    protected static ?string $slug = 'general';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.app.pages.settings.general';

    /**
     * @return array{agency: string, product: string, signed_in_as: string, environment: string, timezone: string, locale: string, operator_settings_url: string}
     */
    protected function getViewData(): array
    {
        $branding = app(AgencySettingService::class)->branding();
        $settings = app(AgencySettingService::class)->current();

        return [
            'agency' => $branding['agency_name'],
            'product' => $branding['portal_name'],
            'signed_in_as' => Auth::user()?->name ?? 'Unknown',
            'environment' => (string) config('app.env'),
            'timezone' => OperatorClock::timezone(Auth::user()),
            'locale' => (string) ($settings->locale ?? 'en'),
            'operator_settings_url' => url('/settings'),
        ];
    }
}
