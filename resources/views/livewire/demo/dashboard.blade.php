<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('operator.dashboard_exec.today') }}</h1>
            <p class="mt-0.5 text-sm text-gray-500">{{ now('Europe/Istanbul')->locale(app()->getLocale())->isoFormat('D MMMM YYYY, dddd') }}</p>
        </div>
        @if (($systemAlerts['critical'] ?? 0) + ($systemAlerts['warning'] ?? 0) > 0)
            <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="flex items-center gap-2 rounded-full bg-gray-100 py-1 pl-2 pr-3 text-xs text-gray-700 hover:bg-gray-200/70 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10" data-system-alerts>
                <span class="h-1.5 w-1.5 rounded-full {{ $systemAlerts['critical'] > 0 ? 'bg-warning-500' : 'bg-gray-400' }}"></span>
                <span><strong class="font-semibold">Hata merkezi:</strong> @if ($systemAlerts['critical'] > 0){{ $systemAlerts['critical'] }} iş seni bekliyor @endif @if ($systemAlerts['warning'] > 0){{ $systemAlerts['warning'] }} yazılım hatası @endif @if ($systemAlerts['top']) · {{ \Illuminate\Support\Str::limit($systemAlerts['top'], 60) }}@endif</span>
                <span class="font-medium">→</span>
            </a>
        @endif
    </div>

    @include('livewire.demo.partials.today-cockpit')

    <livewire:operator.live-feed />

    <livewire:operator.portfolio.epicrisis-questions lazy />

    @if ($chiefPlan === null || $chiefPlan->status === 'failed')
        <p class="flex flex-wrap items-baseline gap-x-2 text-sm text-gray-500 dark:text-gray-400" data-chief-plan data-chief-plan-empty>
            <span class="font-medium text-gray-700 dark:text-gray-300">Bu haftanın planı</span>
            @if ($chiefPlan === null)
                <span>· Henüz plan yok; Şef her pazartesi sabahı hazırlar.</span>
            @else
                <span class="text-warning-700 dark:text-warning-400">· Plan hazırlanamadı: {{ \Illuminate\Support\Str::limit((string) $chiefPlan->error, 120) }}</span>
            @endif
            <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" class="link-neutral">Şimdi hazırla →</button>
        </p>
    @else
        <section class="surface px-4 py-3" data-chief-plan>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-x-3 text-sm">
                    <h2 class="font-semibold text-gray-800 dark:text-white/90">Bu haftanın planı</h2>
                    <span class="text-xs text-gray-500">Şef · her pazartesi, bakım ajanlarının notlarından · {{ $chiefPlan->week_start->format('d.m.Y') }} haftası</span>
                </div>
                <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" class="link-neutral text-xs">Planı yenile</button>
            </div>
            @if ($chiefPlan->headline)
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $chiefPlan->headline }}</p>
            @endif
            <ol class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach ((array) $chiefPlan->plan as $line)
                    <li class="flex items-start gap-3 py-2">
                        <span class="mt-0.5 w-5 shrink-0 text-right text-xs tabular-nums text-gray-400">{{ $loop->iteration }}</span>
                        <x-ta.brand-avatar :name="$line['brand']" size="sm" class="mt-0.5" />
                        <div class="min-w-0 flex-1">
                            @if ($line['brand_id'] > 0)
                                <a href="{{ route('operator.brand', ['brand' => $line['brand_id'], 'tab' => 'dosya']) }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $line['brand'] }}</a>
                            @else
                                <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="font-medium text-gray-800 hover:underline dark:text-white/90">{{ $line['brand'] }}</a>
                            @endif
                            <span class="text-gray-700 dark:text-gray-300">— {{ $line['task'] }}</span>
                            @if ($line['why'] !== '')<span class="block text-xs text-gray-500">{{ $line['why'] }}</span>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <section class="surface px-4 py-3" data-today-results>
        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Sonuçlar</h2>
            <span class="text-xs text-gray-500">son 90 gün</span>
            <span class="text-success-700 dark:text-success-400">İşe yaradı <strong class="tabular-nums">{{ $results['counts']['worked'] ?? 0 }}</strong></span>
            <span class="text-gray-600 dark:text-gray-300">Değişmedi <strong class="tabular-nums">{{ $results['counts']['neutral'] ?? 0 }}</strong></span>
            <span class="text-warning-700 dark:text-warning-400">Kötüleşti <strong class="tabular-nums">{{ $results['counts']['not_worked'] ?? 0 }}</strong></span>
            <span class="text-gray-600 dark:text-gray-300">Ölçülemedi <strong class="tabular-nums">{{ $results['counts']['unclear'] ?? 0 }}</strong></span>
        </div>
        @if ($results['items'] === [])
            <p class="mt-1 text-xs text-gray-500">Henüz ölçülen öneri yok; uygulanan öneriler 30. ve 90. günde, önceki aynı uzunluktaki dönemle karşılaştırılır.</p>
        @else
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach ($results['items'] as $item)
                    <li class="flex flex-wrap items-center gap-x-2 gap-y-0.5 py-1.5">
                        <x-ta.brand-avatar :name="$item['brand']" size="xs" />
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $item['brand'] }}</span>
                        <span class="text-xs text-gray-500">{{ $item['channel'] }} · {{ $item['point'] }}</span>
                        <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $item['title'] }}</span>
                        <span class="text-xs text-gray-500" title="{{ $item['reason'] }}">{{ $item['line'] ?? $item['reason'] }}</span>
                        <span class="ml-auto">@include('livewire.demo.partials.outcome-badge', ['verdict' => $item['verdict']])</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Loss first, approvals second, the rest closed: the brand list is reference, folded by default. --}}
    @if ($rows === [])
        <p class="text-sm text-gray-500">Hizmet verilen marka yok.</p>
    @else
        <details wire:ignore.self class="surface group" data-today-brands>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-4 py-3 text-sm">
            <span class="font-semibold text-gray-800 dark:text-white/90">Markalar <span class="font-normal text-gray-500">· {{ count($rows) }}</span></span>
            <span class="text-xs text-gray-500 group-open:hidden">Göster</span>
            <span class="hidden text-xs text-gray-500 group-open:inline">Gizle</span>
        </summary>
        <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800" data-portfolio-today>
            @foreach ($rows as $row)
                <li class="flex gap-3 px-4 py-3" wire:key="today-{{ $row['id'] }}" data-today-brand="{{ $row['id'] }}">
                    <x-ta.brand-avatar :name="$row['name']" class="mt-0.5" />
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="text-sm font-semibold text-gray-800 hover:underline dark:text-white/90">{{ $row['name'] }}</a>
                            @if ($row['customer'])<span class="text-xs text-gray-500">{{ $row['customer'] }}</span>@endif
                            <span class="text-xs text-gray-500">{{ $row['sector'] ?? 'Sektör —' }}</span>
                            <span class="text-xs text-gray-500">{{ $row['services'] }} hizmet</span>
                            <span class="text-xs text-gray-500">{{ $row['areas'] }} bölge</span>
                            <span class="ml-auto text-xs text-gray-400">Öneri {{ $row['suggestions'] ?? '—' }}</span>
                        </div>
                        @if ($row['assets'] !== [])
                            <div class="flex flex-wrap gap-1.5 text-xs text-gray-500">
                                @foreach ($row['assets'] as $asset)
                                    <span class="rounded-md bg-gray-100 px-1.5 py-0.5 dark:bg-white/5">{{ $asset['type'] }}{{ ($asset['sector'] ?? null) ? ' ('.$asset['sector'].')' : '' }} · {{ $asset['last'] ?? '—' }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        </details>
    @endif
</div>
