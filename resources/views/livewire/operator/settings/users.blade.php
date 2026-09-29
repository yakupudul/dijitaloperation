<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">Kullanıcılar</h1>
        <a href="{{ route('operator.settings') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Düzenle (Ayarlar › Ekip) →</a>
    </div>
    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" data-users>
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-gray-950"><tr><th class="px-4 py-2">Ad</th><th class="px-4 py-2">E-posta</th><th class="px-4 py-2">Rol</th><th class="px-4 py-2">Durum</th><th class="px-4 py-2">Son giriş</th></tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($members as $member)
                    <tr wire:key="user-{{ $member['id'] }}">
                        <td class="px-4 py-2 font-medium text-gray-800 dark:text-white/90">{{ $member['name'] }}</td>
                        <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $member['email'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $member['role'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $member['is_active'] ? 'Aktif' : 'Pasif' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $member['last_login'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-4 text-sm text-gray-500">Kullanıcı yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
