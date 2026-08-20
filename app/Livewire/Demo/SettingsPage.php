<?php

namespace App\Livewire\Demo;

use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Operator\AgencySettingService;
use App\Services\Operator\OperatorMailConfigService;
use App\Services\Operator\OperatorTeamAccessService;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Playbooks\PlaybookReadService;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Demo\GlobalOperatingFixtures;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Operator\AgencySettingCatalog;
use App\Support\Operator\OperatorMailStatus;
use App\Support\Roles;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Layout('operator.layouts.app')]
class SettingsPage extends Component
{
    use WithFileUploads;

    #[Url(as: 'section', history: true)]
    public string $section = 'general';

    #[Url(as: 'advanced_sub', history: true)]
    public ?string $advanced_sub = null;

    #[Url(as: 'ops_sub', history: true)]
    public ?string $ops_sub = null;

    public string $agency_name = '';

    public string $portal_name = '';

    public string $default_locale = AgencySettingCatalog::LOCALE_EN;

    public string $default_timezone = 'Europe/Istanbul';

    public string $default_display_currency = AgencySettingCatalog::CURRENCY_TRY;

    public string $default_analytical_date_range = AgencySettingCatalog::RANGE_LAST_28;

    public string $week_starts_on = AgencySettingCatalog::WEEK_MONDAY;

    public bool $mail_enabled = false;

    public string $mail_from_name = '';

    public string $mail_from_address = '';

    public string $mail_host = '';

    public string $mail_port = '587';

    public string $mail_username = '';

    public string $mail_password = '';

    public string $mail_encryption = AgencySettingCatalog::MAIL_TLS;

    public bool $clear_mail_password = false;

    public mixed $logo = null;

    public mixed $favicon = null;

    /**
     * @var array<int, bool>
     */
    public array $notificationEnabled = [];

    public string $new_name = '';

    public string $new_email = '';

    public string $new_role = Roles::TEAM_MEMBER;

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public bool $new_is_active = true;

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

    public function title(): string
    {
        return __('operator.nav.settings');
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
        $this->assertAdministrator();

        $validated = $this->validate([
            'agency_name' => ['required', 'string', 'min:2', 'max:120'],
            'portal_name' => ['required', 'string', 'min:2', 'max:120'],
            'default_locale' => ['required', Rule::in(AgencySettingCatalog::locales())],
            'default_timezone' => ['required', Rule::in(AgencySettingCatalog::timezones())],
            'default_display_currency' => ['required', Rule::in(AgencySettingCatalog::currencies())],
            'week_starts_on' => ['required', Rule::in(AgencySettingCatalog::weekStarts())],
            'default_analytical_date_range' => ['required', Rule::in(AgencySettingCatalog::dateRanges())],
            'logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:1024'],
        ]);

        $logo = $this->logo instanceof TemporaryUploadedFile ? $this->logo : null;
        $favicon = $this->favicon instanceof TemporaryUploadedFile ? $this->favicon : null;

        app(AgencySettingService::class)->updateGeneral([
            'agency_name' => $validated['agency_name'],
            'portal_name' => $validated['portal_name'],
            'locale' => $validated['default_locale'],
            'timezone' => $validated['default_timezone'],
            'display_currency' => $validated['default_display_currency'],
            'week_starts_on' => $validated['week_starts_on'],
            'analytical_date_range' => $validated['default_analytical_date_range'],
        ], $logo, $favicon);

        $this->logo = null;
        $this->favicon = null;
        $this->hydrateFromSettings();
        DemoState::flash(__('operator.settings.saved'));

        $preset = $validated['default_analytical_date_range'];
        $bounds = DemoPeriod::bounds($preset);
        DemoState::setPeriod($preset, $bounds['start']->toDateString(), $bounds['end']->toDateString());
    }

    public function addTeamMember(): void
    {
        $this->assertAdministrator();

        $validated = $this->validate([
            'new_name' => ['required', 'string', 'min:2', 'max:120'],
            'new_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'new_role' => ['required', Rule::in(Roles::all())],
            'new_password' => ['required', 'confirmed', Password::defaults()],
            'new_is_active' => ['boolean'],
        ]);

        app(OperatorTeamAccessService::class)->createOperator($this->actor(), [
            'name' => $validated['new_name'],
            'email' => $validated['new_email'],
            'password' => $validated['new_password'],
            'role' => $validated['new_role'],
            'is_active' => (bool) $validated['new_is_active'],
        ]);

        $this->new_name = '';
        $this->new_email = '';
        $this->new_role = Roles::TEAM_MEMBER;
        $this->new_password = '';
        $this->new_password_confirmation = '';
        $this->new_is_active = true;

        DemoState::flash(__('operator.team.created'));
    }

    public function deactivateUser(int $userId): void
    {
        $this->assertAdministrator();
        $target = User::query()->findOrFail($userId);
        app(OperatorTeamAccessService::class)->deactivate($this->actor(), $target);
        DemoState::flash(__('operator.team.deactivated'));
    }

    public function reactivateUser(int $userId): void
    {
        $this->assertAdministrator();
        $target = User::query()->findOrFail($userId);
        app(OperatorTeamAccessService::class)->reactivate($this->actor(), $target);
        DemoState::flash(__('operator.team.reactivated'));
    }

    public function updateUserRole(int $userId, string $role): void
    {
        $this->assertAdministrator();
        $target = User::query()->findOrFail($userId);
        app(OperatorTeamAccessService::class)->assignRole($this->actor(), $target, $role);
        DemoState::flash(__('operator.team.role_updated'));
    }

    public function dehydrate(): void
    {
        $this->mail_password = '';
        $this->clear_mail_password = false;
    }

    public function saveMail(): void
    {
        $this->assertAdministrator();

        $validated = $this->validate([
            'mail_enabled' => ['boolean'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'mail_from_address' => ['nullable', 'email', 'max:255', 'required_if:mail_enabled,true'],
            'mail_host' => ['nullable', 'string', 'max:255', 'required_if:mail_enabled,true'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535', 'required_if:mail_enabled,true'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['required', Rule::in(AgencySettingCatalog::mailEncryptions())],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'clear_mail_password' => ['boolean'],
        ]);

        try {
            app(OperatorMailConfigService::class)->update([
                'mail_enabled' => (bool) $validated['mail_enabled'],
                'mail_from_name' => (string) ($validated['mail_from_name'] ?? ''),
                'mail_from_address' => (string) ($validated['mail_from_address'] ?? ''),
                'mail_host' => (string) ($validated['mail_host'] ?? ''),
                'mail_port' => $validated['mail_port'] ?? null,
                'mail_username' => (string) ($validated['mail_username'] ?? ''),
                'mail_encryption' => $validated['mail_encryption'],
                'mail_password' => $validated['mail_password'] ?? '',
                'clear_password' => (bool) $validated['clear_mail_password'],
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'mail_password' => __('operator.mail.incomplete'),
            ]);
        }

        $this->mail_password = '';
        $this->clear_mail_password = false;
        $this->hydrateFromSettings();
        DemoState::flash(__('operator.mail.saved'));
    }

    public function sendTestEmail(): void
    {
        $this->assertAdministrator();

        $result = app(OperatorMailConfigService::class)->sendTestEmail($this->actor());
        DemoState::flash($result['message']);
    }

    public function clearOperatorMail(): void
    {
        $this->assertAdministrator();
        app(OperatorMailConfigService::class)->clearOperatorSmtp();
        $this->mail_password = '';
        $this->clear_mail_password = false;
        $this->hydrateFromSettings();
        DemoState::flash(__('operator.mail.cleared'));
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
            'isAdmin' => $this->actor()->hasRole(Roles::ADMIN),
            'localeOptions' => AgencySettingCatalog::localeOptions(),
            'timezoneOptions' => AgencySettingCatalog::timezoneOptions(),
            'currencyOptions' => AgencySettingCatalog::currencyOptions(),
            'weekStartOptions' => AgencySettingCatalog::weekStartOptions(),
            'dateRangeOptions' => AgencySettingCatalog::dateRangeOptions(),
            'mailEncryptionOptions' => AgencySettingCatalog::mailEncryptionOptions(),
            'roleOptions' => [
                Roles::ADMIN => __('operator.team.roles.admin'),
                Roles::TEAM_MEMBER => __('operator.team.roles.member'),
            ],
            'mail' => OperatorMailStatus::presentation(),
            'branding' => app(AgencySettingService::class)->branding(),
        ])->layoutData([
            'title' => __('operator.nav.settings'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedSettings(): array
    {
        $agency = app(AgencySettingService::class)->current();
        $merged = [
            'general' => [
                'agency_name' => $agency->agency_name,
                'portal_name' => $agency->portal_name,
                'default_locale' => $agency->locale,
                'default_timezone' => $agency->timezone,
                'default_display_currency' => $agency->display_currency,
                'default_analytical_date_range' => $agency->analytical_date_range,
                'week_starts_on' => $agency->week_starts_on,
            ],
            'team' => OperatorUserDirectory::presentationMembers(),
            'notifications' => [],
            'operations' => [],
            'ai' => [
                'openai' => $this->aiCredentialLabel(ProviderRegistry::OPENAI, OpenAiCredentialResolver::class),
                'anthropic' => $this->aiCredentialLabel(ProviderRegistry::ANTHROPIC, AnthropicCredentialResolver::class),
                'gemini' => $this->aiCredentialLabel(ProviderRegistry::GEMINI, GeminiCredentialResolver::class),
                'note' => __('operator.settings.ai.credentials_note'),
            ],
            'files' => [
                'disk' => __('operator.settings.files.private_disk'),
                'avatar_disk' => __('operator.settings.files.public_disk'),
                'max_upload_mb' => 10,
                'allowed' => __('operator.settings.files.allowed'),
                'blocked' => __('operator.settings.files.blocked'),
                'note' => __('operator.settings.files.note'),
            ],
            'privacy' => [
                'retention' => __('operator.settings.privacy.retention'),
                'export' => __('operator.settings.privacy.export'),
                'purge' => __('operator.settings.privacy.purge'),
            ],
            'advanced' => [
                'environment' => config('app.env'),
                'canonical_surface' => '/',
            ],
        ];

        $user = Auth::user();
        if ($user !== null) {
            $prefs = app(NotificationPreferenceService::class)->listForUser($user);
            $merged['notifications'] = array_map(static fn (array $row): array => [
                'event' => __('operator.notifications.events.'.$row['preference_key']),
                'channel' => __('operator.notifications.in_app_only'),
                'enabled' => $row['in_app_enabled'],
                'preference_key' => $row['preference_key'],
                'email_enabled' => $row['email_enabled'],
            ], $prefs);
        }

        return $merged;
    }

    private function hydrateFromSettings(): void
    {
        $agency = app(AgencySettingService::class)->current();
        $this->agency_name = (string) $agency->agency_name;
        $this->portal_name = (string) $agency->portal_name;
        $this->default_locale = AgencySettingCatalog::isLocale((string) $agency->locale)
            ? (string) $agency->locale
            : AgencySettingCatalog::LOCALE_EN;
        $this->default_timezone = AgencySettingCatalog::isTimezone((string) $agency->timezone)
            ? (string) $agency->timezone
            : 'Europe/Istanbul';
        $this->default_display_currency = AgencySettingCatalog::isCurrency((string) $agency->display_currency)
            ? (string) $agency->display_currency
            : AgencySettingCatalog::CURRENCY_TRY;
        $this->default_analytical_date_range = AgencySettingCatalog::isDateRange((string) $agency->analytical_date_range)
            ? (string) $agency->analytical_date_range
            : AgencySettingCatalog::RANGE_LAST_28;
        $this->week_starts_on = AgencySettingCatalog::isWeekStart((string) $agency->week_starts_on)
            ? (string) $agency->week_starts_on
            : AgencySettingCatalog::WEEK_MONDAY;
        $this->mail_enabled = (bool) $agency->mail_enabled;
        $this->mail_from_name = (string) ($agency->mail_from_name ?? '');
        $this->mail_from_address = (string) ($agency->mail_from_address ?? '');
        $this->mail_host = (string) ($agency->mail_host ?? '');
        $this->mail_port = $agency->mail_port !== null ? (string) $agency->mail_port : '587';
        $this->mail_username = (string) ($agency->mail_username ?? '');
        $this->mail_encryption = AgencySettingCatalog::isMailEncryption((string) ($agency->mail_encryption ?? ''))
            ? (string) $agency->mail_encryption
            : AgencySettingCatalog::MAIL_TLS;
        $this->mail_password = '';
        $this->clear_mail_password = false;

        $this->notificationEnabled = [];
        $user = Auth::user();
        if ($user !== null) {
            foreach (app(NotificationPreferenceService::class)->listForUser($user) as $i => $row) {
                $this->notificationEnabled[$i] = (bool) ($row['in_app_enabled'] ?? false);
            }
        }
    }

    /**
     * @param  class-string  $resolverClass
     */
    private function aiCredentialLabel(string $provider, string $resolverClass): string
    {
        $integration = CoreIntegration::query()->where('provider', $provider)->first();

        if ($integration === null || ! app($resolverClass)->isConfigured($integration)) {
            return __('operator.states.not_configured');
        }

        return __('operator.states.configured');
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function assertAdministrator(): void
    {
        app(OperatorTeamAccessService::class)->assertAdministrator($this->actor());
    }
}
