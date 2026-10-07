{{-- Kimlik tutarlılığı (yakup, 2026-10-07): the same name, phone, address and links everywhere; red/amber rows say what differs. --}}
<div>
    @if ($identity)
        @php
            $issues = collect($identity['rows'])->where('state', 'warn')->count();
            $dot = ['ok' => 'bg-emerald-500', 'warn' => 'bg-amber-400', 'unknown' => 'bg-gray-300 dark:bg-gray-600'];
        @endphp
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-brand-identity>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Kimlik tutarlılığı @if ($issues > 0)<span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ $issues }} fark</span>@endif</h2>
                    <p class="text-xs text-gray-500">İşletme Profili ile site aynı adı, telefonu ve adresi verince arama motorları ve yapay zekâ işletmeye daha çok güvenir.</p>
                </div>
                <button type="button" wire:click="recheck" wire:loading.attr="disabled" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Yeniden kontrol et</button>
            </div>
            <ul class="mt-3 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                @foreach ($identity['rows'] as $row)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2" data-identity-row="{{ $row['key'] }}" data-identity-state="{{ $row['state'] }}">
                        <span class="h-2 w-2 shrink-0 translate-y-[-1px] rounded-full {{ $dot[$row['state']] ?? $dot['unknown'] }}"></span>
                        <span class="w-44 shrink-0 font-semibold text-gray-900 dark:text-white">{{ $row['label'] }}</span>
                        <span @class(['min-w-0 flex-1', 'text-amber-700 dark:text-amber-300' => $row['state'] === 'warn', 'text-gray-600 dark:text-gray-400' => $row['state'] !== 'warn'])>{{ $row['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($identity['same_as_missing'] !== [])
                <div class="mt-2 rounded-lg bg-gray-50 p-2.5 dark:bg-white/[0.03]" data-identity-same-as>
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300">SEO eklentisine eklenecek profil bağlantıları</p>
                    <textarea readonly rows="{{ min(5, count($identity['same_as_missing'])) }}" onclick="this.select()" class="mt-1 w-full rounded-md border-gray-200 bg-white font-mono text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">{{ implode("\n", $identity['same_as_missing']) }}</textarea>
                </div>
            @endif
            <p class="mt-2 text-[11px] text-gray-400">Kontrol: {{ \Illuminate\Support\Carbon::parse($identity['checked_at'])->diffForHumans() }}</p>
        </section>
    @endif
</div>
