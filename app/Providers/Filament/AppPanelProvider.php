<?php

namespace App\Providers\Filament;

use App\Filament\App\Widgets\OpsActionOverviewWidget;
use App\Http\Middleware\SetOperatorLocale;
use App\Http\Middleware\SetOperatorTimezone;
use App\Support\MoxDopNavigation;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            // Technical tooling only (Runs, Modules, system widgets). Operator product lives at the site root (ADR-044, ADR Faz 1).
            ->path('admin')
            ->homeUrl('/')
            ->viteTheme('resources/css/filament/app/theme.css')
            ->authGuard('web')
            ->login()
            ->passwordReset()
            ->profile()
            // Same authenticator-app secret as the operator /login (set up from Profil › İki adımlı doğrulama).
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->spa()
            // OAuth launch endpoints return redirect()->away() to Google and must use full browser navigation.
            ->spaUrlExceptions([
                '*/integrations/google/*/authorize',
            ])
            ->maxContentWidth(Width::SevenExtraLarge)
            ->colors([
                'primary' => Color::Orange,
                'danger' => Color::Red,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->brandName('MoxDOP')
            ->font('IBM Plex Sans')
            ->databaseNotifications()
            ->navigationGroups([
                NavigationGroup::make(MoxDopNavigation::OPERATIONS)
                    ->collapsed(false),
                NavigationGroup::make(MoxDopNavigation::SYSTEM)
                    ->collapsed(false),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                OpsActionOverviewWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetOperatorLocale::class,
                SetOperatorTimezone::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
