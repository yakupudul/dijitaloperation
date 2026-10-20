<div class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Kullanıcılar</h1>
        <a href="{{ route('operator.settings') }}" wire:navigate class="text-sm font-medium text-gray-500 hover:text-gray-900 dark:hover:text-white">Düzenle (Ayarlar › Ekip)</a>
    </div>
    <section class="overflow-x-auto" data-users>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800"><tr><th class="py-2 pr-4 font-normal">Ad</th><th class="py-2 pr-4 font-normal">E-posta</th><th class="py-2 pr-4 font-normal">Rol</th><th class="py-2 pr-4 font-normal">Durum</th><th class="py-2 font-normal">Son giriş</th></tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($members as $member)
                    <tr wire:key="user-{{ $member['id'] }}">
                        <td class="py-2.5 pr-4 font-medium text-gray-900 dark:text-white">{{ $member['name'] }}</td>
                        <td class="py-2.5 pr-4 text-gray-500">{{ $member['email'] }}</td>
                        <td class="py-2.5 pr-4 text-xs">{{ $member['role'] }}</td>
                        <td class="py-2.5 pr-4 text-xs"><x-ta.pill :tone="$member['is_active'] ? 'ok' : 'info'">{{ $member['is_active'] ? 'Aktif' : 'Pasif' }}</x-ta.pill></td>
                        <td class="py-2.5 text-xs text-gray-500">{{ $member['last_login'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-sm text-gray-500">Kullanıcı yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
