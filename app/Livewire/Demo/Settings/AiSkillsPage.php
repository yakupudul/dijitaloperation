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
 * Full Skill Library under /app (read-only V1 — same domain as Filament).
 * Operators must not need /admin for routine AI administration.
 */
#[Layout('operator.layouts.app')]
#[Title('Skill Library')]
class AiSkillsPage extends Component
{
    #[Url(as: 'skill', history: true)]
    public string $selected = '';

    public function mount(): void
    {
        $skills = $this->skillCards();
        if ($this->selected === '' || ! collect($skills)->contains(fn (array $s): bool => $s['slug'] === $this->selected)) {
            $this->selected = $skills[0]['slug'] ?? '';
        }
    }

    public function selectSkill(string $slug): void
    {
        if (collect($this->skillCards())->contains(fn (array $s): bool => $s['slug'] === $slug)) {
            $this->selected = $slug;
        }
    }

    public function render(): View
    {
        $skills = $this->skillCards();
        $selected = collect($skills)->firstWhere('slug', $this->selected);

        return view('livewire.demo.settings.ai-skills', [
            'skills' => $skills,
            'selectedSkill' => $selected,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skillCards(): array
    {
        $registry = app(SkillRegistry::class);
        $agents = app(AgentProfileRegistry::class);
        $cards = [];

        foreach ($registry->all() as $skill) {
            $assignedAgents = [];
            foreach ($agents->all() as $profile) {
                if (in_array($skill->slug, $profile->skillSlugs, true)) {
                    $assignedAgents[] = $profile->name;
                }
            }

            $cards[] = [
                'name' => $skill->name,
                'slug' => $skill->slug,
                'version' => $skill->version,
                'module' => $skill->module,
                'purpose' => $skill->purpose,
                'definition_status' => $skill->definitionStatus,
                'stable_key' => $skill->stableKey(),
                'definition_fingerprint' => $skill->definitionFingerprint(),
                'required_evidence' => $skill->requiredEvidence,
                'optional_evidence' => $skill->optionalEvidence,
                'required_capabilities' => $skill->requiredCapabilities,
                'optional_capabilities' => $skill->optionalCapabilities,
                'when_to_use' => $skill->whenToUse,
                'do_not_use_when' => $skill->doNotUseWhen,
                'methodology' => $skill->methodology,
                'allowed_conclusions' => $skill->allowedConclusions,
                'forbidden_claims' => $skill->effectiveForbiddenClaims(),
                'abstention_rules' => $skill->abstentionRules,
                'success_signals' => $skill->successSignals,
                'failure_signals' => $skill->failureSignals,
                'watch_metrics' => $skill->watchMetrics,
                'reference_sources' => $skill->referenceSources,
                'research_provenance' => $skill->researchProvenance,
                'downstream_domains' => $skill->downstreamDomains,
                'assigned_agents' => $assignedAgents,
                'body_markdown' => $skill->bodyMarkdown,
            ];
        }

        return $cards;
    }
}
