@php
    use App\Enums\AdvisorItemStatus;
    use Illuminate\Support\Str;

    $tz = config('app.timezone');
    $card = 'rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]';
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.06]';
    $select = 'h-10 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 focus:border-brand-300 focus:outline-none focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $spinner = '<svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
    $refreshIcon = '<svg class="size-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M16.5 10a6.5 6.5 0 1 1-1.9-4.6M16.5 3.5v3.3h-3.3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $planStates = ['queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'completed' => 'Hazır', 'failed' => 'Başarısız'];
    $symbol = fn (?string $currency): string => match ($currency) { 'TRY' => '₺', 'USD' => '$', 'EUR' => '€', null => '', default => $currency.' ' };
    $money = fn (mixed $amount, ?string $currency): string => is_numeric($amount) ? $symbol($currency).number_format((float) $amount, 0, ',', '.') : '—';
    $accent = fn ($category): string => match ($category->value) {
        'waste' => 'border-l-error-500', 'growth' => 'border-l-success-500', 'measurement' => 'border-l-warning-500',
        'landing' => 'border-l-blue-500', 'change' => 'border-l-gray-400', default => 'border-l-brand-500',
    };
    // Evidence tables: column key => [label, format]
    $columns = [
        'term' => ['Arama terimi', 'text'], 'word' => ['Kelime', 'text'], 'keyword' => ['Anahtar kelime', 'text'], 'name' => ['Kampanya', 'text'],
        'url' => ['Sayfa', 'url'], 'action' => ['Dönüşüm işlemi', 'text'], 'label' => ['Öneri', 'text'], 'campaign' => ['Kampanya', 'text'],
        'quality_score' => ['KP', 'num'], 'match_type' => ['Eşleme', 'text'], 'category' => ['Kategori', 'text'], 'status' => ['Durum', 'text'], 'primary' => ['Birincil', 'bool'],
        'cost' => ['Harcama', 'money'], 'clicks' => ['Tık', 'num'], 'impressions' => ['Gösterim', 'num'], 'conversions' => ['Dönüşüm', 'num'],
        'cpa' => ['CPA', 'money'], 'lost_is_budget' => ['Bütçe kaybı %', 'num'], 'extra_conversions' => ['Tahmini ek dönüşüm', 'num'], 'daily_budget' => ['Günlük bütçe', 'money'],
        'terms' => ['Terim sayısı', 'num'], 'examples' => ['Örnek terimler', 'list'], 'count' => ['Adet', 'num'], 'campaigns' => ['Kampanyalar', 'list'],
        'issues' => ['Sorun', 'list'], 'weak' => ['Zayıf bileşen', 'list'], 'issue' => ['Sorun', 'text'], 'fix' => ['Yapılacak', 'text'],
        'type' => ['Değişen', 'text'], 'operation' => ['İşlem', 'text'], 'fields' => ['Alanlar', 'text'], 'user' => ['Kim', 'text'], 'low_intent' => ['Düşük niyet', 'bool'], 'pmax' => ['PMax', 'bool'],
    ];
    $sectionLabels = [
        'terms' => 'Arama terimleri', 'words' => 'Tek kelime negatifler (sıralı eşleme)', 'service_terms' => 'Hizmet adı geçen dönüşümsüz terimler (negatif ekleme, sayfayı kontrol et)',
        'campaigns' => 'Kampanyalar', 'waste_campaigns' => 'Bütçe kaydırılabilecek dönüşümsüz kampanyalar', 'actions' => 'Dönüşüm işlemleri', 'issues' => 'Sorunlar',
        'pages' => 'Açılış sayfaları', 'keywords' => 'Anahtar kelimeler', 'recommendations' => 'Google önerileri', 'events' => 'O günkü değişiklikler',
    ];
    $weakLabels = ['ad_relevance' => 'reklam alaka', 'landing_page_experience' => 'açılış sayfası', 'expected_ctr' => 'beklenen TO'];
@endphp
<div class="space-y-6" @if($polling) wire:poll.4s @endif>

    {{-- 1. Accounts board --}}
    <section class="{{ $card }} p-5" x-data="{ help: false }">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $asset ? 'Danışman · '.$asset->name : 'Haftalık reklam danışmanı' }}</h2>
                @if ($plansPending > 0)
                    <p class="mt-1 inline-flex items-center gap-2 text-sm font-medium text-brand-600 dark:text-brand-400">{!! $spinner !!} {{ $plansPending }} hesap inceleniyor… Sayfa kendini günceller.</p>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Her Pazartesi {{ config('moxdop-advisor.schedule.weekly_time', '07:00') }}'de toplanmış veriden kendiliğinden çalışır. Google Ads'e hiçbir şey yazılmaz; listeleri kopyalayıp sen uygularsın.</p>
                @endif
                <button type="button" x-on:click="help = ! help" class="mt-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"><span x-text="help ? 'Gizle' : 'Danışman neye bakıyor?'">Danışman neye bakıyor?</span></button>
            </div>
            @if ($asset)
                <button type="button" wire:click="refreshAsset({{ $asset->id }})" wire:loading.attr="disabled" @disabled($plansPending > 0) class="{{ $btnPrimary }}">{!! $refreshIcon !!} Şimdi incele</button>
            @else
                <button type="button" wire:click="refreshAll" wire:loading.attr="disabled" @disabled($plansPending > 0) class="{{ $btnPrimary }}">{!! $refreshIcon !!} Tüm hesapları incele</button>
            @endif
        </div>
        <div x-show="help" x-cloak class="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4 dark:border-gray-800 dark:text-gray-300">
            <p><strong class="text-gray-800 dark:text-white/90">İsraf:</strong> dönüşümsüz harcayan arama terimleri (mevcut negatiflerle karşılaştırılır) ve kampanyalar.</p>
            <p><strong class="text-gray-800 dark:text-white/90">Büyüme:</strong> bütçesi yetmeyen kârlı kampanyalar, anahtar kelime yapılmamış dönüşüm getiren terimler.</p>
            <p><strong class="text-gray-800 dark:text-white/90">Ölçüm:</strong> birincil dönüşüm, sayım ayarı, otomatik etiketleme, GA4 ile tutarlılık.</p>
            <p><strong class="text-gray-800 dark:text-white/90">Diğer:</strong> açılış sayfası, reklam gücü, öğeler, kalite puanı, değişiklik sonrası CPA artışı, Google'ın süzülmüş önerileri.</p>
        </div>
    </section>

    @if ($message !== '')
        <div role="status" @class([
            'flex items-start gap-2 rounded-xl border p-3 text-sm',
            'border-success-200 bg-success-50 text-success-800 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300' => $messageTone === 'success',
            'border-error-200 bg-error-50 text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-300' => $messageTone === 'error',
        ])>
            <span class="flex-1">{{ $message }}</span>
            <button type="button" wire:click="$set('message', '')" class="text-xs opacity-60 hover:opacity-100" aria-label="Kapat">✕</button>
        </div>
    @endif

    {{-- 2. KPIs --}}
    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <div class="{{ $card }} p-4">
            <p class="text-xs font-medium text-gray-500">Açık öneri</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $kpis['open'] }}</p>
            <p class="mt-2 text-xs text-gray-400">Hesap başına en fazla {{ config('moxdop-advisor.google_ads.max_open', 6) }}; az ama gerçek</p>
        </div>
        <button type="button" wire:click="$set('categoryFilter', 'waste')" class="{{ $card }} p-4 text-left transition hover:border-error-300">
            <p class="text-xs font-medium text-gray-500">Tespit edilen israf (30 gün)</p>
            <p class="mt-2 text-2xl font-bold text-error-600 dark:text-error-400">{{ $kpis['waste'] !== null ? $money($kpis['waste'], $kpis['currency']) : 'çoklu para birimi' }}</p>
            <p class="mt-2 text-xs text-gray-400">Dönüşümsüz terim ve kampanya harcaması</p>
        </button>
        <div class="{{ $card }} p-4">
            <p class="text-xs font-medium text-gray-500">Acil</p>
            <p @class(['mt-2 text-2xl font-bold', 'text-error-600 dark:text-error-400' => $kpis['urgent'] > 0, 'text-gray-800 dark:text-white/90' => $kpis['urgent'] === 0])>{{ $kpis['urgent'] }}</p>
            <p class="mt-2 text-xs text-gray-400">{{ $kpis['urgent'] > 0 ? 'Kritik / yüksek önemli öneri' : 'Acil bir sorun yok' }}</p>
        </div>
        <div class="{{ $card }} p-4">
            <p class="text-xs font-medium text-gray-500">İncelenen hesap</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white/90">{{ $kpis['accounts'] }}<span class="text-sm font-medium text-gray-400"> / {{ $board->count() }}</span></p>
            <p class="mt-2 text-xs text-gray-400">Bağlı ve verisi toplanmış Google Ads hesabı</p>
        </div>
    </div>

    {{-- 3. Accounts table --}}
    @if ($board->isNotEmpty())
        <section class="{{ $card }} overflow-hidden">
            <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Hesaplar</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-medium text-gray-500 dark:bg-white/[0.02] dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-2.5">Hesap</th>
                            <th class="px-3 py-2.5">Son inceleme</th>
                            <th class="px-3 py-2.5 text-right">Harcama (30 gün)</th>
                            <th class="px-3 py-2.5 text-right">CPA</th>
                            <th class="px-3 py-2.5 text-center">Açık / acil</th>
                            <th class="px-3 py-2.5 text-right">İsraf</th>
                            <th class="px-5 py-2.5"><span class="sr-only">İşlem</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-800 dark:text-gray-300">
                        @foreach ($board as $row)
                            @php $busy = in_array($row['plan_status'], ['queued', 'running'], true); @endphp
                            <tr wire:key="adv-row-{{ $row['id'] }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-3">
                                    @if (! $asset)
                                        <button type="button" wire:click="$set('assetFilter', '{{ $assetFilter === (string) $row['id'] ? '' : $row['id'] }}')" class="text-left">
                                            <span @class(['block font-medium hover:text-brand-600', 'text-brand-600' => $assetFilter === (string) $row['id'], 'text-gray-800 dark:text-white/90' => $assetFilter !== (string) $row['id']])>{{ $row['name'] }}</span>
                                            <span class="block text-xs text-gray-400">{{ $row['brand'] }}</span>
                                        </button>
                                    @else
                                        <span class="block font-medium text-gray-800 dark:text-white/90">{{ $row['name'] }}</span>
                                        <span class="block text-xs text-gray-400">{{ $row['brand'] }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    @if ($busy)
                                        <span class="inline-flex items-center gap-1.5 font-medium text-brand-600 dark:text-brand-400">{!! $spinner !!} {{ $planStates[$row['plan_status']] }}</span>
                                    @elseif ($row['plan_status'] === 'failed')
                                        <x-ta.badge color="error" size="sm" title="{{ $row['plan_error'] }}">Başarısız</x-ta.badge>
                                    @elseif ($row['last_run_at'])
                                        <span class="text-gray-600 dark:text-gray-300">{{ $row['last_run_at']->timezone($tz)->locale('tr')->diffForHumans() }}</span>
                                        @if ($row['summary'])<span class="block text-gray-400">{{ $row['summary'] }}</span>@endif
                                    @else
                                        <span class="text-gray-400">Henüz incelenmedi</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">{{ $row['account'] ? $money($row['account']['cost'] ?? null, $row['currency']) : '—' }}</td>
                                <td class="px-3 py-3 text-right">{{ $row['account'] ? $money($row['account']['cpa'] ?? null, $row['currency']) : '—' }}</td>
                                <td class="px-3 py-3 text-center">{{ $row['open'] ?: '—' }}@if ($row['urgent']) <x-ta.badge color="error" size="sm">{{ $row['urgent'] }}</x-ta.badge>@endif</td>
                                <td class="px-3 py-3 text-right font-medium text-error-600 dark:text-error-400">{{ $row['waste'] > 0 ? $money($row['waste'], $row['currency']) : '—' }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="refreshAsset({{ $row['id'] }})" wire:loading.attr="disabled" @disabled($busy) class="{{ $btnSecondary }}">{!! $refreshIcon !!} İncele</button>
                                        @if (! $asset)
                                            <a wire:navigate href="{{ route('operator.google-ads.overview', ['assetId' => $row['id'], 'tab' => 'advisor']) }}" class="{{ $btnSecondary }}">Aç →</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <div class="{{ $card }} px-6 py-10 text-center text-sm text-gray-500">Aktif Google Ads hesabı yok. Markaya Google Ads varlığı ekleyip hesabı bağla.</div>
    @endif

    {{-- 4. Item list --}}
    <section class="space-y-3">
        <div class="{{ $card }} px-4 pt-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="-mb-px flex flex-wrap gap-1" role="tablist" aria-label="Öneri türü">
                    @php $tabs = array_merge([['', 'Tümü', array_sum($counts)]], array_map(fn ($c): array => [$c->value, $c->label(), $counts[$c->value] ?? 0], array_values(array_filter($categories, fn ($c): bool => ($counts[$c->value] ?? 0) > 0 || $categoryFilter === $c->value)))); @endphp
                    @foreach ($tabs as [$value, $label, $count])
                        <button type="button" role="tab" aria-selected="{{ $categoryFilter === $value ? 'true' : 'false' }}" wire:click="$set('categoryFilter', '{{ $value }}')" @class([
                            'inline-flex items-center gap-2 border-b-2 px-3 pb-3 pt-1 text-sm font-medium transition',
                            'border-brand-500 text-brand-600 dark:text-brand-400' => $categoryFilter === $value,
                            'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $categoryFilter !== $value,
                        ])>{{ $label }} <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-brand-50 text-brand-600 dark:bg-brand-500/15' => $categoryFilter === $value, 'bg-gray-100 text-gray-500 dark:bg-white/5' => $categoryFilter !== $value])>{{ $count }}</span></button>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-2 pb-3" role="group" aria-label="Filtreler">
                    @if (! $asset)
                        <select wire:model.live="customerFilter" aria-label="Müşteri" class="{{ $select }}">
                            <option value="">Tüm müşteriler</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select wire:model.live="statusFilter" aria-label="Durum" class="{{ $select }}">
                        @foreach (['open' => 'Açık öneriler', 'done' => 'Yapıldı', 'skipped' => 'Atlandı', 'resolved' => 'Kendiliğinden kapandı', 'all' => 'Hepsi'] as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($customerFilter !== '' || $assetFilter !== '' || $categoryFilter !== '' || $statusFilter !== 'open')
                        <button type="button" wire:click="clearFilters" class="text-xs font-medium text-gray-500 hover:text-brand-600">Filtreleri temizle</button>
                    @endif
                </div>
            </div>
        </div>

        @forelse ($items as $item)
            @php
                $open = $item->status === AdvisorItemStatus::Open;
                $expanded = $expandedId === $item->id;
                $evidence = is_array($item->evidence) ? $item->evidence : [];
                $draft = is_array($item->draft) ? $item->draft : null;
            @endphp
            <article wire:key="adv-item-{{ $item->id }}" @class([$card, 'border-l-4', $accent($item->category), 'opacity-70' => ! $open, 'shadow-theme-sm' => $expanded])>
                <div class="flex flex-wrap items-start gap-4 p-4 sm:flex-nowrap">
                    <span class="hidden size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-sm font-semibold text-gray-500 sm:flex dark:bg-white/5 dark:text-gray-400">{{ $loop->iteration }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ta.badge :color="$item->category->color()" size="sm">{{ $item->category->label() }}</x-ta.badge>
                            <x-ta.badge :color="$item->severityColor()" size="sm">{{ $item->severityLabel() }}</x-ta.badge>
                            @if (! $open)<x-ta.badge color="dark" size="sm">{{ $item->status->label() }}</x-ta.badge>@endif
                            @if (! $asset && $item->digitalAsset)
                                <span class="ml-1 text-xs text-gray-500">{{ $item->digitalAsset->name }}@if ($item->brand) · {{ $item->brand->name }}@endif</span>
                            @endif
                        </div>
                        <button type="button" wire:click="toggle({{ $item->id }})" aria-expanded="{{ $expanded ? 'true' : 'false' }}" class="group mt-2 block text-left">
                            <span class="text-base font-semibold text-gray-800 group-hover:text-brand-600 dark:text-white/90">{{ $item->title }}</span>
                        </button>
                        <p @class(['mt-1 text-sm text-gray-600 dark:text-gray-300', 'line-clamp-2' => ! $expanded])>{{ $item->reason }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
                            @if ($item->impact_label)<span>Etki <strong class="text-gray-700 dark:text-gray-200">{{ $item->impact_label }}</strong></span>@endif
                            <span>İlk görüldü {{ $item->created_at?->timezone($tz)->format('d.m.Y') }}</span>
                            <button type="button" wire:click="toggle({{ $item->id }})" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $expanded ? 'Detayı gizle' : ($item->copy_text ? 'Liste ve adımlar' : 'Kanıt ve adımlar') }}</button>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2 sm:flex-col">
                        @if ($open)
                            <button type="button" wire:click="markDone({{ $item->id }})" class="inline-flex items-center justify-center gap-1 rounded-lg bg-success-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-success-600">✓ Yapıldı</button>
                            <button type="button" wire:click="skip({{ $item->id }})" wire:confirm="Bu öneri atlansın mı? Tekrar gösterilmez." class="{{ $btnSecondary }}">Atla</button>
                        @elseif ($item->status !== AdvisorItemStatus::Resolved)
                            <button type="button" wire:click="reopen({{ $item->id }})" class="{{ $btnSecondary }}">Yeniden aç</button>
                        @endif
                    </div>
                </div>

                @if ($expanded)
                    <div class="grid gap-3 border-t border-gray-100 p-4 lg:grid-cols-2 dark:border-gray-800">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Yapılacaklar</p>
                            <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($item->checklist ?? [] as $step)<li>{{ $step }}</li>@endforeach
                            </ol>
                        </div>

                        @if ($item->copy_text)
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]" x-data="{ copied: false }">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Kopyala-yapıştır ({{ count(explode("\n", $item->copy_text)) }} satır)</p>
                                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($item->copy_text)); copied = true; setTimeout(() => copied = false, 2000)" class="rounded-md bg-white px-2 py-1 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 dark:bg-gray-900"><span x-show="! copied">Listeyi kopyala</span><span x-show="copied">Kopyalandı</span></button>
                                </div>
                                <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded-md bg-white p-2 font-mono text-xs text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $item->copy_text }}</pre>
                            </div>
                        @endif

                        @if ($item->rule_id === 'weak-ad-strength')
                            <div class="rounded-lg bg-blue-50 p-3 lg:col-span-2 dark:bg-blue-500/10" x-data="{ copied: false }">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-medium uppercase tracking-wide text-blue-700 dark:text-blue-300">Reklam metni taslağı (AI)</p>
                                    @if ($item->draft_status === 'queued')
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-blue-700 dark:text-blue-300">{!! $spinner !!} Hazırlanıyor…</span>
                                    @else
                                        <button type="button" wire:click="requestDraft({{ $item->id }})" wire:confirm="Bu reklam grubu için 1 AI çağrısı yapılacak (aylık AI bütçesinden). Devam edilsin mi?" class="rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200 hover:bg-blue-100 dark:bg-gray-900 dark:text-blue-300">{{ $draft && $item->draft_status === 'ready' ? 'Yeniden hazırla' : 'Taslak hazırla' }}</button>
                                    @endif
                                </div>
                                @if ($item->draft_status === 'failed')
                                    <p class="mt-2 text-sm text-error-700 dark:text-error-300">{{ $draft['error'] ?? 'Taslak hazırlanamadı.' }}</p>
                                @elseif ($item->draft_status === 'ready' && $draft)
                                    @php $draftText = "Başlıklar:\n".implode("\n", $draft['headlines'] ?? [])."\n\nAçıklamalar:\n".implode("\n", $draft['descriptions'] ?? [])."\n\nYol: ".($draft['path1'] ?? '').'/'.($draft['path2'] ?? ''); @endphp
                                    <div class="mt-2 grid gap-3 text-sm text-gray-800 sm:grid-cols-2 dark:text-gray-200">
                                        <div>
                                            <p class="text-xs text-gray-500">Başlıklar (≤30 karakter)</p>
                                            <ol class="mt-1 list-decimal space-y-0.5 pl-5">@foreach ($draft['headlines'] ?? [] as $headline)<li>{{ $headline }} <span class="text-xs text-gray-400">{{ mb_strlen($headline) }}</span></li>@endforeach</ol>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">Açıklamalar (≤90 karakter)</p>
                                            <ol class="mt-1 list-decimal space-y-1 pl-5">@foreach ($draft['descriptions'] ?? [] as $description)<li>{{ $description }} <span class="text-xs text-gray-400">{{ mb_strlen($description) }}</span></li>@endforeach</ol>
                                            <p class="mt-2 text-xs text-gray-500">Görünen yol: /{{ $draft['path1'] ?? '' }}/{{ $draft['path2'] ?? '' }}</p>
                                        </div>
                                    </div>
                                    @if (! empty($draft['notes']))<p class="mt-2 text-xs text-blue-800 dark:text-blue-300">{{ $draft['notes'] }}</p>@endif
                                    <button type="button" x-on:click="navigator.clipboard.writeText(@js($draftText)); copied = true; setTimeout(() => copied = false, 2000)" class="mt-2 rounded-md bg-white px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-gray-900"><span x-show="! copied">Taslağı kopyala</span><span x-show="copied">Kopyalandı</span></button>
                                @elseif ($item->draft_status === null)
                                    <p class="mt-1 text-xs text-blue-800 dark:text-blue-300">Bu reklam grubunun anahtar kelimeleri, dönüşüm getiren arama terimleri ve açılış sayfasından 12–15 başlık ve 4 açıklama taslağı hazırlanır. Google Ads'e yazılmaz; kontrol edip kendin eklersin.</p>
                                @endif
                            </div>
                        @endif

                        <div class="rounded-lg bg-gray-50 p-3 lg:col-span-2 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Neden · kanıt</p>
                            @foreach ($evidence as $key => $value)
                                @if (is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0] ?? null))
                                    @php $cols = array_values(array_filter(array_keys($value[0]), fn ($c) => isset($columns[$c]))); @endphp
                                    <p class="mt-3 text-xs font-medium text-gray-600 dark:text-gray-300">{{ $sectionLabels[$key] ?? $key }}</p>
                                    <div class="mt-1 overflow-x-auto">
                                        <table class="w-full text-left text-xs">
                                            <thead class="text-gray-400"><tr>@foreach ($cols as $col)<th class="py-1 pr-3 font-medium">{{ $columns[$col][0] }}</th>@endforeach</tr></thead>
                                            <tbody class="text-gray-700 dark:text-gray-300">
                                                @foreach (array_slice($value, 0, 25) as $row)
                                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                                        @foreach ($cols as $col)
                                                            @php $cell = $row[$col] ?? null; $format = $columns[$col][1]; @endphp
                                                            <td class="py-1 pr-3 align-top">
                                                                @if ($format === 'money'){{ $money($cell, $item->currency) }}
                                                                @elseif ($format === 'num'){{ is_numeric($cell) ? rtrim(rtrim(number_format((float) $cell, 1, ',', '.'), '0'), ',') : '—' }}
                                                                @elseif ($format === 'bool'){{ $cell ? '✓' : '' }}
                                                                @elseif ($format === 'list'){{ is_array($cell) ? implode(', ', array_map(fn ($v) => $weakLabels[$v] ?? $v, $cell)) : $cell }}
                                                                @elseif ($format === 'url')<span class="break-all">{{ Str::limit((string) $cell, 80) }}</span>
                                                                @else{{ is_scalar($cell) ? $cell : '' }}@endif
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @elseif (is_array($value) && $value !== [] && array_is_list($value))
                                    <p class="mt-3 text-xs font-medium text-gray-600 dark:text-gray-300">{{ $sectionLabels[$key] ?? ($key === 'missing' ? 'Eksikler' : $key) }}</p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs text-gray-700 dark:text-gray-300">@foreach ($value as $line)<li>{{ is_scalar($line) ? $line : json_encode($line, JSON_UNESCAPED_UNICODE) }}</li>@endforeach</ul>
                                @elseif (in_array($key, ['before', 'after'], true) && is_array($value))
                                    <p class="mt-2 text-xs text-gray-700 dark:text-gray-300"><strong>{{ $key === 'before' ? 'Önce' : 'Sonra' }} ({{ $value['days'] }} gün):</strong> {{ $money($value['cost'], $item->currency) }} harcama · {{ rtrim(rtrim(number_format((float) $value['conversions'], 1, ',', '.'), '0'), ',') }} dönüşüm · {{ number_format((int) $value['clicks'], 0, ',', '.') }} tık</p>
                                @endif
                            @endforeach
                            @if (isset($evidence['ads_conversions']))
                                <p class="mt-2 text-xs text-gray-700 dark:text-gray-300">Google Ads: {{ $evidence['ads_conversions'] }} dönüşüm, {{ number_format((int) $evidence['ads_clicks'], 0, ',', '.') }} tık · GA4 (google / cpc): {{ $evidence['ga4_key_events'] }} temel etkinlik, {{ number_format((int) $evidence['ga4_sessions'], 0, ',', '.') }} oturum</p>
                            @endif
                            @if (isset($evidence['threshold']))
                                <p class="mt-2 text-xs text-gray-400">Eşik: dönüşümsüz ve en az {{ $money($evidence['threshold'], $item->currency) }} harcama. Kaynak: son {{ config('moxdop-advisor.google_ads.window_days', 30) }} günün arama terimi raporu.</p>
                            @endif
                            @if (! empty($evidence['final_url']))
                                <p class="mt-2 text-xs text-gray-500">Açılış sayfası: <span class="break-all">{{ $evidence['final_url'] }}</span></p>
                            @endif
                        </div>
                    </div>
                @endif
            </article>
        @empty
            <div class="{{ $card }} px-6 py-12 text-center">
                <p class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $statusFilter === 'open' ? 'Açık öneri yok' : 'Öneri yok' }}</p>
                <p class="mx-auto mt-1 max-w-md text-sm text-gray-500">
                    @if ($board->whereNotNull('last_run_at')->isEmpty())
                        Henüz inceleme yapılmadı. "{{ $asset ? 'Şimdi incele' : 'Tüm hesapları incele' }}" ile başlat; toplanmış veriden birkaç saniyede çalışır.
                    @elseif ($statusFilter !== 'open' || $categoryFilter !== '' || $assetFilter !== '' || $customerFilter !== '')
                        Seçili filtrelerde öneri yok.
                    @else
                        Bu hafta önemli bir iş yok. Bu da iyi bir sonuç.
                    @endif
                </p>
            </div>
        @endforelse
    </section>
</div>
