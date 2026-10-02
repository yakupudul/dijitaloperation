<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">{{ __('operator.dashboard_exec.today') }}</h1>

    @if (($systemAlerts['critical'] ?? 0) + ($systemAlerts['warning'] ?? 0) > 0)
        <a href="{{ route('operator.settings.system-health') }}" wire:navigate @class(['flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3 text-sm ring-1 ring-inset', 'bg-rose-50 text-rose-800 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' => $systemAlerts['critical'] > 0, 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' => $systemAlerts['critical'] === 0])>
            <span><strong>Hata merkezi:</strong> @if ($systemAlerts['critical'] > 0){{ $systemAlerts['critical'] }} iş seni bekliyor @endif @if ($systemAlerts['warning'] > 0){{ $systemAlerts['warning'] }} yazılım hatası @endif @if ($systemAlerts['top']) · {{ $systemAlerts['top'] }}@endif</span>
            <span class="font-medium underline">Hata merkezine git</span>
        </a>
    @endif

    <section class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-chief-plan>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="flex flex-wrap items-baseline gap-x-3 text-sm">
                <h2 class="font-semibold text-gray-800 dark:text-white/90">Bu haftanın planı</h2>
                <span class="text-xs text-gray-500">Şef · her pazartesi, bakım ajanlarının notlarından
                    @if ($chiefPlan) · {{ $chiefPlan->week_start->format('d.m.Y') }} haftası @endif</span>
            </div>
            <button type="button" wire:click="refreshPlan" wire:loading.attr="disabled" class="text-xs font-medium text-brand-600 hover:underline">Planı yenile</button>
        </div>
        @if ($chiefPlan === null)
            <p class="mt-1 text-xs text-gray-500">Henüz plan yok; ilk plan pazartesi sabahı hazırlanır.</p>
        @elseif ($chiefPlan->status === 'failed')
            <p class="mt-1 text-xs text-rose-600">Plan hazırlanamadı: {{ $chiefPlan->error }}</p>
        @else
            @if ($chiefPlan->headline)
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $chiefPlan->headline }}</p>
            @endif
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm">
                @foreach ((array) $chiefPlan->plan as $line)
                    <li>
                        @if ($line['brand_id'] > 0)
                            <a href="{{ route('operator.brand', ['brand' => $line['brand_id'], 'tab' => 'dosya']) }}" wire:navigate class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $line['brand'] }}</a>
                        @else
                            <a href="{{ route('operator.settings.system-health') }}" wire:navigate class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $line['brand'] }}</a>
                        @endif
                        <span class="text-gray-700 dark:text-gray-300">— {{ $line['task'] }}</span>
                        @if ($line['why'] !== '')<span class="block text-xs text-gray-500">{{ $line['why'] }}</span>@endif
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-today-results>
        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm">
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Sonuçlar</h2>
            <span class="text-xs text-gray-500">son 90 gün</span>
            <span class="text-success-700 dark:text-success-400">İşe yaradı <strong>{{ $results['counts']['worked'] ?? 0 }}</strong></span>
            <span class="text-rose-700 dark:text-rose-300">İşe yaramadı <strong>{{ $results['counts']['not_worked'] ?? 0 }}</strong></span>
            <span class="text-gray-600 dark:text-gray-300">Belirsiz <strong>{{ $results['counts']['unclear'] ?? 0 }}</strong></span>
        </div>
        @if ($results['items'] === [])
            <p class="mt-1 text-xs text-gray-500">Henüz ölçülen öneri yok; uygulanan öneriler 28. ve 56. günde ölçülür.</p>
        @else
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach ($results['items'] as $item)
                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 py-1.5">
                        <span class="font-medium text-gray-800 dark:text-white/90">{{ $item['brand'] }}</span>
                        <span class="text-xs text-gray-500">{{ $item['channel'] }} · {{ $item['point'] }}</span>
                        <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $item['title'] }}</span>
                        <span class="text-xs text-gray-500">{{ $item['reason'] }}</span>
                        <span class="ml-auto">@include('livewire.demo.partials.outcome-badge', ['verdict' => $item['verdict']])</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($rows === [])
        <p class="text-sm text-gray-500">Hizmet verilen marka yok.</p>
    @else
        <ul class="divide-y divide-gray-100 rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:divide-gray-800 dark:bg-gray-900 dark:ring-gray-800" data-portfolio-today>
            @foreach ($rows as $row)
                <li class="flex flex-col gap-1 px-4 py-3" wire:key="today-{{ $row['id'] }}" data-today-brand="{{ $row['id'] }}">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <a href="{{ route('operator.brand', ['brand' => $row['id']]) }}" wire:navigate class="text-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $row['name'] }}</a>
                        @if ($row['customer'])<span class="text-xs text-gray-500">{{ $row['customer'] }}</span>@endif
                        <span class="text-xs text-gray-500">{{ $row['sector'] ?? 'Sektör —' }}</span>
                        <span class="text-xs text-gray-500">{{ $row['services'] }} hizmet</span>
                        <span class="text-xs text-gray-500">{{ $row['areas'] }} bölge</span>
                        <span class="ml-auto text-xs text-gray-400">Öneri {{ $row['suggestions'] ?? '—' }}</span>
                    </div>
                    @if ($row['assets'] !== [])
                        <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                            @foreach ($row['assets'] as $asset)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">{{ $asset['type'] }}{{ ($asset['sector'] ?? null) ? ' ('.$asset['sector'].')' : '' }} · {{ $asset['last'] ?? '—' }}</span>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
