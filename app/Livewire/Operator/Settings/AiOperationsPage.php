<?php

namespace App\Livewire\Operator\Settings;

use App\Models\PromptVersion;
use App\Services\Ai\AiRouteResolver;
use App\Services\Prompts\PromptRegistry;
use App\Services\Prompts\PromptRunStats;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › AI işlemleri ve promptlar (Faz 8, admin only): list of every AI operation with model, version and the last
 * 30 days of runs; detail with the template, variables, context sources, output shape, model, version history
 * ("Bu sürüme dön") and recent runs. Saving publishes a new version.
 */
#[Layout('operator.layouts.app')]
#[Title('AI işlemleri ve promptlar')]
final class AiOperationsPage extends Component
{
    #[Url(as: 'islem')]
    public string $operation = '';

    public string $template = '';

    public string $model = '';

    public function mount(PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        if ($this->operation !== '') {
            $this->open($this->operation, $registry);
        }
    }

    public function open(string $operation, PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        if (! $registry->has($operation)) {
            $this->operation = '';

            return;
        }
        $current = $registry->current($operation);
        $this->operation = $operation;
        $this->template = (string) $current->template;
        $this->model = (string) ($current->model ?? '');
        $this->resetErrorBag();
    }

    public function close(): void
    {
        $this->operation = '';
    }

    public function save(PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        try {
            $version = $registry->publish($this->operation, ['template' => $this->template, 'model' => $this->model], auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->template = (string) $version->template;
        session()->flash('status', 'Sürüm '.$version->version.' yayınlandı.');
    }

    public function revertTo(int $version, PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        try {
            $new = $registry->revert($this->operation, $version, auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->template = (string) $new->template;
        $this->model = (string) ($new->model ?? '');
        session()->flash('status', 'Sürüm '.$version.' geri yüklendi (yeni sürüm '.$new->version.').');
    }

    public function render(PromptRegistry $registry, PromptRunStats $stats, AiRouteResolver $routes): View
    {
        $summary = $stats->summary();
        $current = PromptVersion::query()->where('is_current', true)->get(['operation', 'version', 'model'])->keyBy('operation');
        $rows = [];
        foreach ($registry->definitions() as $key => $definition) {
            $version = $current->get($key);
            $rows[] = [
                'operation' => $key,
                'purpose' => $definition['purpose'],
                'model' => (string) ($version->model ?? $definition['model'] ?? ''),
                'version' => $version?->version,
                'runs' => $summary[$key]['runs'] ?? 0,
                'avg_ms' => $summary[$key]['avg_ms'] ?? null,
                'cost' => $summary[$key]['cost'] ?? 0.0,
            ];
        }

        $detail = null;
        if ($this->operation !== '' && $registry->has($this->operation)) {
            $definition = $registry->definition($this->operation);
            $detail = [
                'definition' => $definition,
                'current' => $registry->current($this->operation),
                'versions' => PromptVersion::query()->with('creator:id,name')->where('operation', $this->operation)->orderByDesc('version')->limit(50)->get(),
                'runs' => $stats->recent($this->operation),
                'models' => $this->modelOptions($routes),
            ];
        }

        return view('livewire.operator.settings.ai-operations', ['rows' => $rows, 'detail' => $detail]);
    }

    /** @return array<string, string> value ("provider:model", '' = route model) => label */
    private function modelOptions(AiRouteResolver $routes): array
    {
        $options = ['' => 'Rota modeli (varsayılan)'];
        foreach (AiProviderCatalog::supported() as $provider) {
            if (! $routes->providerReady($provider)) {
                continue;
            }
            $models = array_keys((array) config('moxdop-ai-pricing.models.'.$provider, []));
            $models[] = AiProviderCatalog::defaultModel($provider);
            foreach (array_unique($models) as $model) {
                if ($model === '*' || str_starts_with((string) $model, 'text-embedding')) {
                    continue;
                }
                $options[$provider.':'.$model] = AiProviderCatalog::label($provider).' · '.$model;
            }
        }
        if ($this->model !== '' && ! isset($options[$this->model])) {
            $options[$this->model] = $this->model;
        }

        return $options;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
