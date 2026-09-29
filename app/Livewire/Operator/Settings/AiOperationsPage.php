<?php

namespace App\Livewire\Operator\Settings;

use App\Models\PromptVersion;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › AI işlemleri ve promptlar: one row per AI operation with its current prompt version. Faz 8 adds editing,
 * version history, "önceki sürüme dön" and the run history; Faz 0 lists what is stored.
 */
#[Layout('operator.layouts.app')]
#[Title('AI işlemleri ve promptlar')]
final class AiOperationsPage extends Component
{
    public function render(): View
    {
        return view('livewire.operator.settings.ai-operations', [
            'operations' => PromptVersion::query()->where('is_current', true)->orderBy('operation')->get(['operation', 'version', 'purpose', 'model'])
                ->map(fn (PromptVersion $row): array => ['operation' => (string) $row->operation, 'version' => (int) $row->version, 'purpose' => (string) $row->purpose, 'model' => (string) ($row->model ?? '')])
                ->all(),
        ]);
    }
}
