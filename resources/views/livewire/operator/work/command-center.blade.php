@php
    use App\Livewire\Operator\Work\CommandCenterPage;
    use App\Services\CommandCenter\Activity\ActivitySuppression;
    use App\Services\CommandCenter\TopicCatalog;

    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $fmt = fn (float $n): string => number_format($n, 0, ',', '.');
    $tone = ['critical' => 'bg-error-50 text-error-700', 'high' => 'bg-warning-50 text-warning-700', 'medium' => 'bg-blue-50 text-blue-700', 'low' => 'bg-gray-100 text-gray-600'];
    $dot = ['critical' => 'bg-error-500', 'high' => 'bg-warning-500', 'medium' => 'bg-blue-500', 'low' => 'bg-gray-400'];
    $sevLabel = ['critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük'];
    $groupLabel = TopicCatalog::GROUPS;
    $btn = 'rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700';
    $ageText = function (?\DateTimeInterface $since): ?string {
        if ($since === null) {
            return null;
        }
        $days = (int) floor(\Carbon\CarbonImmutable::instance($since)->diffInDays(now(), true));

        return $days < 1 ? 'bugün' : $days.' gündür';
    };
    $assetUrl = fn (?string $type, ?int $id): ?string => $id === null ? null : match ($type) {
        'website' => route('operator.website', ['assetId' => $id]),
        'google_ads' => route('operator.google-ads.overview', ['assetId' => $id]),
        'meta_ads' => route('operator.meta.overview', ['assetId' => $id]),
        'google_business_profile', 'gbp' => route('operator.gbp', ['assetId' => $id]),
        'ga4' => route('operator.analytics', ['assetId' => $id]),
        'search_console', 'gsc' => route('operator.search-console', ['assetId' => $id]),
        default => null,
    };
    $detailedUrl = fn (array $row): ?string => match ($row['source']) {
        'advisor' => route('operator.ads_advisor.detailed', ['adv_asset' => $row['asset_id']]),
        'seo' => route('operator.seo_tasks.detailed', ['seo_site' => $row['asset_id']]),
        default => null,
    };
@endphp
<div class="space-y-5" wire:poll.visible.120s
    x-data
    x-on:keydown.window="if (['INPUT', 'SELECT', 'TEXTAREA'].includes($event.target.tagName)) return; if ($event.key === 'j') { $wire.moveTopic(1) } else if ($event.key === 'k') { $wire.moveTopic(-1) } else if ($event.key === 'Escape') { $wire.closeItem() }">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $heading['title'] }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500">{{ $heading['subtitle'] }}</p>
            <p class="mt-2 text-xs text-gray-500">
                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $fmt($summary['total']) }}</span> açık iş · {{ $summary['brands'] }} markada
                @if ($summary['critical'] > 0)· <span class="font-medium text-error-600">{{ $summary['critical'] }} kritik / yüksek</span>@endif
                @if ($summary['money'] > 0)· risk altındaki tutar ~{{ $fmt($summary['money']) }} TL @endif
                @if ($summary['aged'] > 0)· {{ $summary['aged'] }} uzun süredir devam eden @endif
            </p>
        </div>
        @if ($heading['detailed_route'])
            <a href="{{ route($heading['detailed_route']) }}" wire:navigate class="{{ $btn }}">{{ $heading['detailed_label'] }} →</a>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800" role="tablist" aria-label="Alan">
            <button type="button" role="tab" wire:click="setArea('')" @class(['rounded-md px-3 py-1.5 text-xs font-medium', 'bg-white text-gray-900 shadow-theme-xs dark:bg-gray-900 dark:text-white' => $area === '', 'text-gray-600 dark:text-gray-400' => $area !== ''])>Tümü <span class="text-gray-400">{{ $total }}</span></button>
            @foreach (TopicCatalog::AREAS as $key => $label)
                <button type="button" role="tab" wire:click="setArea('{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-xs font-medium', 'bg-white text-gray-900 shadow-theme-xs dark:bg-gray-900 dark:text-white' => $area === $key, 'text-gray-600 dark:text-gray-400' => $area !== $key])>{{ $label }} <span class="text-gray-400">{{ $areaCounts[$key] ?? 0 }}</span></button>
            @endforeach
        </div>
        <select wire:model.live="brand" aria-label="Marka" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            <option value="">Tüm markalar</option>
            @foreach ($brands as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        @if ($asset !== null)
            <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">Varlık: {{ $assetName ?? '#'.$asset }}
                <button type="button" wire:click="clearFilter('asset')" aria-label="Varlık filtresini kaldır" class="hover:text-brand-900">×</button></span>
        @endif
        @if ($source !== '')
            <span class="inline-flex items-center gap-1 rounded-full bg-brand-50 px-3 py-1 text-xs text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">Kaynak: {{ \App\Services\CommandCenter\CommandCenter::SOURCES[$source] ?? $source }}
                <button type="button" wire:click="clearFilter('source')" aria-label="Kaynak filtresini kaldır" class="hover:text-brand-900">×</button></span>
        @endif
        <span class="ml-auto hidden text-xs text-gray-400 lg:inline">Kısayol: j / k konu değiştir · Esc paneli kapat</span>
    </div>

    @if ($suppressedCount > 0)
        <p class="rounded-lg bg-gray-50 px-4 py-2 text-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-400">
            <span class="font-medium">Duraklatılmış hesaplar:</span> {{ $suppressedCount }} bütçe / harcama uyarısı gizlendi — müşteri reklamı durdurmuş ya da hesap uzun süredir harcamıyor{{ $suppressedAssets !== [] ? ' ('.implode(', ', array_slice($suppressedAssets, 0, 5)).(count($suppressedAssets) > 5 ? '…' : '').')' : '' }}.
        </p>
    @endif

    <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
        <nav class="space-y-4" aria-label="Konular">
            @php($any = false)
            @foreach ($groupLabel as $group => $label)
                @if ($nav[$group] !== [])
                    @php($any = true)
                    <div>
                        <p class="mb-1 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</p>
                        <ul class="space-y-0.5">
                            @foreach ($nav[$group] as $t)
                                <li>
                                    <button type="button" wire:click="selectTopic(@js($t['key']))" @if ($selectedKey === $t['key']) aria-current="true" @endif
                                        @class(['flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition',
                                            'bg-brand-500 text-white' => $selectedKey === $t['key'],
                                            'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]' => $selectedKey !== $t['key']])>
                                        <span class="h-2 w-2 shrink-0 rounded-full {{ $dot[$t['severity']] ?? 'bg-gray-400' }}"></span>
                                        <span class="min-w-0 flex-1 truncate">{{ $t['label'] }}</span>
                                        <span @class(['shrink-0 text-xs', 'text-white' => $selectedKey === $t['key'], 'text-gray-400' => $selectedKey !== $t['key']])>{{ $t['assets'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
            @if ($nav['aged'] !== [])
                @php($any = true)
                <div>
                    <button type="button" wire:click="$toggle('showAged')" class="flex w-full items-center justify-between px-3 text-xs font-semibold uppercase tracking-wide text-gray-400 hover:text-gray-600" aria-expanded="{{ $showAged ? 'true' : 'false' }}">
                        <span>{{ TopicCatalog::AGED_GROUP }} ({{ collect($nav['aged'])->sum('count') }})</span><span>{{ $showAged ? '▾' : '▸' }}</span>
                    </button>
                    @if ($showAged)
                        <p class="mt-1 px-3 text-xs text-gray-400">{{ $agingDays }}+ gündür değişmeden süren işler: sayaçta ve panoda görünmez. Durum değişirse yeniden üste çıkar.</p>
                        <ul class="mt-1 space-y-0.5">
                            @foreach ($nav['aged'] as $t)
                                <li>
                                    <button type="button" wire:click="selectTopic(@js($t['key']))"
                                        @class(['flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition',
                                            'bg-gray-700 text-white' => $selectedKey === $t['key'],
                                            'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]' => $selectedKey !== $t['key']])>
                                        <span class="min-w-0 flex-1 truncate">{{ $t['label'] }}</span>
                                        <span class="shrink-0 text-xs">{{ $t['assets'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
            @if (! $any)
                <p class="px-3 text-sm text-gray-500">Bu filtrede açık iş yok.</p>
            @endif
        </nav>

        <section class="min-w-0 space-y-4">
            @if ($selected === null)
                <div class="{{ $card }} p-6 text-sm text-gray-500">Bu filtrede açık iş yok. 🎉</div>
            @else
                <div class="{{ $card }} p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selected['label'] }}</h2>
                        <span class="rounded-full px-2 py-0.5 text-xs {{ $tone[$selected['severity']] ?? '' }}">{{ $sevLabel[$selected['severity']] ?? $selected['severity'] }}</span>
                        @if ($selected['group'] === 'aged')<span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ TopicCatalog::AGED_GROUP }}</span>@endif
                        <span class="text-xs text-gray-500">{{ $selected['assets'] }} varlık · {{ $selected['count'] }} kayıt @if ($selected['money'] > 0)· ~{{ $fmt($selected['money']) }} TL @endif</span>
                    </div>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-400">{{ $selected['explanation'] }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="selectAll" class="text-xs text-brand-600 hover:underline">Tümünü seç ({{ $selectedItems->count() }})</button>
                    @if ($bulkIds !== [])
                        <div class="flex flex-wrap items-center gap-2 rounded-lg bg-brand-50 px-3 py-1.5 text-sm dark:bg-brand-500/10">
                            <span class="text-xs font-medium text-brand-700 dark:text-brand-300">{{ count($bulkIds) }} seçili</span>
                            <button type="button" wire:click="act('done')" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Yaptım</button>
                            <div class="relative" x-data="{ open: false }">
                                <button type="button" x-on:click="open = ! open" class="{{ $btn }}">Ertele ▾</button>
                                <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute left-0 z-10 mt-1 w-28 rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                                    @foreach (CommandCenterPage::SNOOZE_DAYS as $d)
                                        <button type="button" wire:click="act('snooze', null, {{ $d }})" x-on:click="open = false" class="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 dark:hover:bg-white/[0.03]">{{ $d }} gün</button>
                                    @endforeach
                                </div>
                            </div>
                            <button type="button" wire:click="act('dismiss')" wire:confirm="Seçili işler kapatılsın mı?" class="{{ $btn }}">Kapat</button>
                            @if ($showEditorExport)
                                <button type="button" wire:click="exportEditor" class="{{ $btn }}">Google Ads Editor dosyası indir</button>
                            @endif
                            <button type="button" wire:click="$set('bulkIds', [])" class="text-xs text-gray-500 hover:underline">Seçimi kaldır</button>
                        </div>
                    @endif
                </div>

                @if ($manualItems !== [])
                    <div class="rounded-lg bg-warning-50 px-4 py-3 text-xs text-warning-700">
                        <p class="font-semibold">Elle yapılacak ({{ count($manualItems) }})</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-4">
                            @foreach ($manualItems as $manual)<li>{{ $manual['title'] }} — {{ $manual['reason'] }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <ul class="{{ $card }} divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($selectedItems as $row)
                        <li wire:key="cc-{{ $row['key'] }}" @class(['flex items-start gap-3 px-4 py-3', 'bg-brand-50/50 dark:bg-brand-500/5' => $openKey === $row['key']])>
                            <input type="checkbox" value="{{ $row['key'] }}" wire:model.live="bulkIds" aria-label="Seç" class="mt-1 size-4 shrink-0 rounded border-gray-300">
                            <button type="button" wire:click="openItem(@js($row['key']))" class="min-w-0 flex-1 text-left">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $row['brand'] ?? 'Ajans' }}@if ($row['asset']) · {{ $row['asset'] }}@endif</span>
                                <span class="block truncate text-sm text-gray-600 dark:text-gray-400">{{ $row['title'] }}</span>
                                <span class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-gray-500">
                                    @if ($row['channel'])<span>{{ $row['channel'] }}</span>@endif
                                    @if ($age = $ageText($row['since']))<span>· {{ $age }}</span>@endif
                                    @if ($row['impact'])<span class="font-medium text-gray-700 dark:text-gray-300">· {{ $row['impact'] }}</span>@endif
                                    @if (($row['exported_at'] ?? null) !== null)<span class="text-blue-600">· Editor'a aktarıldı</span>@endif
                                </span>
                            </button>
                            <div class="flex shrink-0 items-center gap-2 text-xs">
                                @if (in_array('done', $row['actions'], true))
                                    <button type="button" wire:click="act('done', @js($row['key']))" class="rounded-lg bg-success-500 px-2.5 py-1 font-semibold text-white hover:bg-success-600" title="Yaptım">✓</button>
                                @endif
                                @if (in_array('snooze', $row['actions'], true))
                                    <button type="button" wire:click="act('snooze', @js($row['key']), 7)" class="text-gray-500 hover:underline">7 gün ertele</button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($selected['count'] > $selectedItems->count())
                    <p class="text-xs text-gray-500">İlk {{ $selectedItems->count() }} kayıt gösteriliyor; marka filtresiyle daraltın.</p>
                @endif
            @endif
        </section>
    </div>

    @if ($open)
        <div class="fixed inset-0 z-99999 flex justify-end bg-gray-900/40" wire:click.self="closeItem" role="dialog" aria-modal="true" aria-label="İş ayrıntısı">
            <aside class="h-full w-full max-w-md overflow-y-auto bg-white p-6 shadow-lg dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                        <span class="rounded-full px-2 py-0.5 {{ $tone[$open['severity']] ?? '' }}">{{ $sevLabel[$open['severity']] ?? $open['severity'] }}</span>
                        <span>{{ $open['topic_label'] }}</span>
                        <span>· {{ $open['source_label'] }}</span>
                    </div>
                    <button type="button" wire:click="closeItem" aria-label="Kapat" class="text-gray-400 hover:text-gray-700">✕</button>
                </div>
                <h3 class="mt-3 text-lg font-semibold text-gray-900 dark:text-white">{{ $open['title'] }}</h3>

                <dl class="mt-4 grid grid-cols-3 gap-x-3 gap-y-2 text-sm">
                    @if ($open['brand'])
                        <dt class="text-gray-500">Marka</dt>
                        <dd class="col-span-2"><a href="{{ route('operator.brand', ['brand' => $open['brand_id']]) }}" wire:navigate class="text-brand-600 hover:underline">{{ $open['brand'] }}</a></dd>
                    @endif
                    @if ($open['asset'])
                        <dt class="text-gray-500">Varlık</dt>
                        <dd class="col-span-2">
                            @if ($url = $assetUrl($open['asset_type'], $open['asset_id']))<a href="{{ $url }}" wire:navigate class="text-brand-600 hover:underline">{{ $open['asset'] }}</a>@else{{ $open['asset'] }}@endif
                        </dd>
                    @endif
                    @if ($open['channel'])<dt class="text-gray-500">Kanal</dt><dd class="col-span-2">{{ $open['channel'] }}</dd>@endif
                    @if ($open['since'])
                        <dt class="text-gray-500">Süre</dt>
                        <dd class="col-span-2">{{ $ageText($open['since']) }} <span class="text-gray-400">({{ \Carbon\CarbonImmutable::instance($open['since'])->timezone(config('app.timezone'))->format('d.m.Y') }}'den beri)</span></dd>
                    @endif
                    @if ($open['impact'])<dt class="text-gray-500">Etki</dt><dd class="col-span-2">{{ $open['impact'] }}</dd>@endif
                    @if ($open['money'] > 0)<dt class="text-gray-500">Tutar</dt><dd class="col-span-2">~{{ $fmt($open['money']) }} TL</dd>@endif
                    @if ($open['clicks'] > 0)<dt class="text-gray-500">Tık</dt><dd class="col-span-2">+{{ $fmt($open['clicks']) }} / 90 gün</dd>@endif
                </dl>

                @if (($open['why'] ?? null) || ($open['action'] ?? null))
                    {{-- Ne oldu / Neden önemli / Ne yapmalısın (system alerts carry all three). --}}
                    <dl class="mt-4 space-y-3 text-sm">
                        @if ($open['detail'])<div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ne oldu</dt><dd class="mt-0.5 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $open['detail'] }}</dd></div>@endif
                        @if ($open['why'])<div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Neden önemli</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $open['why'] }}</dd></div>@endif
                        @if ($open['action'])<div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ne yapmalısın</dt><dd class="mt-0.5 text-gray-700 dark:text-gray-300">{{ $open['action'] }}</dd></div>@endif
                    </dl>
                    @if (($open['repeat_label'] ?? '') !== '')<p class="mt-2 text-xs font-medium text-amber-600">{{ $open['repeat_label'] }}</p>@endif
                @elseif ($open['detail'])
                    <p class="mt-4 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $open['detail'] }}</p>
                @endif
                @if ($open['aged'])
                    <p class="mt-4 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/[0.03]">{{ $agingDays }} günü geçtiği için "{{ TopicCatalog::AGED_GROUP }}" altında; durum değişirse yeniden üste çıkar.</p>
                @endif
                @if (($open['draft_status'] ?? null) === 'queued')
                    <p class="mt-4 text-xs text-blue-600">AI metin taslağı hazırlanıyor…</p>
                @elseif (($open['draft_status'] ?? null) === 'ready')
                    <p class="mt-4 text-xs text-success-600">AI metin taslağı hazır — ayrıntılı ekranda açın.</p>
                @endif

                <div class="mt-6 flex flex-wrap gap-2">
                    @if (! empty($open['button']['run_now']))
                        <button type="button" wire:click="runNow({{ (int) $open['button']['run_now'] }})" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">{{ $open['button']['label'] }}</button>
                    @elseif (! empty($open['button']['url']))
                        <a href="{{ $open['button']['url'] }}" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600">{{ $open['button']['label'] }}</a>
                    @endif
                    @if (in_array('done', $open['actions'], true))
                        <button type="button" wire:click="act('done', @js($open['key']))" class="rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Yaptım</button>
                    @endif
                    @if (in_array('snooze', $open['actions'], true))
                        @foreach (CommandCenterPage::SNOOZE_DAYS as $d)
                            <button type="button" wire:click="act('snooze', @js($open['key']), {{ $d }})" class="{{ $btn }}">{{ $d }} gün ertele</button>
                        @endforeach
                    @endif
                    @if (in_array('dismiss', $open['actions'], true))
                        <button type="button" wire:click="act('dismiss', @js($open['key']))" wire:confirm="Bu iş kapatılsın mı?" class="{{ $btn }}">Kapat</button>
                    @endif
                    @if ($open['draftable'] ?? false)
                        <button type="button" wire:click="requestDraft(@js($open['key']))" wire:confirm="1 AI çağrısı yapılacak (aylık AI bütçesinden). Devam edilsin mi?" class="{{ $btn }}">AI metin taslağı hazırla</button>
                    @endif
                    @if ($open['asset_id'] !== null && ActivitySuppression::isBudgetTopic($open))
                        <button type="button" wire:click="pauseAccount(@js($open['key']))" wire:confirm="Müşteri bu hesapta reklamı bilerek durdurdu mu? Bütçe uyarıları hesap yeniden harcayana kadar gizlenir." class="{{ $btn }}">Hesap duraklatıldı (müşteri kararı)</button>
                    @endif
                </div>
                <div class="mt-4 flex flex-col gap-1 text-sm">
                    @if ($open['url'])<a href="{{ $open['url'] }}" wire:navigate class="text-brand-600 hover:underline">{{ $open['link_label'] ?? 'Kaynağında aç' }} →</a>@endif
                    @if ($detailed = $detailedUrl($open))<a href="{{ $detailed }}" wire:navigate class="text-brand-600 hover:underline">Ayrıntılı ekran (kanıt, adımlar, taslaklar) →</a>@endif
                </div>
            </aside>
        </div>
    @endif
</div>
