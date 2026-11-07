{{-- "Baştan sona kontrol" (yakup, 2026-11-07): the whole live chain, read-only, one row per link with its fix. --}}
<section class="card p-5" data-wa-diagnosis>
    <div class="flex flex-wrap items-center gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="section-title">Canlı bağlantı kontrolü</h2>
            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                Meta uygulamasından gelen kutusuna kadar her adım Meta'ya sorularak denetlenir; hiçbir şey değiştirilmez.
                @if($diagnosis) Son kontrol: {{ \Carbon\CarbonImmutable::parse($diagnosis['at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}. @endif
            </p>
        </div>
        <button type="button" wire:click="runDiagnosis" wire:loading.attr="disabled" class="btn" data-wa-diagnosis-run>
            <span wire:loading.remove wire:target="runDiagnosis">Baştan sona kontrol et</span><span wire:loading wire:target="runDiagnosis">Kontrol ediliyor…</span>
        </button>
    </div>
    @if($diagnosis)
        <ol class="mt-4 space-y-3">
            @foreach($diagnosis['rows'] as $row)
                <li class="flex gap-3">
                    <span @class(['mt-1 h-2 w-2 shrink-0 rounded-full', 'bg-success-500' => $row['ok'] === true, 'bg-error-500' => $row['ok'] === false, 'bg-gray-400' => $row['ok'] === null]) aria-hidden="true"></span>
                    <div class="min-w-0 text-sm">
                        <p class="font-medium text-gray-900 dark:text-white">{{ $row['step'] }} <span class="font-normal text-gray-500">· {{ $row['ok'] === true ? 'Tamam' : ($row['ok'] === false ? 'Sorun' : 'Denenemedi') }}</span></p>
                        <p class="text-[13px] text-gray-600 dark:text-gray-300 break-words">{{ $row['detail'] }}</p>
                        @if(! empty($row['fix']))<p class="mt-0.5 text-[13px] text-gray-900 dark:text-white">Yapılacak: {{ $row['fix'] }}</p>@endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
