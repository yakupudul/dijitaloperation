<?php

namespace App\Filament\App\Clusters\Settings\Pages;

use App\Filament\App\Clusters\SettingsCluster;
use App\Filament\App\Resources\Integrations\Pages\ListIntegrations;
use App\Models\User;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Integrations\Presentation\IntegrationHealthPresenter;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Roles;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class AiControlPlaneSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Control Plane';

    protected static ?string $title = 'AI Control Plane';

    protected static ?string $slug = 'ai-control-plane';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.app.pages.settings.ai-control-plane';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    #[Url(as: 'route', history: true)]
    public string $selectedRoute = AiRouteKeys::WEBSITE_AI_GUIDANCE;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    public function mount(): void
    {
        $this->ensureSelectedRoute();
        $this->fillFormForSelectedRoute();
    }

    public function updatedSelectedRoute(): void
    {
        $this->ensureSelectedRoute();
        $this->fillFormForSelectedRoute();
    }

    public function selectRoute(string $routeKey): void
    {
        $this->selectedRoute = $routeKey;
        $this->ensureSelectedRoute();
        $this->fillFormForSelectedRoute();
    }

    public function form(Schema $schema): Schema
    {
        $descriptor = app(AiRouteRegistry::class)->get($this->selectedRoute);

        return $schema
            ->components([
                Section::make($descriptor['name'])
                    ->description(($descriptor['description'] ?? 'Providers run in order.').' Position 1 is PRIMARY; later steps are FALLBACK for provider availability issues only. Module: '.($descriptor['module'] ?? '—'))
                    ->schema([
                        Repeater::make('steps')
                            ->label('Provider order')
                            ->reorderable()
                            ->addActionLabel('Add provider')
                            ->minItems(1)
                            ->schema([
                                Select::make('provider')
                                    ->label('Provider')
                                    ->options(collect(AiProviderCatalog::supported())
                                        ->mapWithKeys(fn (string $provider): array => [
                                            $provider => AiProviderCatalog::label($provider),
                                        ])
                                        ->all())
                                    ->required()
                                    ->live()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->afterStateUpdated(function (?string $state, callable $set): void {
                                        if (is_string($state) && AiProviderCatalog::isSupported($state)) {
                                            $set('model', AiProviderCatalog::defaultModel($state));
                                        }
                                    }),
                                TextInput::make('model')
                                    ->label('Model')
                                    ->helperText('Recommended default is prefilled. Change only when you need an exact model ID.')
                                    ->required()
                                    ->maxLength(191),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->ensureSelectedRoute();
        $state = $this->form->getState();
        $steps = $state['steps'] ?? [];

        app(AiRouteResolver::class)->saveSteps($this->selectedRoute, is_array($steps) ? $steps : []);

        $name = app(AiRouteRegistry::class)->get($this->selectedRoute)['name'];

        Notification::make()
            ->title('AI route saved')
            ->body($name.' provider order updated.')
            ->success()
            ->send();

        $this->fillFormForSelectedRoute();
    }

    /**
     * @return array{
     *     providers: list<array{provider: string, label: string, status: string, status_label: string}>,
     *     integrations_url: string,
     *     routes: list<array{key: string, name: string, module: string, description: string, selected: bool}>,
     *     route_name: string,
     *     route_key: string,
     *     route_module: string,
     *     resolved_preview: list<array{provider: string, model: string, role: string, eligible: bool, reason: ?string, status_label: string}>
     * }
     */
    protected function getViewData(): array
    {
        $this->ensureSelectedRoute();

        $catalog = app(IntegrationWorkspaceCatalog::class);
        $health = app(IntegrationHealthPresenter::class);
        $registry = app(AiRouteRegistry::class);
        $providers = [];

        foreach (AiProviderCatalog::supported() as $provider) {
            $integration = $catalog->find($provider);
            $status = $health->status($integration, $provider);
            $providers[] = [
                'provider' => $provider,
                'label' => AiProviderCatalog::label($provider),
                'status' => $status,
                'status_label' => IntegrationOperatorStatus::label($status),
            ];
        }

        $routes = [];
        foreach ($registry->all() as $descriptor) {
            $routes[] = [
                'key' => $descriptor['key'],
                'name' => $descriptor['name'],
                'module' => $descriptor['module'],
                'description' => $descriptor['description'],
                'selected' => $descriptor['key'] === $this->selectedRoute,
            ];
        }

        usort($routes, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $descriptor = $registry->get($this->selectedRoute);
        $resolved = app(AiRouteResolver::class)->resolve($this->selectedRoute);
        $preview = [];
        foreach ($resolved->steps as $step) {
            $preview[] = [
                ...$step,
                'status_label' => $step['eligible']
                    ? ($step['role'] === 'PRIMARY' ? 'PRIMARY' : 'FALLBACK')
                    : ($step['reason'] === 'credential_missing' ? 'Not configured' : 'Unavailable'),
            ];
        }

        return [
            'providers' => $providers,
            'integrations_url' => ListIntegrations::getUrl(),
            'routes' => $routes,
            'route_name' => $descriptor['name'],
            'route_key' => $this->selectedRoute,
            'route_module' => $descriptor['module'],
            'resolved_preview' => $preview,
        ];
    }

    private function ensureSelectedRoute(): void
    {
        $registry = app(AiRouteRegistry::class);
        if (! $registry->has($this->selectedRoute)) {
            $keys = $registry->keys();
            $this->selectedRoute = $keys[0] ?? AiRouteKeys::WEBSITE_AI_GUIDANCE;
        }
    }

    private function fillFormForSelectedRoute(): void
    {
        $route = app(AiRouteResolver::class)->resolve($this->selectedRoute);
        $steps = [];

        foreach ($route->steps as $step) {
            $steps[] = [
                'provider' => $step['provider'],
                'model' => $step['model'],
            ];
        }

        if ($steps === []) {
            $steps = [[
                'provider' => AiProviderCatalog::OPENAI,
                'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
            ]];
        }

        $this->form->fill([
            'steps' => $steps,
        ]);
    }
}
