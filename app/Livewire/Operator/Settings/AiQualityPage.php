<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Operations\AiQualityReport;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › AI kalitesi (admin): per AI-producing source how much output was accepted, edited, rejected, and its
 * recorded cost; sources with low acceptance are flagged.
 */
#[Layout('operator.layouts.app')]
#[Title('AI kalitesi')]
final class AiQualityPage extends Component
{
    #[Url]
    public int $days = 30;

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }

    public function render(AiQualityReport $report): View
    {
        return view('livewire.operator.settings.ai-quality', ['report' => $report->build($this->days)]);
    }
}
