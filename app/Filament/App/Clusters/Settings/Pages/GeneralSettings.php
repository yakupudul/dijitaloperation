<?php

namespace App\Filament\App\Clusters\Settings\Pages;

use App\Filament\App\Clusters\SettingsCluster;
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
     * @return array{agency: string, product: string, signed_in_as: string, environment: string}
     */
    protected function getViewData(): array
    {
        return [
            'agency' => 'Moximu',
            'product' => 'MoxDOP — Agency Operations OS',
            'signed_in_as' => Auth::user()?->name ?? 'Unknown',
            'environment' => (string) config('app.env'),
        ];
    }
}
