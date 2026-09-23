<?php

namespace App\Livewire\Demo\Settings;

use App\Models\AgencySetting;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Demo\DemoState;
use App\Support\Roles;
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

    public string $monthlyBudget = '';

    public function mount(): void
    {
        $keys = app(AiRouteRegistry::class)->keys();
        if ($this->selectedRoute === '' || ! app(AiRouteRegistry::class)->has($this->selectedRoute)) {
            $this->selectedRoute = $keys[0] ?? '';
        }
        $this->loadSteps();
        $this->monthlyBudget = number_format(app(AiBudget::class)->monthlyBudget(), 2, '.', '');
    }

    /** Monthly AI budget in USD (0 = no limit). Admin only. */
    public function saveBudget(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->validate(['monthlyBudget' => ['required', 'numeric', 'min:0', 'max:100000']]);
        $settings = AgencySetting::query()->first() ?? AgencySetting::query()->create(['agency_name' => 'MoxDOP', 'portal_name' => 'MoxDOP']);
        $settings->forceFill(['ai_monthly_budget_usd' => round((float) $this->monthlyBudget, 2)])->save();
        DemoState::flash('Aylık AI bütçesi kaydedildi.');
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
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
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

        $usage = app(AiBudget::class)->monthSummary();
        $routeSpend = collect($usage['by_route'])->keyBy('route_key');

        return view('livewire.demo.settings.ai-control-plane', [
            'usage' => $usage,
            'routeSpend' => $routeSpend,
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
