<?php

namespace App\Livewire\Demo\Settings;

use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('AI Control Plane')]
class AiControlPlanePage extends Component
{
    #[Url(as: 'route', history: true)]
    public string $selectedRoute = '';

    /** @var list<array{provider: string, model: string}> */
    public array $steps = [];

    public function mount(): void
    {
        $keys = app(AiRouteRegistry::class)->keys();
        if ($this->selectedRoute === '' || ! app(AiRouteRegistry::class)->has($this->selectedRoute)) {
            $this->selectedRoute = $keys[0] ?? '';
        }
        $this->loadSteps();
    }

    public function selectRoute(string $routeKey): void
    {
        if (! app(AiRouteRegistry::class)->has($routeKey)) {
            return;
        }
        $this->selectedRoute = $routeKey;
        $this->loadSteps();
    }

    public function addStep(): void
    {
        $this->steps[] = [
            'provider' => AiProviderCatalog::OPENAI,
            'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
        ];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function save(): void
    {
        if ($this->selectedRoute === '') {
            return;
        }

        app(AiRouteResolver::class)->saveSteps($this->selectedRoute, $this->steps);
        DemoState::flash(__('operator.flash.ai_route_saved'));
        $this->loadSteps();
    }

    public function render(): View
    {
        $registry = app(AiRouteRegistry::class);
        $routes = collect($registry->all())->sortBy('key')->values()->all();
        $resolved = $this->selectedRoute !== ''
            ? app(AiRouteResolver::class)->resolve($this->selectedRoute)
            : null;

        return view('livewire.demo.settings.ai-control-plane', [
            'routes' => $routes,
            'providers' => AiProviderCatalog::supported(),
            'resolved' => $resolved,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    private function loadSteps(): void
    {
        if ($this->selectedRoute === '') {
            $this->steps = [];

            return;
        }

        $resolved = app(AiRouteResolver::class)->resolve($this->selectedRoute);
        $this->steps = collect($resolved->steps)
            ->map(fn (array $step): array => [
                'provider' => $step['provider'],
                'model' => $step['model'],
            ])
            ->values()
            ->all();

        if ($this->steps === []) {
            $this->steps = [[
                'provider' => AiProviderCatalog::OPENAI,
                'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
            ]];
        }
    }
}
