<?php

namespace App\Livewire\Operator;

use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiOperationLabels;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The ⓘ window next to AI action buttons (`<x-operator.ai-prompt-info operation="…" />` dispatches `ai-prompt-info`):
 * purpose, current prompt (version, code default or not), model; Admin edits it ("Düzenle" → new version through
 * PromptRegistry::publish, same as Ayarlar › AI işlemleri) or goes back to the code default ("Varsayılana dön").
 * Mounted once in the operator layout.
 */
final class AiPromptInfo extends Component
{
    public string $operation = '';

    public bool $editing = false;

    public string $template = '';

    #[On('ai-prompt-info')]
    public function show(string $operation, PromptRegistry $registry): void
    {
        $this->resetErrorBag();
        $this->editing = false;
        $this->template = '';
        $this->operation = $registry->has($operation) ? $operation : '';
    }

    public function close(): void
    {
        $this->operation = '';
        $this->editing = false;
        $this->template = '';
    }

    public function edit(PromptRegistry $registry): void
    {
        $this->authorizeEdit();
        if ($this->operation === '') {
            return;
        }
        $this->template = (string) $registry->current($this->operation)->template;
        $this->editing = true;
    }

    public function save(PromptRegistry $registry): void
    {
        $this->authorizeEdit();
        try {
            $current = $registry->current($this->operation);
            $version = $registry->publish($this->operation, ['template' => $this->template, 'model' => (string) ($current->model ?? '')], auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->editing = false;
        $this->dispatch('operator-notice', message: 'Prompt sürüm '.$version->version.' yayınlandı.', tone: 'info');
    }

    public function resetDefault(PromptRegistry $registry): void
    {
        $this->authorizeEdit();
        try {
            $version = $registry->resetToDefault($this->operation, auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->editing = false;
        $this->dispatch('operator-notice', message: 'Varsayılan prompt geri yüklendi (sürüm '.$version->version.').', tone: 'info');
    }

    public function render(PromptRegistry $registry): View
    {
        $info = null;
        if ($this->operation !== '' && $registry->has($this->operation)) {
            $definition = $registry->definitions()[$this->operation];
            $current = $registry->current($this->operation);
            $info = [
                'label' => AiOperationLabels::for($this->operation),
                'purpose' => (string) ($current->purpose ?: $definition['purpose']),
                'current' => $current,
                'isDefault' => $registry->isDefault($current),
                'model' => (string) ($current->model ?: ($definition['model'] ?? '')),
                'variables' => $definition['variables'],
                'author' => $current->created_by !== null ? $current->loadMissing('creator:id,name')->creator?->name : null,
            ];
        }

        return view('livewire.operator.ai-prompt-info', [
            'info' => $info,
            'canEdit' => PromptRegistry::canEdit(auth()->user()),
        ]);
    }

    private function authorizeEdit(): void
    {
        if (! PromptRegistry::canEdit(auth()->user())) {
            throw new AuthorizationException('Promptları yalnız Admin değiştirebilir.');
        }
    }
}
