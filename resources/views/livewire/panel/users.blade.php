@php($input = 'mt-1.5 h-10 w-full rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10 sm:text-sm')
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Kullanıcılar</h1>
            <p class="page-sub">{{ $rows->count() }} kişi, {{ $rows->where('is_active', true)->count() }} tanesi girebiliyor.</p>
        </div>
        <button type="button" wire:click="$set('adding', true)" class="btn-primary w-full sm:w-auto">Kullanıcı ekle</button>
    </header>

    @if ($handover)
        <section class="card -mt-4 p-5" data-panel-handover>
            <h2 class="text-sm font-semibold text-gray-900">{{ $handover['name'] }} için giriş bilgileri</h2>
            @if ($handover['password'])
                <p class="mt-1 text-sm text-gray-600">Bu şifre yalnız şimdi görünür. Kişiye iletin; ilk girişte kendi şifresini belirleyecek.</p>
                <dl class="mt-3 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="text-gray-500">Adres</dt><dd class="font-medium text-gray-900">{{ route('panel.login') }}</dd>
                    <dt class="text-gray-500">E-posta</dt><dd class="font-medium text-gray-900">{{ $handover['email'] }}</dd>
                    <dt class="text-gray-500">Geçici şifre</dt><dd class="font-mono text-[15px] font-semibold text-gray-900" data-panel-temp-password>{{ $handover['password'] }}</dd>
                </dl>
            @else
                <p class="mt-1 text-sm text-gray-600">Bu e-posta panelde zaten kayıtlıydı; aynı şifresiyle girip bu markayı da görecek.</p>
            @endif
            <button type="button" wire:click="$set('handover', null)" class="btn mt-4">Tamam, ilettim</button>
        </section>
    @endif

    @if ($adding)
        <form wire:submit="add" class="card -mt-4 grid gap-4 p-5 sm:grid-cols-3" data-panel-user-form>
            <label class="block"><span class="text-[13px] font-medium text-gray-700">Ad soyad</span>
                <input type="text" wire:model="name" class="{{ $input }}" autocomplete="off">
                @error('name')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
            </label>
            <label class="block"><span class="text-[13px] font-medium text-gray-700">E-posta</span>
                <input type="email" wire:model="email" class="{{ $input }}" autocomplete="off">
                @error('email')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
            </label>
            <label class="block"><span class="text-[13px] font-medium text-gray-700">Rol</span>
                <select wire:model="roleId" class="{{ $input }}">
                    <option value="">Seçin</option>
                    @foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
                </select>
                @error('roleId')<span class="mt-1 block text-[13px] text-error-600">{{ $message }}</span>@enderror
            </label>
            <div class="flex gap-2 sm:col-span-3">
                <button type="submit" class="btn-primary">Ekle</button>
                <button type="button" wire:click="$set('adding', false)" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
    @endif

    @if ($closing)
        <form wire:submit="close" class="card -mt-4 space-y-3 p-5" data-panel-user-close>
            <h2 class="text-sm font-semibold text-gray-900">{{ $closing->user->name }} kapatılırken işleri kime geçsin?</h2>
            <p class="max-w-[70ch] text-sm text-gray-600">Üzerinde {{ $left[0] }} açık aday ve {{ $left[1] }} bekleyen hatırlatma var. Sahipsiz kalmasınlar diye bir kişiye devredin.</p>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model="handTo" class="h-10 min-w-56 rounded-lg border border-gray-200 bg-white px-3 text-base text-gray-900 sm:h-9 sm:text-sm" aria-label="Devralacak kişi">
                    <option value="">Kimseye atama, hatırlatmalar bana gelsin</option>
                    @foreach ($rows->where('is_active', true)->where('id', '!=', $closing->id) as $other)<option value="{{ $other->panel_user_id }}">{{ $other->user->name }}</option>@endforeach
                </select>
                <button type="submit" class="btn-primary">Devret ve girişi kapat</button>
                <button type="button" wire:click="$set('closingId', null)" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
    @endif

    @if ($editingId && ($edited = $rows->firstWhere('id', $editingId)))
        <form wire:submit="savePermissions" class="card -mt-4 space-y-4 p-5" data-panel-user-permissions>
            <div>
                <h2 class="text-sm font-semibold text-gray-900">{{ $edited->user->name }} için özel yetkiler</h2>
                <p class="mt-1 max-w-[70ch] text-sm text-gray-600">Kutular bu kişinin bugün yapabildiklerini gösterir. Rolü {{ $edited->role?->name ?? 'yok' }}; işaretini değiştirdiğiniz yetki yalnız bu kişi için açılır ya da kapanır, roldeki diğer kişiler etkilenmez.</p>
            </div>
            <div class="grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($catalog as $group => $items)
                    <fieldset>
                        <legend class="mb-1.5 text-[13px] font-medium text-gray-500">{{ $group }}</legend>
                        @foreach ($items as $key => $label)
                            <label class="flex items-start gap-2 py-1 text-sm text-gray-800">
                                <input type="checkbox" wire:model="boxes.{{ str_replace('.', '__', $key) }}" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-gray-900">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary">Kaydet</button>
                @if ($edited->overrides)<button type="button" wire:click="resetPermissions" class="btn">Role geri döndür</button>@endif
                <button type="button" wire:click="$set('editingId', null)" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
    @endif

    <section class="card -mt-4 overflow-x-auto" data-panel-users>
        <table class="data-table table-stack">
            <thead><tr><th>Ad</th><th>Rol</th><th>Durum</th><th>Son giriş</th><th></th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="member-{{ $row->id }}">
                        <td>
                            <span class="block font-medium text-gray-900">{{ $row->user->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $row->user->email }}</span>
                        </td>
                        <td data-label="Rol">
                            @if ($row->is_manager)
                                <span class="text-[13px] text-gray-700">Yönetici</span>
                            @else
                                <select wire:change="setRole({{ $row->id }}, $event.target.value)" class="h-8 rounded-lg border border-gray-200 bg-white px-2 text-[13px]" aria-label="{{ $row->user->name }} rolü">
                                    @unless ($roles->contains('id', $row->access_role_id))<option value="" selected>{{ $row->role?->name ?? 'Rol yok' }}</option>@endunless
                                    @foreach ($roles as $role)<option value="{{ $role->id }}" @selected($role->id === $row->access_role_id)>{{ $role->name }}</option>@endforeach
                                </select>
                            @endif
                        </td>
                        <td data-label="Durum"><x-ta.pill :tone="$row->is_active && $row->user->is_active ? 'ok' : 'info'">{{ $row->is_active && $row->user->is_active ? 'Girebilir' : 'Kapalı' }}</x-ta.pill></td>
                        <td data-label="Son giriş" class="whitespace-nowrap text-xs tabular-nums text-gray-500">{{ $row->user->last_login_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'Henüz girmedi' }}</td>
                        <td>
                            <div class="flex justify-end gap-1.5">
                                <button type="button" wire:click="renew({{ $row->id }})" wire:confirm="{{ $row->user->name }} için yeni bir geçici şifre verilsin mi? Eski şifresi çalışmaz olur." class="btn">Şifre ver</button>
                                @if ($isManager && ! $row->is_manager)
                                    <button type="button" wire:click="editPermissions({{ $row->id }})" class="btn">Özel yetki{{ $row->overrides ? ' ('.(count($row->overrides['allow'] ?? []) + count($row->overrides['deny'] ?? [])).')' : '' }}</button>
                                @endif
                                @unless ($row->is_manager || $row->panel_user_id === $me)
                                    <button type="button" wire:click="toggle({{ $row->id }})" class="btn">{{ $row->is_active ? 'Girişi kapat' : 'Girişi aç' }}</button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="max-w-[72ch]">
        <h2 class="section-title">Roller ne yapabilir</h2>
        <dl class="mt-3 space-y-2 text-sm">
            @foreach ($roles as $role)
                <div class="flex gap-3"><dt class="w-24 shrink-0 font-medium text-gray-900">{{ $role->name }}</dt><dd class="text-gray-600">{{ $role->description }}</dd></div>
            @endforeach
        </dl>
    </section>
</div>
