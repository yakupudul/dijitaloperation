<?php

namespace App\Filament\App\Clusters\Settings\Pages;

use App\Filament\App\Clusters\SettingsCluster;
use App\Models\User;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Roles;
use App\Support\Skills\SkillRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class SkillLibrarySettings extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Skill Library';

    protected static ?string $title = 'Skill Library';

    protected static ?string $slug = 'skill-library';

    protected static ?int $navigationSort = 27;

    protected string $view = 'filament.app.pages.settings.skill-library';

    public ?string $selected = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    public function mount(): void
    {
        $skills = app(SkillRegistry::class)->all();
        $this->selected = $skills[0]->slug ?? null;
    }

    public function selectSkill(string $slug): void
    {
        if (app(SkillRegistry::class)->has($slug)) {
            $this->selected = $slug;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
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
                'required_evidence' => $skill->requiredEvidence,
                'required_capabilities' => $skill->requiredCapabilities,
                'optional_capabilities' => $skill->optionalCapabilities,
                'when_to_use' => $skill->whenToUse,
                'do_not_use_when' => $skill->doNotUseWhen,
                'methodology' => $skill->methodology,
                'allowed_conclusions' => $skill->allowedConclusions,
                'forbidden_claims' => $skill->forbiddenClaims,
                'success_signals' => $skill->successSignals,
                'failure_signals' => $skill->failureSignals,
                'watch_metrics' => $skill->watchMetrics,
                'reference_sources' => $skill->referenceSources,
                'assigned_agents' => $assignedAgents,
            ];
        }

        $selected = null;
        foreach ($cards as $card) {
            if ($card['slug'] === $this->selected) {
                $selected = $card;
                break;
            }
        }

        return [
            'skills' => $cards,
            'selected_skill' => $selected,
            'control_plane_url' => AiControlPlaneSettings::getUrl(),
            'agents_url' => AgentProfilesSettings::getUrl(),
        ];
    }
}
