@php($input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-gray-400 dark:border-gray-700 dark:bg-transparent dark:text-white')
<div class="space-y-8">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Roller</h1>
            <p class="page-sub">Bir rol, bir yetki kümesidir. MoxDOP rolleri ekibin hangi menüleri açacağını, müşteri paneli rolleri markaların kullanıcılarının panelde ne yapacağını belirler.</p>
        </div>
        <button type="button" wire:click="create" class="btn-primary w-full sm:w-auto" data-role-create>Rol ekle</button>
    </header>

    <nav class="flex gap-2" aria-label="Kapsam" data-role-scopes>
        @foreach ($scopes as $key => $label)
            <button type="button" wire:click="setScope('{{ $key }}')" @class(['seg', 'seg-active' => $scope === $key])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($creating || $editing)
        <form wire:submit="save" class="card space-y-5 p-5" data-role-form>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,280px)_minmax(0,1fr)]">
                <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Rol adı</span>
                    <input type="text" wire:model="name" class="{{ $input }}" autocomplete="off">
                    @error('name')<span class="mt-1 block text-xs text-error-600">{{ $message }}</span>@enderror
                </label>
                <label class="block"><span class="mb-1.5 block text-[13px] font-medium text-gray-700 dark:text-gray-300">Kısa açıklama</span>
                    <input type="text" wire:model="description" class="{{ $input }}" autocomplete="off">
                </label>
            </div>
            <div class="grid gap-x-10 gap-y-5 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($catalog as $group => $items)
                    <fieldset>
                        <legend class="mb-1.5 text-[13px] font-semibold text-gray-900 dark:text-white">{{ $group }}</legend>
                        @foreach ($items as $key => $label)
                            <label class="flex items-start gap-2 py-1 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" wire:model="permissions" value="{{ $key }}" class="mt-0.5 size-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900/20">{{ $label }}
                            </label>
                        @endforeach
                    </fieldset>
                @endforeach
            </div>
            @if ($scope === 'panel')
                <p class="text-[13px] text-gray-500">Excel'e aktarma bir yetki değildir: yalnız markanın yöneticisi aktarır.</p>
            @else
                <p class="text-[13px] text-gray-500">Yönetici (Admin) hesaplar her şeyi açar; rol yalnız ekip üyelerini sınırlar. Rol verilmemiş ekip üyesi bugünkü gibi çalışır.</p>
            @endif
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">Kaydet</button>
                <button type="button" wire:click="cancel" class="btn-quiet">Vazgeç</button>
            </div>
        </form>
    @endif

    <section class="card overflow-x-auto" data-roles>
        <table class="data-table table-stack">
            <thead><tr><th>Rol</th><th>Yetki</th><th>Kullanan</th><th></th></tr></thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr wire:key="role-{{ $role->id }}">
                        <td>
                            <span class="block font-medium text-gray-900 dark:text-white">{{ $role->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $role->description }}</span>
                        </td>
                        <td data-label="Yetki" class="tabular-nums text-gray-600">{{ count((array) $role->permissions) }} / {{ count(\App\Panel\Support\PanelPermissions::keys($scope)) }}</td>
                        <td data-label="Kullanan" class="tabular-nums text-gray-600">{{ $holders[$role->id] }} kişi</td>
                        <td>
                            <div class="flex justify-end gap-1.5">
                                <button type="button" wire:click="edit({{ $role->id }})" class="btn">Düzenle</button>
                                @if ($holders[$role->id] === 0 && ! $role->is_system)
                                    <button type="button" wire:click="remove({{ $role->id }})" wire:confirm="{{ $role->name }} rolü silinsin mi?" class="btn-quiet">Sil</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
