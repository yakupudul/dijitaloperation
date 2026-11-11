@php
    use App\Models\BrandFinding;

    $live = $page['live'];
    $waiting = $live->where('status', 'waiting')->count();
    $inHand = $live->where('status', 'requested')->count();
    $short = \Illuminate\Support\Str::of($brand->name)->replaceMatches('/^(Özel|Dr\.?|Dt\.?|Op\.?|Uzm\.?)\s+/u', '')->explode(' ')->first();
    $day = fn ($at): string => $at ? $at->timezone('Europe/Istanbul')->locale('tr')->isoFormat('D MMMM') : '';
@endphp
{{-- Bulgular (Marka Düzeni 2.1 / 2.2): the approved brand page prototype. Nothing is drawn until the brand has had its first look. --}}
<div data-brand-findings>
    @if ($page['any'])
        <section class="space-y-8">
            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                <div class="min-w-0">
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-900 sm:text-[28px] dark:text-white" data-brand-findings-head>
                        @if ($page['open'] > 0)
                            {{ $short }}'de <span class="tabular-nums">{{ $page['open'] }}</span> bulgu çözülmeyi bekliyor
                        @elseif ($waiting > 0)
                            {{ $short }}'de <span class="tabular-nums">{{ $waiting }}</span> iş onayını bekliyor
                        @elseif ($inHand > 0)
                            {{ $short }}'de <span class="tabular-nums">{{ $inHand }}</span> bulgu Claude'da
                        @else
                            {{ $short }}'de açık bulgu yok
                        @endif
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        @if ($page['open'] > 0) Satıra tıkla: ne gördüm, ne yapacağım, senden ne gerekiyor. @else Çözülen her bulgu aşağıda kalır, bir ay sonraki etkisi yanına yazılır. @endif
                        @if ($inHand > 0 && $page['open'] > 0) {{ $inHand }} bulgu Claude'da. @endif
                    </p>
                </div>
                @if ($page['open'] > 1)
                    <button type="button" wire:click="solveAll" wire:loading.attr="disabled" wire:confirm="{{ $page['open'] }} bulgunun hepsi Claude'a iletilsin mi? Onay isteyen işler yine sana sorulur." class="btn-dark" data-brand-findings-all>Hepsini çöz</button>
                @endif
            </div>
            @if ($message !== '')
                <p role="status" class="-mt-4 text-[13px] text-gray-600 dark:text-gray-300" data-brand-findings-message>{{ $message }}</p>
            @endif

            @if ($live->isNotEmpty())
                <div class="card divide-y divide-gray-200 dark:divide-gray-800" data-brand-findings-list>
                    @foreach ($live as $row)
                        <div wire:key="finding-{{ $row->id }}" x-data="{ open: false }" data-brand-finding="{{ $row->id }}" data-status="{{ $row->status }}">
                            <div class="flex flex-col gap-x-4 gap-y-2 px-4 py-3.5 sm:flex-row sm:items-center">
                                <button type="button" x-on:click="open = !open" :aria-expanded="open" class="min-w-0 flex-1 text-left">
                                    <span class="block text-[12px] text-gray-500">{{ $row->area }}</span>
                                    <span class="block text-[15px] font-semibold text-gray-900 dark:text-white">{{ $row->title }}</span>
                                    @if ($row->evidence)<span class="mt-0.5 block text-[13px] text-gray-500">{{ $row->evidence }}</span>@endif
                                </button>
                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    <span class="chip chip-flat" title="Bu iş canlıya çıkmadan önce senden ne ister">{{ $row->approvalLabel() }}</span>
                                    @if ($row->status === 'open')
                                        <button type="button" wire:click="solve({{ $row->id }})" wire:loading.attr="disabled" class="btn-dark h-8 px-3.5 text-[13px]" data-brand-finding-solve>Çöz</button>
                                    @elseif ($row->status === 'requested')
                                        <span class="chip chip-accent" data-brand-finding-state>Claude'da</span>
                                        <button type="button" wire:click="mark({{ $row->id }}, 'open')" class="btn-quiet h-8 px-2 text-[13px] text-gray-500">Geri al</button>
                                    @else
                                        <span class="chip chip-down" data-brand-finding-state>Onayını bekliyor</span>
                                        <button type="button" wire:click="mark({{ $row->id }}, 'done')" class="btn h-8 px-3 text-[13px]" data-brand-finding-approve>Tamam</button>
                                    @endif
                                </div>
                            </div>
                            @if ($row->status === 'waiting' && $row->result)
                                <p class="border-t border-gray-100 bg-gray-50 px-4 py-2.5 text-[13px] text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-brand-finding-result>
                                    {{ $row->result }}
                                    @if ($row->result_url)<a href="{{ $row->result_url }}" target="_blank" rel="noopener" class="ml-1 font-semibold text-gray-900 underline decoration-gray-300 underline-offset-2 dark:text-white">Aç</a>@endif
                                </p>
                            @endif
                            <div x-show="open" x-cloak class="grid gap-x-8 gap-y-4 border-t border-gray-100 px-4 py-4 text-[13px] leading-relaxed sm:grid-cols-3 dark:border-gray-800">
                                <div><p class="font-semibold text-gray-900 dark:text-white">Ne gördüm</p><p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $row->saw ?: '—' }}</p></div>
                                <div><p class="font-semibold text-gray-900 dark:text-white">Ne yapacağım</p><p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $row->plan ?: '—' }}</p></div>
                                <div>
                                    <p class="font-semibold text-gray-900 dark:text-white">Senden</p><p class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $row->needs ?: 'Bir şey gerekmiyor.' }}</p>
                                    @if ($row->status === 'open')
                                        <button type="button" wire:click="mark({{ $row->id }}, 'closed')" wire:confirm="Bu bulgu kapatılsın mı? İstediğinde yeniden açılır." class="mt-3 text-[12px] text-gray-400 underline decoration-gray-300 underline-offset-2 hover:text-gray-700" data-brand-finding-close>Bu bulguyu kapat</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($page['tasks']->isNotEmpty())
                <div data-brand-findings-tasks>
                    <div class="section-head"><h3 class="section-title">Senin işlerin <span class="ml-1 font-normal tabular-nums text-gray-400">{{ $page['tasks']->count() }}</span></h3></div>
                    <ul class="card divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($page['tasks'] as $row)
                            <li wire:key="task-{{ $row->id }}" class="flex items-start gap-3 px-4 py-3">
                                <button type="button" wire:click="mark({{ $row->id }}, 'done')" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border border-gray-300 hover:border-gray-900 dark:border-gray-600 dark:hover:border-white" aria-label="Yapıldı: {{ $row->title }}" data-brand-task-done></button>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $row->title }}</span>
                                    @if ($row->evidence || $row->saw)<span class="block text-[13px] text-gray-500">{{ $row->evidence ?: $row->saw }}</span>@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($page['untouched']->isNotEmpty() || $page['unseen']->isNotEmpty())
                <div class="grid gap-6 lg:grid-cols-2" data-brand-findings-looked>
                    @foreach (['untouched' => 'Baktım, dokunmuyorum', 'unseen' => 'Göremedim'] as $key => $label)
                        @continue($page[$key]->isEmpty())
                        <div>
                            <div class="section-head"><h3 class="section-title">{{ $label }} <span class="ml-1 font-normal tabular-nums text-gray-400">{{ $page[$key]->count() }}</span></h3></div>
                            <ul class="card divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($page[$key] as $row)
                                    <li class="px-4 py-3">
                                        <span class="block text-[12px] text-gray-500">{{ $row->area }}</span>
                                        <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $row->title }}</span>
                                        @if ($row->evidence || $row->saw)<span class="block text-[13px] text-gray-500">{{ $row->evidence ?: $row->saw }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($page['done']->isNotEmpty())
                <div data-brand-findings-done>
                    <div class="section-head">
                        <h3 class="section-title">Yapılanlar ve sonuçları <span class="ml-1 font-normal tabular-nums text-gray-400">{{ $page['done']->count() }}</span></h3>
                    </div>
                    <div class="card divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($page['done'] as $row)
                            <div wire:key="done-{{ $row->id }}" class="grid gap-x-6 gap-y-1 px-4 py-3 text-[13px] sm:grid-cols-[minmax(0,2fr)_6rem_minmax(0,2fr)]">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $row->title }}</p>
                                    @if ($row->result)<p class="text-gray-500">{{ $row->result }} @if ($row->result_url)<a href="{{ $row->result_url }}" target="_blank" rel="noopener" class="underline decoration-gray-300 underline-offset-2">Aç</a>@endif</p>@endif
                                </div>
                                <p class="tabular-nums text-gray-500">{{ $day($row->resolved_at) }}</p>
                                <p @class(['whitespace-pre-line', 'text-gray-900 dark:text-white' => filled($row->effect), 'text-gray-400' => ! filled($row->effect)])>{{ $row->effect ?: 'Etkisi bir ay sonra ölçülür.' }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Marka Düzeni 2.5: the brand's switch between the old row-producing order and the new one (findings + monthly session). --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-5 dark:border-gray-800" data-new-order="{{ $brand->new_order_at ? '1' : '0' }}">
                <p class="min-w-0 flex-1 basis-72 text-[13px] text-gray-500 dark:text-gray-400">
                    @if ($brand->new_order_at)
                        <span class="font-medium text-gray-900 dark:text-white">Yeni düzen açık</span> ({{ $day($brand->new_order_at) }}). Bu markada gece denetimlerinin satırları, haftalık yazı fikirleri ve "senin elinle" listesi sana düşmüyor; iş bu sayfadaki bulgulardan yürüyor.@if ($held > 0) {{ $held }} eski satır kenarda duruyor, eski düzene dönersen geri gelir.@endif
                    @else
                        <span class="font-medium text-gray-900 dark:text-white">Bu marka eski düzende.</span> Yeni düzene alırsan gece denetimlerinin satırları, haftalık yazı fikirleri ve "senin elinle" listesi bu marka için sana düşmez; açık satırlar silinmez, kenara alınır. Veri toplama, sorgular ve kümeler çalışmaya devam eder.
                    @endif
                </p>
                @if ($brand->new_order_at)
                    <button type="button" wire:click="newOrder(false)" wire:loading.attr="disabled" class="btn-quiet" data-new-order-off>Eski düzene dön</button>
                @else
                    <button type="button" wire:click="newOrder(true)" wire:loading.attr="disabled" class="btn" data-new-order-on>Yeni düzene al</button>
                @endif
            </div>
        </section>
    @endif
</div>
