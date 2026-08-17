<?php

namespace App\Livewire\Demo;

use App\Models\CoreIntegration;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Playbooks\PlaybookReadService;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    #[Url(as: 'advanced_sub', history: true)]
    public ?string $advanced_sub = null;

    #[Url(as: 'ops_sub', history: true)]
    public ?string $ops_sub = null;

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
        if (in_array($this->section, ['files', 'privacy'], true)) {
            $this->advanced_sub = $this->section;
            $this->section = 'advanced';
        }

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
            if ($section !== 'advanced') {
                $this->advanced_sub = null;
            }
            if ($section !== 'operations') {
                $this->ops_sub = null;
            }
        }
    }

    public function setOpsSub(?string $sub): void
    {
        if ($sub === null || in_array($sub, ['defaults', 'playbooks'], true)) {
            $this->section = 'operations';
            $this->ops_sub = $sub ?? 'defaults';
        }
    }

    public function setAdvancedSub(?string $sub): void
    {
        if ($sub === null || in_array($sub, ['files', 'privacy', 'diagnostics'], true)) {
            $this->section = 'advanced';
            $this->advanced_sub = $sub;
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
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $prefs = app(NotificationPreferenceService::class)->listForUser($user);
        if (! isset($prefs[$index])) {
            return;
        }

        $row = $prefs[$index];
        $enabled = ! (bool) ($this->notificationEnabled[$index] ?? $row['in_app_enabled']);
        $this->notificationEnabled[$index] = $enabled;

        app(NotificationPreferenceService::class)->setPreference(
            $user,
            (string) $row['preference_key'],
            $enabled,
            (bool) ($row['email_enabled'] ?? false),
        );

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
            'playbooks' => app(PlaybookReadService::class)->forList(['status' => 'active']),
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
        $merged = array_replace_recursive($base, $overrides);
        $merged['team'] = OperatorUserDirectory::presentationMembers();
        $merged['ai']['openai'] = $this->aiCredentialLabel(ProviderRegistry::OPENAI, OpenAiCredentialResolver::class);
        $merged['ai']['anthropic'] = $this->aiCredentialLabel(ProviderRegistry::ANTHROPIC, AnthropicCredentialResolver::class);
        $merged['ai']['gemini'] = $this->aiCredentialLabel(ProviderRegistry::GEMINI, GeminiCredentialResolver::class);
        $merged['ai']['note'] = 'Provider API keys are configured under Integrations. A stored key is not a live connection.';

        $user = Auth::user();
        if ($user !== null) {
            $prefs = app(NotificationPreferenceService::class)->listForUser($user);
            $merged['notifications'] = array_map(static fn (array $row): array => [
                'event' => $row['label'],
                'channel' => 'In-app (email delivery not implemented)',
                'enabled' => $row['in_app_enabled'],
                'preference_key' => $row['preference_key'],
                'email_enabled' => $row['email_enabled'],
            ], $prefs);
        }

        return $merged;
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

    /**
     * @param  class-string  $resolverClass
     */
    private function aiCredentialLabel(string $provider, string $resolverClass): string
    {
        $integration = CoreIntegration::query()->where('provider', $provider)->first();

        if ($integration === null || ! app($resolverClass)->isConfigured($integration)) {
            return 'Not configured';
        }

        return 'Configured';
    }
}
