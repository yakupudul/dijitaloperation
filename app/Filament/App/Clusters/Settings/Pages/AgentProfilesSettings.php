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

class AgentProfilesSettings extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Agent Profiles';

    protected static ?string $title = 'Agent Profiles';

    protected static ?string $slug = 'agent-profiles';

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.app.pages.settings.agent-profiles';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $skills = app(SkillRegistry::class);
        $profiles = [];

        foreach (app(AgentProfileRegistry::class)->all() as $profile) {
            $assigned = [];
            foreach ($profile->skillSlugs as $slug) {
                if ($skills->has($slug)) {
                    $skill = $skills->get($slug);
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

        return [
            'profiles' => $profiles,
            'control_plane_url' => AiControlPlaneSettings::getUrl(),
            'skills_url' => SkillLibrarySettings::getUrl(),
        ];
    }
}
