{{-- Kimlik tutarlılığı (yakup, 2026-10-07): the same name, phone, address and links everywhere; red/amber rows say what differs. --}}
<div>
    @if ($identity)
        @php
            $issues = collect($identity['rows'])->where('state', 'warn')->count();
            $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-400', 'unknown' => 'bg-gray-300 dark:bg-gray-600'];
        @endphp
        <section data-brand-identity>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="section-title" title="İşletme Profili ile site aynı adı, telefonu ve adresi verince arama motorları ve yapay zekâ işletmeye daha çok güvenir.">Kimlik tutarlılığı @if ($issues > 0)<span class="ml-1 text-sm font-normal text-warning-800 dark:text-warning-400">{{ $issues }} fark</span>@endif</h2>
                <button type="button" wire:click="recheck" wire:loading.attr="disabled" class="text-xs font-medium text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-300 dark:hover:text-white">Yeniden kontrol et</button>
            </div>
            <ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($identity['rows'] as $row)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5" data-identity-row="{{ $row['key'] }}" data-identity-state="{{ $row['state'] }}">
                        <span class="h-1.5 w-1.5 shrink-0 translate-y-[-2px] rounded-full {{ $dot[$row['state']] ?? $dot['unknown'] }}"></span>
                        <span class="w-44 shrink-0 font-medium text-gray-900 dark:text-white">{{ $row['label'] }}</span>
                        <span @class(['min-w-0 flex-1 text-xs', 'text-warning-800 dark:text-warning-400' => $row['state'] === 'warn', 'text-gray-500 dark:text-gray-400' => $row['state'] !== 'warn'])>{{ $row['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($identity['same_as_missing'] !== [])
                <div class="mt-3" data-identity-same-as>
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300">SEO eklentisine eklenecek profil bağlantıları</p>
                    <textarea readonly rows="{{ min(5, count($identity['same_as_missing'])) }}" onclick="this.select()" class="mt-1 w-full rounded-md border-gray-200 bg-white font-mono text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">{{ implode("\n", $identity['same_as_missing']) }}</textarea>
                </div>
            @endif
            <p class="mt-2 text-xs text-gray-500">Kontrol: {{ \Illuminate\Support\Carbon::parse($identity['checked_at'])->diffForHumans() }}</p>
        </section>
    @endif
</div>
