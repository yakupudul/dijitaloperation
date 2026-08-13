<?php

namespace App\Livewire\Demo;

use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Settings')]
class SettingsPage extends Component
{
    #[Url(as: 'section', history: true)]
    public string $section = 'general';

    public string $agency_name = '';

    public string $default_locale = '';

    public string $default_timezone = '';

    public string $default_display_currency = '';

    public string $default_analytical_date_range = '';

    public string $week_starts_on = '';

    public string $default_dashboard_mode = '';

    public string $outcome_review_window = '';

    /**
     * @var array<int, bool>
     */
    public array $notificationEnabled = [];

    public function mount(): void
    {
        $ids = array_column(GlobalOperatingFixtures::settingsSections(), 'id');
        if (! in_array($this->section, $ids, true)) {
            $this->section = 'general';
        }

        $this->hydrateFromSettings();
    }

    public function setSection(string $section): void
    {
        $ids = array_column(GlobalOperatingFixtures::settingsSections(), 'id');
        if (in_array($section, $ids, true)) {
            $this->section = $section;
        }
    }

    public function saveGeneral(): void
    {
        DemoState::mergeSettingsOverrides([
            'general' => [
                'agency_name' => trim($this->agency_name) !== '' ? trim($this->agency_name) : 'Moximu',
                'default_locale' => trim($this->default_locale) !== '' ? trim($this->default_locale) : 'tr_TR',
                'default_timezone' => trim($this->default_timezone) !== '' ? trim($this->default_timezone) : 'Europe/Istanbul',
                'default_display_currency' => trim($this->default_display_currency) !== '' ? trim($this->default_display_currency) : 'TRY',
                'default_analytical_date_range' => $this->default_analytical_date_range !== '' ? $this->default_analytical_date_range : 'last_28',
                'week_starts_on' => $this->week_starts_on !== '' ? $this->week_starts_on : 'monday',
            ],
        ]);
        $this->hydrateFromSettings();
    }

    public function saveOperations(): void
    {
        DemoState::mergeSettingsOverrides([
            'operations' => [
                'default_dashboard_mode' => $this->default_dashboard_mode !== '' ? $this->default_dashboard_mode : 'My Work',
                'outcome_review_window' => $this->outcome_review_window !== '' ? $this->outcome_review_window : '14 days',
            ],
        ]);
        $this->hydrateFromSettings();
    }

    public function toggleNotification(int $index): void
    {
        $settings = $this->mergedSettings();
        $rows = $settings['notifications'] ?? [];
        if (! isset($rows[$index]) || ! is_array($rows[$index])) {
            return;
        }

        $enabled = ! (bool) ($this->notificationEnabled[$index] ?? $rows[$index]['enabled'] ?? false);
        $this->notificationEnabled[$index] = $enabled;

        $overrides = [];
        foreach ($rows as $i => $row) {
            $overrides[$i] = [
                'event' => $row['event'],
                'channel' => $row['channel'],
                'enabled' => (bool) ($this->notificationEnabled[$i] ?? $row['enabled'] ?? false),
            ];
        }
        $overrides[$index]['enabled'] = $enabled;

        DemoState::mergeSettingsOverrides([
            'notifications' => $overrides,
        ]);
        $this->hydrateFromSettings();
    }

    public function resetDemo(): void
    {
        DemoState::reset();
        DemoState::flash('Demo Mode reset to seed state.');
        $this->hydrateFromSettings();
    }

    public function render(): View
    {
        $settings = $this->mergedSettings();
        $routes = collect(app(AiRouteRegistry::class)->all())
            ->map(fn (array $r): array => [
                'key' => $r['key'],
                'name' => $r['name'],
                'module' => $r['module'],
            ])
            ->sortBy('key')
            ->values()
            ->all();
        $agents = collect(app(AgentProfileRegistry::class)->all())
            ->map(fn ($profile): array => [
                'name' => $profile->name,
                'slug' => $profile->slug,
                'status' => $profile->status,
                'module' => $profile->module,
                'route' => $profile->aiRouteKey,
            ])
            ->values()
            ->all();
        $skills = collect(app(SkillRegistry::class)->all())
            ->map(fn (SkillDefinition $skill): array => [
                'name' => $skill->name,
                'slug' => $skill->slug,
                'module' => $skill->module,
                'version' => $skill->version,
                'purpose' => $skill->purpose,
            ])
            ->values()
            ->all();

        return view('livewire.demo.settings', [
            'sections' => GlobalOperatingFixtures::settingsSections(),
            'settings' => $settings,
            'aiRoutes' => $routes,
            'aiAgents' => $agents,
            'aiSkills' => $skills,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedSettings(): array
    {
        $base = GlobalOperatingFixtures::settingsPayload();
        $overrides = DemoState::settingsOverrides();

        return array_replace_recursive($base, $overrides);
    }

    private function hydrateFromSettings(): void
    {
        $settings = $this->mergedSettings();
        $general = $settings['general'] ?? [];
        $operations = $settings['operations'] ?? [];
        $this->agency_name = (string) ($general['agency_name'] ?? '');
        $this->default_locale = (string) ($general['default_locale'] ?? '');
        $this->default_timezone = (string) ($general['default_timezone'] ?? '');
        $this->default_display_currency = (string) ($general['default_display_currency'] ?? '');
        $this->default_analytical_date_range = (string) ($general['default_analytical_date_range'] ?? 'last_28');
        $this->week_starts_on = (string) ($general['week_starts_on'] ?? 'monday');
        $this->default_dashboard_mode = (string) ($operations['default_dashboard_mode'] ?? 'My Work');
        $this->outcome_review_window = (string) ($operations['outcome_review_window'] ?? '14 days');

        $this->notificationEnabled = [];
        foreach ($settings['notifications'] ?? [] as $i => $row) {
            $this->notificationEnabled[$i] = (bool) ($row['enabled'] ?? false);
        }
    }
}
