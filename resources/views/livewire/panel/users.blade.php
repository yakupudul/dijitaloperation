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
