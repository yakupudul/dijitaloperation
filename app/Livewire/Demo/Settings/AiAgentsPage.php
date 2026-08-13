<?php

namespace App\Livewire\Demo\Settings;

use App\Support\Agents\AgentProfileRegistry;
use App\Support\Skills\SkillRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Full Agent Profile catalog under /app (read-only V1 — same domain as Filament).
 * Operators must not need /system for routine AI administration.
 */
#[Layout('operator.layouts.app')]
#[Title('Agent Profiles')]
class AiAgentsPage extends Component
{
    #[Url(as: 'agent', history: true)]
    public string $selected = '';

    public function mount(): void
    {
        $profiles = $this->profileCards();
        if ($this->selected === '' || ! collect($profiles)->contains(fn (array $p): bool => $p['slug'] === $this->selected)) {
            $this->selected = $profiles[0]['slug'] ?? '';
        }
    }

    public function selectAgent(string $slug): void
    {
        if (collect($this->profileCards())->contains(fn (array $p): bool => $p['slug'] === $slug)) {
            $this->selected = $slug;
        }
    }

    public function render(): View
    {
        $profiles = $this->profileCards();
        $selected = collect($profiles)->firstWhere('slug', $this->selected);

        return view('livewire.demo.settings.ai-agents', [
            'profiles' => $profiles,
            'selectedProfile' => $selected,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profileCards(): array
    {
        $skills = app(SkillRegistry::class);
        $profiles = [];

        foreach (app(AgentProfileRegistry::class)->all() as $profile) {
            $assigned = [];
            foreach ($profile->skillSlugs as $slug) {
                if ($skills->hasForModule($profile->module, $slug)) {
                    $skill = $skills->getForModule($profile->module, $slug);
                    $assigned[] = [
                        'name' => $skill->name,
                        'slug' => $skill->slug,
                        'version' => $skill->version,
                    ];
                } else {
                    $assigned[] = [
                        'name' => $slug,
                        'slug' => $slug,
                        'version' => null,
                    ];
                }
            }

            $profiles[] = [
                'name' => $profile->name,
                'slug' => $profile->slug,
                'version' => $profile->version,
                'module' => $profile->module,
                'status' => $profile->status,
                'purpose' => $profile->purpose,
                'ai_route_key' => $profile->aiRouteKey,
                'skills' => $assigned,
                'allowed_data' => $profile->allowedDataScope,
                'allowed_operations' => $profile->allowedOperations,
                'forbidden_operations' => $profile->forbiddenOperations,
                'output_contract' => $profile->outputContract,
                'success_criteria' => $profile->successCriteria,
            ];
        }

        return $profiles;
    }
}
