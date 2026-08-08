<?php

namespace App\Filament\App\Clusters;

use App\Support\MoxDopNavigation;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings shell for future Integrations / Team / Security pages.
 * Only register pages that have real content.
 */
class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $clusterBreadcrumb = 'Settings';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::SYSTEM;

    protected static ?int $navigationSort = 90;
}
