<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Support\Integrations\Presentation\IntegrationPresentationRegistry;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ListIntegrations extends ListRecords
{
    protected static string $resource = IntegrationResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Integrations';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Connect and manage the services MoxDOP uses for data and intelligence.';
    }

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        // No generic "Add integration" — cards bootstrap via Set up.
        return [];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.app.integrations.hub')
                ->viewData([
                    'hub' => app(IntegrationWorkspaceCatalog::class)->hub(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        // Hub replaces the generic integrations table as the primary UI.
        return $table
            ->columns([])
            ->headerActions([])
            ->recordActions([])
            ->paginated(false);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'mox-integrations-page',
        ];
    }

    public function setupProvider(string $provider): mixed
    {
        if (! IntegrationPresentationRegistry::isOperatorReady($provider)) {
            Notification::make()
                ->title('Integration not available')
                ->warning()
                ->send();

            return null;
        }

        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap($provider);

        Notification::make()
            ->title($integration->wasRecentlyCreated ? 'Integration ready to configure' : 'Opening integration')
            ->success()
            ->send();

        return redirect()->to(IntegrationResource::getUrl('view', ['record' => $integration]));
    }
}
