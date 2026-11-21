@php($input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-gray-400 dark:border-gray-700 dark:bg-transparent dark:text-white')
<div class="space-y-8">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
        <h1 class="page-title">Müşteri paneli</h1>
        <p class="page-sub">Markaların {{ config('panel.domain') }} adresindeki paneli: kim girer, hangi rolle. Burada yalnız sayılar görünür; adayların adı, telefonu ve notları markanın kendi panelinde kalır.</p>
        </div>
        <a href="{{ route('operator.settings.panel-support') }}" wire:navigate class="btn">Talepler ve duyurular{{ $waitingTickets ? ' ('.$waitingTickets.')' : '' }}</a>
    </header>

    <div class="grid gap-8 xl:grid-cols-[300px_minmax(0,1fr)]">
        <section class="card self-start" data-panel-brands>
            @foreach ($brands as $item)
                @php($on = $settings->get($item->id))
                <button type="button" wire:click="open({{ $item->id }})" wire:key="brand-{{ $item->id }}" @class(['card-row w-full text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]', 'bg-gray-50 dark:bg-white/[0.04]' => $brand?->id === $item->id])>
                    <x-ta.brand-avatar :name="$item->name" :logo="$item->logo_url" size="sm" />
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $item->name }}</span>
                    @if ($on)
                        @if ($on->is_enabled && in_array($item->id, $idle, true))
                            <x-ta.pill tone="look">14 gündür giren yok</x-ta.pill>
                        @else
                            <x-ta.pill :tone="$on->is_enabled ? 'ok' : 'info'">{{ $on->is_enabled ? ((int) ($members[$item->id] ?? 0)).' kişi' : 'Kapalı' }}</x-ta.pill>
                        @endif
                    @endif
                </button>
            @endforeach
        </section>

        <div class="min-w-0 space-y-8">
            @if (! $brand)
                <p class="text-sm text-gray-500">Bir marka seçin. Paneli açtığınız markaya yönetici ve kullanıcı ekleyebilirsiniz.</p>
            @else
                <section class="flex flex-wrap items-start justify-between gap-3" data-panel-brand>
                    <div class="min-w-0">
                        <h2 class="section-title">{{ $brand->name }}</h2>
                        <p class="mt-0.5 text-sm text-gray-500">
                            @if (! $setting) Panel bu marka için henüz açılmadı.
                            @elseif (! $setting->is_enabled) Panel kapalı. Kullanıcıları giremez, {{ $people }} aday kaydı duruyor.
                            @else Panel açık. {{ $rows->count() }} kullanıcı, {{ $people }} aday kaydı. @endif
                        </p>
                    </div>
                    <button type="button" wire:click="toggleBrand" @if ($setting?->is_enabled) wire:confirm="{{ $brand->name }} için panel kapatılsın mı? Kullanıcıları giremez; kayıtlar silinmez." @endif @class([$setting?->is_enabled ? 'btn' : 'btn-primary'])>{{ ! $setting ? 'Paneli aç' : ($setting->is_enabled ? 'Paneli kapat' : 'Paneli yeniden aç') }}</button>
                </section>

                @if ($handover)
                    <section class="card p-5" data-panel-handover>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $handover['name'] }} için giriş bilgileri</h3>
                        @if ($handover['password'])
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Bu şifre yalnız şimdi görünür. Kişi ilk girişte kendi şifresini belirler.</p>
                            <dl class="mt-3 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                                <dt class="text-gray-500">Adres</dt><dd class="font-medium text-gray-900 dark:text-white">{{ route('panel.login') }}</dd>
                                <dt class="text-gray-500">E-posta</dt><dd class="font-medium text-gray-900 dark:text-white">{{ $handover['email'] }}</dd>
                                <dt class="text-gray-500">Geçici şifre</dt><dd class="font-mono text-[15px] font-semibold text-gray-900 dark:text-white" data-panel-temp-password>{{ $handover['password'] }}</dd>
                            </dl>
                        @else
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Bu e-posta panelde zaten kayıtlıydı; aynı şifresiyle girip bu markayı da görecek.</p>
                        @endif
                        <button type="button" wire:click="$set('handover', null)" class="btn mt-4">Tamam</button>
                    </section>
                @endif

                <section data-panel-members>
                    <div class="section-head"><h3 class="section-title">Kullanıcılar</h3><span class="section-count">{{ $rows->count() }}</span></div>
                    <div class="card overflow-x-auto">
                        <table class="data-table table-stack">
                            <thead><tr><th>Ad</th><th>Rol</th><th>Durum</th><th>Son giriş</th><th></th></tr></thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr wire:key="member-{{ $row->id }}">
                                        <td>
                                            <span class="block font-medium text-gray-900 dark:text-white">{{ $row->user->name }}@if ($row->is_manager) <span class="font-normal text-gray-500">(yönetici)</span>@endif</span>
                                            <span class="block text-xs text-gray-500">{{ $row->user->email }}</span>
                                        </td>
                                        <td data-label="Rol">
                                            <select wire:change="setRole({{ $row->id }}, $event.target.value)" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px] dark:border-gray-700 dark:bg-transparent" aria-label="{{ $row->user->name }} rolü">
                                                @if ($row->access_role_id === null)<option value="" selected>{{ $row->is_manager ? 'Yönetici (her şey)' : 'Rol yok' }}</option>@endif
                                                @foreach ($roles as $role)<option value="{{ $role->id }}" @selected($role->id === $row->access_role_id)>{{ $role->name }}</option>@endforeach
                                            </select>
                                        </td>
                                        <td data-label="Durum"><x-ta.pill :tone="$row->is_active && $row->user->is_active ? 'ok' : 'info'">{{ $row->is_active && $row->user->is_active ? 'Girebilir' : 'Kapalı' }}</x-ta.pill></td>
                                        <td data-label="Son giriş" class="whitespace-nowrap text-xs tabular-nums text-gray-500">{{ $row->user->last_login_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'Henüz girmedi' }}</td>
                                        <td class="sm:min-w-[330px]">
                                            <div class="flex flex-wrap justify-end gap-1.5">
                                                @if ($isAdmin && $setting?->is_enabled && $row->is_active)
                                                    <button type="button" wire:click="viewAs({{ $row->id }})" class="btn" data-panel-view-as>Bu kullanıcı olarak gör</button>
                                                @endif
                                                <button type="button" wire:click="renew({{ $row->id }})" wire:confirm="{{ $row->user->name }} için yeni bir geçici şifre verilsin mi? Eski şifresi çalışmaz olur." class="btn">Şifre ver</button>
                                                <button type="button" wire:click="toggleManager({{ $row->id }})" class="btn">{{ $row->is_manager ? 'Yöneticiliği al' : 'Yönetici yap' }}</button>
                                                <button type="button" wire:click="toggle({{ $row->id }})" class="btn">{{ $row->is_active ? 'Girişi kapat' : 'Girişi aç' }}</button>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5"><x-ta.empty-state size="sm" icon="none" title="Bu markada henüz kullanıcı yok." message="İlk kişiyi yönetici olarak ekleyin; kendi ekibini o ekler." class="px-0! py-0!" /></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section data-panel-add>
                    <div class="section-head"><h3 class="section-title">Kullanıcı ekle</h3></div>
                    <form wire:submit="add" class="card grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Ad soyad</span>
                            <input type="text" wire:model="name" class="{{ $input }}" autocomplete="off">
                            @error('name')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                        </label>
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700 dark:text-gray-300">E-posta</span>
                            <input type="email" wire:model="email" class="{{ $input }}" autocomplete="off">
                            @error('email')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                        </label>
                        <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Rol</span>
                            <select wire:model="roleId" class="{{ $input }}">
                                <option value="">Seçin</option>
                                @foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
                            </select>
                            @error('roleId')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                        </label>
                        <label class="flex items-center gap-2 self-end pb-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="asManager" class="size-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900/20">Markanın yöneticisi
                        </label>
                        <div class="sm:col-span-2 xl:col-span-4"><button type="submit" class="btn-primary">Ekle</button></div>
                    </form>
                    <p class="mt-2 text-[13px] text-gray-500">Yönetici her şeyi görür, Excel'e aktarır ve kendi ekibini ekler. Rollerin yetkilerini <a href="{{ route('operator.settings.roles') }}" wire:navigate class="link-neutral">Roller</a> ekranından değiştirirsiniz.</p>
                </section>

                @if ($setting)
                    <section data-panel-form-address>
                        <div class="section-head"><h3 class="section-title">Site formu adresi</h3></div>
                        <p class="max-w-[75ch] text-sm text-gray-600 dark:text-gray-400">Markanın sitesindeki form bu adrese gönderdiğinde talep panele aday olarak düşer. Adres gizlidir; markayı adresin kendisi belirler.</p>
                        <p class="mt-2 break-all rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-[13px] text-gray-800 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200" data-panel-form-url>{{ $formUrl }}</p>
                        <button type="button" wire:click="renewFormAddress" wire:confirm="Form adresi yenilensin mi? Eski adres çalışmaz olur." class="btn mt-3">Adresi yenile</button>
                    </section>

                    <section data-panel-modules>
                        <div class="section-head"><h3 class="section-title">Bu markada neler açık</h3></div>
                        <p class="max-w-[75ch] text-sm text-gray-600 dark:text-gray-400">Kapattığınız bölüm, markanın yöneticisi dahil bütün kullanıcılarında menüden kalkar. Kayıtlar silinmez.</p>
                        <div class="card mt-3">
                            @foreach (\App\Panel\Models\PanelBrandSetting::MODULES as $key => [$label])
                                <div class="card-row" wire:key="module-{{ $key }}">
                                    <span class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">{{ $label }}</span>
                                    <x-ta.pill :tone="$setting->moduleOn($key) ? 'ok' : 'info'">{{ $setting->moduleOn($key) ? 'Açık' : 'Kapalı' }}</x-ta.pill>
                                    <button type="button" wire:click="toggleModule('{{ $key }}')" class="btn">{{ $setting->moduleOn($key) ? 'Kapat' : 'Aç' }}</button>
                                </div>
                            @endforeach
                            <div class="card-row">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">Salt okunur</span>
                                    <span class="block text-[13px] text-gray-500">Kullanıcılar her şeyi görür ve indirir; kayıt ekleyemez, değiştiremez. Ayrılan marka için.</span>
                                </span>
                                <x-ta.pill :tone="$setting->is_read_only ? 'look' : 'info'">{{ $setting->is_read_only ? 'Açık' : 'Kapalı' }}</x-ta.pill>
                                <button type="button" wire:click="toggleReadOnly" class="btn">{{ $setting->is_read_only ? 'Kaldır' : 'Salt okunur yap' }}</button>
                            </div>
                        </div>
                    </section>
                @endif
            @endif
        </div>
    </div>
</div>
