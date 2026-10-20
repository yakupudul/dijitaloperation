@php
    $active = collect($members)->where('is_active', true)->count();
    $roleLabels = [\App\Support\Roles::ADMIN => __('operator.team.roles.admin'), \App\Support\Roles::TEAM_MEMBER => __('operator.team.roles.member')];
@endphp
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Kullanıcılar</h1>
            <p class="page-sub">{{ count($members) }} kullanıcı, {{ $active }} aktif.</p>
        </div>
        <a href="{{ route('operator.settings', ['section' => 'team']) }}" wire:navigate class="btn w-full sm:w-auto" title="Ayarlar › Ekip ve Yetkiler">Ekibi düzenle</a>
    </header>
    <section class="card -mt-4 overflow-x-auto" data-users>
        <table class="data-table">
            <thead><tr><th>Ad</th><th class="hidden md:table-cell">E-posta</th><th>Rol</th><th class="hidden sm:table-cell">Durum</th><th class="hidden text-right! lg:table-cell">Son giriş</th></tr></thead>
            <tbody>
                @forelse ($members as $member)
                    <tr wire:key="user-{{ $member['id'] }}">
                        <td>
                            <span class="flex min-w-0 items-center gap-2.5">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200" aria-hidden="true">{{ $member['initials'] }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $member['name'] }}</span>
                                    <span class="block truncate text-xs text-gray-500 md:hidden">{{ $member['email'] }}</span>
                                </span>
                            </span>
                        </td>
                        <td class="hidden text-gray-500 md:table-cell">{{ $member['email'] }}</td>
                        <td class="text-[13px] text-gray-700 dark:text-gray-300">{{ $roleLabels[$member['role']] ?? $member['role'] }}</td>
                        <td class="hidden sm:table-cell"><x-ta.pill :tone="$member['is_active'] ? 'ok' : 'info'">{{ $member['is_active'] ? 'Aktif' : 'Pasif' }}</x-ta.pill></td>
                        <td class="hidden whitespace-nowrap text-right text-xs tabular-nums text-gray-500 lg:table-cell">{{ $member['last_login'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ta.empty-state size="sm" icon="none" title="Kullanıcı yok." class="px-0! py-0!" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
