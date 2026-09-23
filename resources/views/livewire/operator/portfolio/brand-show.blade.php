@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $tabLabels = ['overview' => 'Genel bakış', 'business' => 'İşletme', 'assets' => 'Dijital varlıklar', 'work' => 'İşler', 'reports' => 'Raporlar'];
    $openWork = collect($work)->sum('count');
    $toneClass = fn (string $tone): string => match ($tone) {
        'error' => 'bg-error-500',
        'warning' => 'bg-warning-500',
        default => 'bg-brand-500',
    };
@endphp
<div class="space-y-6">
    @include('livewire.demo.partials.flash')

    {{-- Header: who the brand is --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <a href="{{ route('operator.brands') }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Markalar</a>
            <h1 class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $brandModel->name }}</h1>
            <p class="mt-1 text-sm text-gray-500">
                @if ($customer)<a href="{{ route('operator.customer', ['customerId' => $customer->id]) }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $customer->name }}</a>@endif
                @if ($sectors !== []) · {{ implode(', ', $sectors) }}@endif
                @if ($areas !== []) · {{ implode(' · ', array_slice($areas, 0, 3)) }}@if (count($areas) > 3) +{{ count($areas) - 3 }}@endif @endif
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate @class(['inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium', 'bg-success-500 text-white hover:bg-success-600' => ! $checklist['complete'], 'text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700' => $checklist['complete']])>Otomatik kur</a>
            <a href="{{ route('operator.asset.create', ['brandId' => $brandModel->id]) }}" wire:navigate class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Varlık ekle</a>
            <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Düzenle</a>
        </div>
    </div>

    <div class="-mx-1 overflow-x-auto">
        <div class="flex min-w-max gap-1 border-b border-gray-200 px-1 dark:border-gray-800" role="tablist" aria-label="Marka">
            @foreach ($tabLabels as $key => $label)
                <button type="button" role="tab" wire:click="setTab('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['border-b-2 px-3 py-2 text-sm font-medium transition', 'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key, 'border-transparent text-gray-600 hover:text-gray-900 dark:text-gray-400' => $tab !== $key])>{{ $label }}@if ($key === 'work' && $openWork > 0) <span class="ml-1 rounded-full bg-gray-100 px-1.5 text-xs dark:bg-gray-800">{{ $openWork }}</span>@endif</button>
            @endforeach
        </div>
    </div>

    {{-- ============================================================ OVERVIEW --}}
    @if ($tab === 'overview')
        @if (! $checklist['complete'])
            <section class="{{ $card }} p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Kurulum {{ $checklist['done'] }}/{{ $checklist['total'] }}</h2>
                        <p class="mt-1 text-sm text-gray-500">Eksikler tamamlanınca SEO planı, sorgu eşleştirme ve raporlar tam veriyle çalışır. "Otomatik kur" hesapları ve hizmetleri bulur; sen onaylarsın.</p>
                    </div>
                    <a href="{{ route('operator.brand.setup', ['brand' => $brandModel->id]) }}" wire:navigate class="rounded-lg bg-success-500 px-4 py-2 text-sm font-medium text-white hover:bg-success-600">Otomatik kur</a>
                </div>
                <ul class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($checklist['items'] as $item)
                        <li class="flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                            <span @class(['mt-0.5 text-sm', 'text-success-600' => $item['done'], 'text-error-500' => ! $item['done'] && $item['required'], 'text-gray-400' => ! $item['done'] && ! $item['required']])>{{ $item['done'] ? '✓' : '○' }}</span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-800 dark:text-white/90">{{ $item['label'] }}@if (! $item['required']) <span class="text-xs font-normal text-gray-400">(isteğe bağlı)</span>@endif</span>
                                <span class="block truncate text-xs text-gray-500">{{ $item['detail'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="{{ $card }}">
            <h2 class="border-b border-gray-100 px-5 py-3 text-base font-semibold text-gray-800 dark:border-gray-800 dark:text-white/90">Dikkat gerektirenler</h2>
            @forelse ($attention as $row)
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                    <span class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><span class="h-2 w-2 rounded-full {{ $toneClass($row['tone']) }}"></span>{{ $row['text'] }}</span>
                    @if (isset($row['url']))
                        <a href="{{ $row['url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Aç →</a>
                    @else
                        <button type="button" wire:click="setOps('{{ $row['ops'] }}')" class="text-xs font-medium text-brand-600 hover:underline">Aç →</button>
                    @endif
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-gray-500">Şu an bekleyen iş yok.</p>
            @endforelse
        </section>

        @if ($advisor && ($advisor['channels'] !== [] || $advisor['top'] !== []))
            <section class="{{ $card }}" aria-labelledby="brand-advisor-heading">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h2 id="brand-advisor-heading" class="text-base font-semibold text-gray-800 dark:text-white/90">Danışman</h2>
                    <span class="text-xs text-gray-400">Kanal başına durum ve bu markanın en önemli işleri</span>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($advisor['channels'] as $line)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $line['channel'] }} <span class="font-normal text-gray-400">· {{ $line['asset'] }}</span></p>
                                <p class="text-xs text-gray-500">
                                    @if ($line['running']) İnceleniyor…
                                    @elseif ($line['last_run_at']) {{ $line['summary'] }} · {{ $line['last_run_at']->locale('tr')->diffForHumans() }}
                                    @else Henüz incelenmedi
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-2 text-xs">
                                @if ($line['urgent'] > 0)<x-ta.badge color="error" size="sm">{{ $line['urgent'] }} acil</x-ta.badge>@endif
                                <span class="text-gray-500">{{ $line['open'] }} açık</span>
                                @if ($line['url'])<a href="{{ $line['url'] }}" wire:navigate class="font-medium text-brand-600 hover:underline">Aç →</a>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
                @if ($advisor['top'] !== [])
                    <div class="border-t border-gray-100 px-5 py-3 dark:border-gray-800">
                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Önce bunlar</p>
                        <ol class="mt-2 space-y-2">
                            @foreach ($advisor['top'] as $row)
                                <li class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['title'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $row['channel'] }}@if ($row['impact']) · {{ $row['impact'] }}@endif</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-ta.badge :color="$row['severity_color']" size="sm">{{ $row['severity_label'] }}</x-ta.badge>
                                        @if ($row['url'])<a href="{{ $row['url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Aç →</a>@endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="{{ $card }}">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Dijital varlıklar</h2>
                    <button type="button" wire:click="setTab('assets')" class="text-xs font-medium text-brand-600 hover:underline">Tümü</button>
                </div>
                @forelse ($assets as $asset)
                    <a href="{{ $asset['url'] }}" wire:navigate class="flex items-start gap-3 border-b border-gray-100 px-5 py-3 last:border-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                        <x-demo.digital-asset-mark :type="$asset['type']" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $asset['name'] }}</span>
                            <span class="mt-1 flex flex-wrap gap-1">
                                @forelse ($asset['accounts'] as $account)
                                    <span class="rounded-full bg-success-50 px-2 py-0.5 text-xs text-success-700 dark:bg-success-500/10 dark:text-success-400">● {{ $account['label'] }}</span>
                                @empty
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800">○ Bağlı hesap yok</span>
                                @endforelse
                            </span>
                        </span>
                    </a>
                @empty
                    <p class="px-5 py-4 text-sm text-gray-500">Henüz dijital varlık yok. "Otomatik kur" ile web sitesinden başla.</p>
                @endforelse
            </section>

            <section class="{{ $card }}">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Hizmetler</h2>
                    <button type="button" wire:click="setTab('business')" class="text-xs font-medium text-brand-600 hover:underline">Tümü</button>
                </div>
                @forelse (array_slice($services, 0, 8) as $service)
                    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-2.5 last:border-0 dark:border-gray-800">
                        <span class="text-sm text-gray-800 dark:text-white/90">@if ($service['is_priority'])<span class="text-warning-500">★</span> @endif{{ $service['name'] }}</span>
                        <span class="text-xs text-gray-500">{{ $service['matching_count'] > 0 ? $service['matching_count'].' eşleştirme ifadesi' : 'Eşleştirme ifadesi yok' }}</span>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-gray-500">Markaya hizmet eklenmedi.</p>
                @endforelse
                @if (count($services) > 8)<p class="px-5 py-2 text-xs text-gray-400">+{{ count($services) - 8 }} hizmet daha</p>@endif
            </section>
        </div>
    @endif

    {{-- ============================================================ BUSINESS --}}
    @if ($tab === 'business')
        <section class="{{ $card }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Hizmetler</h2>
                    <p class="text-xs text-gray-500">★ = öncelikli (SEO planı derinlemesine bakar). Eşleştirme ifadeleri, içe aktarılan sorguları bu hizmete otomatik atar.</p>
                </div>
                <a href="{{ route('operator.brand.edit', ['brandId' => $brandModel->id]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Hizmetleri ve bölgeleri düzenle</a>
            </div>
            @forelse ($services as $service)
                <div wire:key="service-{{ $service['id'] }}" class="flex flex-wrap items-start gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                    <button type="button" wire:click="toggleOfferingPriority({{ $service['id'] }})" title="Öncelik" @class(['text-lg leading-none', 'text-warning-500' => $service['is_priority'], 'text-gray-300 hover:text-warning-400' => ! $service['is_priority']])>★</button>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $service['name'] }}</p>
                        @if ($service['matching'] !== [])
                            <p class="mt-1 text-xs text-gray-500">{{ implode(', ', $service['matching']) }}@if ($service['matching_count'] > count($service['matching'])) … (+{{ $service['matching_count'] - count($service['matching']) }})@endif</p>
                        @else
                            <p class="mt-1 text-xs text-warning-700 dark:text-warning-400">Eşleştirme ifadesi yok; sorgular bu hizmete otomatik atanmaz.</p>
                        @endif
                    </div>
                    @if ($service['catalog_item_id'])
                        <a href="{{ route('operator.library.services', ['q' => $service['name']]) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">İfadeleri düzenle</a>
                    @endif
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-gray-500">Markaya hizmet eklenmedi. "Otomatik kur" siteden önerir ya da "Düzenle" ile ekleyebilirsin.</p>
            @endforelse
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="{{ $card }} p-5">
                <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Kapsam</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div><dt class="text-xs text-gray-500">Hizmet verdiği yerler</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $areas !== [] ? implode(' · ', $areas) : '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Sektör</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $sectors !== [] ? implode(', ', $sectors) : '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Sorumlu ekip</dt><dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ $responsible !== [] ? implode(', ', $responsible) : '—' }}</dd></div>
                    <div>
                        <dt class="text-xs text-gray-500">Ajansın verdiği hizmetler</dt>
                        <dd class="mt-0.5 text-gray-800 dark:text-white/90">{{ collect($serviceScope)->where('status', 'active')->pluck('service_label')->implode(', ') ?: '—' }}</dd>
                    </div>
                </dl>
                <a href="{{ route('operator.library.brand-query-portfolios', ['brand' => $brandModel->id]) }}" wire:navigate class="mt-4 inline-block text-xs font-medium text-brand-600 hover:underline">Markanın sorgu portföyü →</a>
            </section>

            <section class="{{ $card }} p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">İş bağlamı</h2>
                    @unless ($editingContext)
                        <button type="button" wire:click="startEditingContext" class="text-xs font-medium text-brand-600 hover:underline">Düzenle</button>
                    @endunless
                </div>
                @if ($editingContext)
                    <form wire:submit="saveBusinessContext" class="mt-3 space-y-3">
                        @foreach (['context_business_summary' => 'İşletme özeti', 'context_business_model' => 'İş modeli', 'context_priority_offerings' => 'Öncelikli teklifler (satır başına bir)', 'context_target_audiences' => 'Hedef kitle', 'context_positioning' => 'Konumlandırma', 'context_differentiators' => 'Farklılaştırıcılar', 'context_business_goals' => 'İş hedefleri', 'context_conversion_goals' => 'Dönüşüm hedefleri', 'context_constraints' => 'Kısıtlar'] as $field => $label)
                            <label class="block text-sm">
                                <span class="text-xs text-gray-500">{{ $label }}</span>
                                <textarea wire:model="{{ $field }}" rows="2" class="mt-1 w-full rounded-lg border border-gray-200 bg-transparent px-3 py-2 text-sm dark:border-gray-700"></textarea>
                            </label>
                        @endforeach
                        <div class="flex gap-2">
                            <x-ta.button type="submit" size="sm">Kaydet</x-ta.button>
                            <x-ta.button type="button" wire:click="cancelEditingContext" size="sm" variant="outline">Vazgeç</x-ta.button>
                        </div>
                    </form>
                @else
                    <dl class="mt-3 space-y-3 text-sm">
                        @forelse ($context as $row)
                            <div><dt class="text-xs text-gray-500">{{ $row['label'] }}</dt><dd class="mt-0.5 whitespace-pre-line text-gray-800 dark:text-white/90">{{ $row['value'] !== '' ? $row['value'] : '—' }}</dd></div>
                        @empty
                            <p class="text-sm text-gray-500">Henüz girilmedi. AI analizleri ve raporlar bu bilgiyi kullanır.</p>
                        @endforelse
                    </dl>
                @endif
            </section>
        </div>
        <livewire:operator.portfolio.brand-competitors :brand-id="(int) $brandModel->id" :key="'brand-competitors-'.$brandModel->id" />
    @endif

    {{-- ============================================================ ASSETS --}}
    @if ($tab === 'assets')
        <section class="{{ $card }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <div>
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">Dijital varlıklar ve bağlı hesaplar</h2>
                    <p class="text-xs text-gray-500">Bağlı hesap = onayladığın Google / Meta hesabı. Son veri, en son başarılı veri çekimidir.</p>
                </div>
            </div>
            @forelse ($assets as $asset)
                <div wire:key="asset-{{ $asset['id'] }}" data-asset-row="{{ $asset['id'] }}" class="flex flex-wrap items-start gap-3 border-b border-gray-100 px-5 py-4 last:border-0 dark:border-gray-800">
                    <x-demo.digital-asset-mark :type="$asset['type']" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $asset['name'] }} <span class="font-normal text-gray-500">· {{ $asset['type_label'] }}</span></p>
                        @if ($asset['accounts'] === [])
                            <p class="mt-1 text-xs text-gray-500">Bağlı hesap yok. Hesap bağlanmadan bu varlık için veri toplanmaz.</p>
                        @else
                            <ul class="mt-2 space-y-1">
                                @foreach ($asset['accounts'] as $account)
                                    <li class="text-xs text-gray-600 dark:text-gray-400"><span class="text-success-600">●</span> <span class="font-medium">{{ $account['label'] }}</span> · {{ $account['resource'] }} · Son veri: {{ $account['last_sync'] ? $account['last_sync']->locale('tr')->diffForHumans() : 'henüz yok' }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        @if ($asset['open_findings'] > 0)<span class="text-xs text-warning-700">{{ $asset['open_findings'] }} açık bulgu</span>@endif
                        <a href="{{ $asset['url'] }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Aç →</a>
                    </div>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-gray-500">Henüz dijital varlık yok.</p>
            @endforelse
        </section>
        @php $missing = collect($checklist['items'])->whereIn('key', array_keys(\App\Services\Operator\BrandWorkspaceReadService::ACCOUNT_LABELS))->where('done', false); @endphp
        @if ($missing->isNotEmpty())
            <p class="text-sm text-gray-500">Bağlı olmayan hesaplar: {{ $missing->pluck('label')->implode(', ') }}. "Otomatik kur" entegrasyonlardaki hesapları adres ve ada göre arar; bulamadığı hesabı "Varlık ekle" ile elle bağlayabilirsin.</p>
        @endif
    @endif

    {{-- ============================================================ WORK --}}
    @if ($tab === 'work')
        <div class="flex flex-wrap gap-2">
            @foreach ($work as $key => $section)
                <button type="button" wire:click="setOps('{{ $key }}')" @class(['rounded-full px-3 py-1.5 text-sm', 'bg-brand-500 text-white' => $ops === $key, 'bg-white text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => $ops !== $key])>{{ $section['label'] }} · {{ $section['count'] }}</button>
            @endforeach
        </div>
        <section class="{{ $card }}">
            @forelse ($work[$ops]['rows'] as $row)
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-3 last:border-0 dark:border-gray-800">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $row['title'] ?? ($row['playbook_name'] ?? '—') }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ collect([$row['severity'] ?? null, $row['asset'] ?? null, $row['owner'] ?? null, $row['due'] ?? null, $row['detected'] ?? null])->filter(fn ($v) => is_string($v) && $v !== '')->implode(' · ') }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-ta.badge color="light" size="sm">{{ $row['status'] ?? '—' }}</x-ta.badge>
                        @if ($ops === 'recommendations' && in_array($row['status'] ?? '', ['pending', 'approved'], true))
                            <button type="button" wire:click="createTaskFromRecommendation('{{ $row['id'] }}')" class="text-xs font-medium text-brand-600 hover:underline">Göreve çevir</button>
                        @elseif ($ops === 'tasks' && isset($row['id']))
                            <a href="{{ route('operator.work.show', ['workId' => $row['id'], 'type' => 'task']) }}" wire:navigate class="text-xs font-medium text-brand-600 hover:underline">Aç</a>
                        @endif
                    </div>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-gray-500">Bu bölümde kayıt yok.</p>
            @endforelse
        </section>
    @endif

    {{-- ============================================================ REPORTS --}}
    @if ($tab === 'reports')
        @include('livewire.demo.partials.period-bar')
        @if ($valueStory)
            @include('livewire.demo.partials._value-story', ['story' => $valueStory])
        @endif
        @include('livewire.demo.partials._report-composer')
    @endif
</div>
