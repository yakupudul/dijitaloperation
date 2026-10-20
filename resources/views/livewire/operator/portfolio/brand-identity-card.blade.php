{{-- Kimlik tutarlılığı (yakup, 2026-10-07): the same name, phone, address and links everywhere; red/amber rows say what differs. --}}
<div>
    @if ($identity)
        @php
            $issues = collect($identity['rows'])->where('state', 'warn')->count();
            $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-400', 'unknown' => 'bg-gray-300 dark:bg-gray-600'];
        @endphp
        <section data-brand-identity>
            <div class="section-head">
                <h2 class="section-title" title="İşletme Profili ile site aynı adı, telefonu ve adresi verince arama motorları ve yapay zekâ işletmeye daha çok güvenir.">Kimlik tutarlılığı @if ($issues > 0)<span class="section-count text-warning-700! dark:text-warning-400!">{{ $issues }} fark</span>@endif</h2>
                <button type="button" wire:click="recheck" wire:loading.attr="disabled" class="btn ml-auto">Yeniden kontrol et</button>
            </div>
            <ul class="card">
                @foreach ($identity['rows'] as $row)
                    <li class="card-row items-start" data-identity-row="{{ $row['key'] }}" data-identity-state="{{ $row['state'] }}">
                        <span class="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full {{ $dot[$row['state']] ?? $dot['unknown'] }}" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1 sm:flex sm:items-baseline sm:gap-4">
                            <p class="text-sm font-medium text-gray-900 sm:w-44 sm:shrink-0 dark:text-white">{{ $row['label'] }}</p>
                            <p @class(['mt-0.5 text-[13px] sm:mt-0', 'text-warning-800 dark:text-warning-400' => $row['state'] === 'warn', 'text-gray-500 dark:text-gray-400' => $row['state'] !== 'warn'])>{{ $row['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
                @if ($identity['same_as_missing'] !== [])
                    <li class="border-t border-gray-200 px-4 py-3.5 dark:border-gray-800" data-identity-same-as>
                        <p class="text-[13px] font-medium text-gray-700 dark:text-gray-300">SEO eklentisine eklenecek profil bağlantıları</p>
                        <textarea readonly rows="{{ min(5, count($identity['same_as_missing'])) }}" onclick="this.select()" class="mt-1.5 w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-xs dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-200">{{ implode("\n", $identity['same_as_missing']) }}</textarea>
                    </li>
                @endif
                <li class="border-t border-gray-200 bg-gray-50/60 px-4 py-2.5 text-[13px] text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">Kontrol: {{ \Illuminate\Support\Carbon::parse($identity['checked_at'])->diffForHumans() }}</li>
            </ul>
        </section>
    @endif
</div>
