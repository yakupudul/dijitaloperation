<?php

namespace App\Livewire\Operator\Settings;

use App\Models\Brand;
use App\Models\User;
use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelPerson;
use App\Panel\Services\PanelAccounts;
use App\Panel\Support\OperatorAccess;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelPermissions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Müşteri paneli (yakup 2026-11-20): which brands use panel.moximu.com, their managers and users, roles,
 * passwords and the address their site form posts to. Shows counts only, never a person's name, phone or note.
 */
#[Layout('operator.layouts.app')]
#[Title('Müşteri paneli')]
final class PanelUsersPage extends Component
{
    #[Url(as: 'marka')]
    public ?int $brandId = null;

    public string $name = '';

    public string $email = '';

    public string $roleId = '';

    public bool $asManager = false;

    public ?array $handover = null;

    public function boot(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && OperatorAccess::allowsSection($user, 'client_panel'), 403);
    }

    private function actor(): int
    {
        return (int) auth()->id();
    }

    /** The open brand; an action without one (a stale tab) ends as "record not found". */
    private function brand(): Brand
    {
        return Brand::query()->findOrFail((int) $this->brandId);
    }

    private function row(int $membershipId): PanelMembership
    {
        return PanelMembership::query()->with('user')->where('brand_id', (int) $this->brandId)->findOrFail($membershipId);
    }

    private function log(string $action, ?PanelMembership $row = null): void
    {
        PanelAudit::record($action, (int) $this->brandId, 'operator', $this->actor(), $row ? ['panel_user_id' => (int) $row->panel_user_id] : []);
    }

    private function say(string $message, string $tone = 'ok'): void
    {
        $this->dispatch('operator-notice', message: $message, tone: $tone);
    }

    public function open(int $brandId): void
    {
        $this->brandId = Brand::query()->findOrFail($brandId)->id;
        $this->reset('name', 'email', 'roleId', 'asManager', 'handover');
        $this->resetValidation();
    }

    /** Switches the panel on for the brand (first time: makes its form address), or off without touching its data. */
    public function toggleBrand(): void
    {
        $brand = $this->brand();
        $existing = PanelBrandSetting::query()->where('brand_id', $brand->id)->first();
        if ($existing === null) {
            PanelBrandSetting::forBrand((int) $brand->id);
            $this->log('brand.opened');
            $this->say('Panel bu marka için açıldı.');

            return;
        }
        $existing->update(['is_enabled' => ! $existing->is_enabled]);
        $this->log($existing->is_enabled ? 'brand.opened' : 'brand.closed');
        $this->say($existing->is_enabled ? 'Panel bu marka için yeniden açıldı.' : 'Panel bu marka için kapatıldı; kullanıcıları giremez, kayıtları durur.');
    }

    public function renewFormAddress(): void
    {
        PanelBrandSetting::forBrand((int) $this->brand()->id)->update(['form_token' => Str::random(48)]);
        $this->log('brand.form_token');
        $this->say('Form adresi yenilendi. Eski adres artık çalışmaz; sitedeki formu yeni adrese bağlayın.');
    }

    public function add(PanelAccounts $accounts): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190']], [], ['name' => 'ad', 'email' => 'e-posta']);
        PanelBrandSetting::forBrand((int) $this->brand()->id);
        [$membership, $password] = $accounts->add((int) $this->brandId, $this->name, $this->email, $this->roleId !== '' ? (int) $this->roleId : null, $this->asManager);
        $this->log('user.added', $membership);
        $this->handover = ['name' => $membership->user->name, 'email' => $membership->user->email, 'password' => $password];
        $this->reset('name', 'email', 'roleId', 'asManager');
    }

    public function setRole(int $membershipId, string $roleId, PanelAccounts $accounts): void
    {
        $row = $this->row($membershipId);
        $role = $accounts->panelRole((int) $roleId);
        abort_if($role === null, 422);
        $row->forceFill(['access_role_id' => $role->id])->save();
        $this->log('user.role', $row);
        $this->say($row->user->name.': '.$role->name);
    }

    public function toggleManager(int $membershipId): void
    {
        $row = $this->row($membershipId);
        if ($row->is_manager && $row->access_role_id === null) {
            $this->say('Önce bir rol verin; yöneticilik kalkınca o rolün yetkileri geçerli olur.', 'error');

            return;
        }
        $row->forceFill(['is_manager' => ! $row->is_manager])->save();
        $this->log($row->is_manager ? 'user.manager' : 'user.not_manager', $row);
        $this->say($row->user->name.($row->is_manager ? ' artık bu markanın yöneticisi.' : ' artık yönetici değil.'));
    }

    public function toggle(int $membershipId): void
    {
        $row = $this->row($membershipId);
        $row->forceFill(['is_active' => ! $row->is_active])->save();
        $this->log($row->is_active ? 'user.opened' : 'user.closed', $row);
        $this->say($row->user->name.($row->is_active ? ' yeniden girebilir.' : ' artık bu markaya giremez.'));
    }

    public function renew(int $membershipId, PanelAccounts $accounts): void
    {
        $row = $this->row($membershipId);
        $this->handover = ['name' => $row->user->name, 'email' => $row->user->email, 'password' => $accounts->resetPassword($row->user)];
        $this->log('user.password', $row);
    }

    /** "Bu kullanıcı olarak gör": admins only; a signed one-minute link to the panel host. */
    public function viewAs(int $membershipId): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $row = $this->row($membershipId);
        $this->redirect(\Illuminate\Support\Facades\URL::temporarySignedRoute('panel.view-as', now()->addMinute(), ['membership' => $row->id, 'operator' => $this->actor()]));
    }

    public function render(): View
    {
        AccessRole::ensureDefaults();
        $settings = PanelBrandSetting::query()->get()->keyBy('brand_id');
        $members = PanelMembership::query()->selectRaw('brand_id, count(*) as n')->groupBy('brand_id')->pluck('n', 'brand_id');
        $brands = Brand::query()->orderBy('name')->get(['id', 'name', 'logo_url']);
        $brand = $this->brandId ? $brands->firstWhere('id', $this->brandId) : null;
        $setting = $brand ? $settings->get($brand->id) : null;

        return view('livewire.operator.settings.panel-users', [
            'waitingTickets' => app(PanelContext::class)->system(fn (): int => \App\Panel\Models\PanelTicket::query()->where('status', 'open')->count()),
            'brands' => $brands,
            'settings' => $settings,
            'members' => $members,
            'brand' => $brand,
            'setting' => $setting,
            'rows' => $brand ? PanelMembership::query()->with(['user', 'role:id,name'])->where('brand_id', $brand->id)->orderByDesc('is_manager')->orderBy('id')->get() : collect(),
            'roles' => AccessRole::query()->where('scope', PanelPermissions::SCOPE_PANEL)->orderBy('id')->get(['id', 'name']),
            // Counts only: the operator side never reads a person's data.
            'people' => $brand ? app(PanelContext::class)->system(fn (): int => PanelPerson::query()->where('brand_id', $brand->id)->count()) : 0,
            'formUrl' => $setting ? route('panel.intake', ['token' => $setting->form_token]) : null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
