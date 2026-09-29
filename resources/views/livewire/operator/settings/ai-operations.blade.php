<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">AI işlemleri ve promptlar</h1>
        <p class="mt-1 text-sm text-gray-500">Her AI işlemi: amaç, şablon, bağlam kaynakları, çıktı şeması, model ve sürüm geçmişi (Faz 8).</p>
    </div>
    <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" data-ai-operations>
        @forelse ($operations as $row)
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-700" wire:key="op-{{ $row['operation'] }}">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['operation'] }} <span class="text-xs text-gray-400">v{{ $row['version'] }}</span></p>
                    @if ($row['purpose'] !== '')<p class="text-xs text-gray-500">{{ $row['purpose'] }}</p>@endif
                </div>
                <span class="text-xs text-gray-500">{{ $row['model'] !== '' ? $row['model'] : '—' }}</span>
            </div>
        @empty
            <p class="px-5 py-4 text-sm text-gray-500">Kayıtlı prompt sürümü yok.</p>
        @endforelse
    </section>
</div>
