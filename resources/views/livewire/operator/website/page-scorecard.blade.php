@php
    $number = fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $channelLabels = ['Organic Search' => 'Google organik', 'Paid Search' => 'Google Ads', 'Direct' => 'Doğrudan', 'Referral' => 'Yönlendirme', 'Organic Social' => 'Sosyal', 'Paid Social' => 'Meta / sosyal reklam', 'Organic Maps' => 'Haritalar', 'Email' => 'E-posta', 'Unassigned' => 'Belirsiz'];
    $tones = [
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-300 dark:bg-white/5 dark:text-gray-300 dark:ring-gray-700',
        'blue' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
    ];
    $stateLabels = ['fail' => 'Sorun', 'review' => 'Öneri', 'info' => 'Bilgi', 'pass' => 'Geçti', 'unknown' => 'Veri yok', 'not_applicable' => 'Uygulanmaz'];
    $stateTones = ['fail' => 'text-rose-600', 'review' => 'text-amber-600', 'info' => 'text-blue-600', 'pass' => 'text-emerald-600', 'unknown' => 'text-gray-400', 'not_applicable' => 'text-gray-400'];
    $severityLabels = ['high' => 'yüksek', 'medium' => 'orta', 'low' => 'düşük', 'none' => '—'];
    $sourceLabels = ['crawl' => 'tarama', 'inventory' => 'envanter', 'sitemap' => 'sitemap', 'wordpress' => 'WordPress', 'search_console' => 'Search Console', 'ga4' => 'GA4', 'google_ads' => 'Google Ads'];
    $counts = (array) ($audit?->counts ?? []);
    $period = (array) ($audit?->period ?? []);
    $siteChecks = collect((array) ($audit?->site_checks ?? []))->sortBy(fn ($c) => ['fail' => 0, 'review' => 1, 'info' => 2, 'unknown' => 3, 'pass' => 4, 'not_applicable' => 5][$c['state']] ?? 5);
@endphp
<section class="space-y-4 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold text-gray-800 dark:text-white/90">Sayfa Karnesi (son 28 gün)</h2>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                Sitedeki her adres (taranan, yalnız sitemap’te olan, WordPress ve ölçülen sayfalar) için tek karar: ne sorun var, nasıl çözülür, gerek yoksa “gerek yok”.
                Google tıkları, GA4 ziyaret ve dönüşümleri, trafik kanalı, Google Ads, dizin durumu ve hız yanında.
                @if (! empty($period['start']))
                    {{ \Illuminate\Support\Carbon::parse($period['start'])->format('d.m.Y') }} – {{ \Illuminate\Support\Carbon::parse($period['end'])->format('d.m.Y') }}.
                @endif
                “—” = o kaynakta bu sayfa için veri yok.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Sayfa ara…" class="w-56 rounded-lg border border-gray-200 bg-transparent px-3 py-1.5 text-sm dark:border-gray-700 dark:text-white" />
            @if ($served)
                <x-ta.button type="button" wire:click="refresh" size="sm" variant="outline" :disabled="in_array($audit?->status, ['queued', 'running'], true)">{{ in_array($audit?->status, ['queued', 'running'], true) ? 'Yenileniyor…' : 'Yenile' }}</x-ta.button>
            @endif
        </div>
    </div>

    @if ($message !== '')
        <p @class(['rounded-lg px-3 py-2 text-sm', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $tone === 'success', 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => $tone !== 'success'])>{{ $message }}</p>
    @endif

    @if (! $served)
        <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:bg-white/[0.03] dark:text-gray-400">{{ \App\Support\ServiceScope::NOT_SERVED }}</p>
    @elseif ($audit === null || $audit->computed_at === null)
        <p class="text-sm text-gray-500">
            {{ in_array($audit?->status, ['queued', 'running'], true) ? 'Sayfa Karnesi hesaplanıyor; birkaç dakika içinde dolar.' : ($audit?->status === 'failed' ? 'Son hesaplama başarısız oldu: '.$audit->error : 'Sayfa Karnesi henüz hesaplanmadı. “Yenile” ile kayıtlı veriden hesaplayın (site taraması, sitemap, WordPress, Search Console, GA4, Google Ads).') }}
        </p>
    @else
        <p class="text-xs text-gray-500">
            {{ $audit->url_count }} adres · son hesaplama {{ $audit->computed_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}
            @if ($audit->status === 'failed') · <span class="text-rose-600">son yenileme başarısız: {{ \Illuminate\Support\Str::limit((string) $audit->error, 120) }}</span>@endif
            · Kaynaklar:
            @foreach (['inventory' => 'Envanter', 'sitemap' => 'Sitemap', 'wordpress' => 'WordPress', 'html' => 'Sayfa HTML', 'links' => 'İç bağlantılar', 'search_console' => 'Search Console', 'ga4' => 'GA4', 'ga4_channels' => 'GA4 kanal', 'google_ads' => 'Google Ads', 'inspection' => 'Dizin kontrolü', 'speed' => 'Hız', 'standards_run' => 'Standartlar'] as $source => $label)
                <span @class(['text-emerald-600' => $audit->sources[$source] ?? false, 'text-gray-400 line-through' => ! ($audit->sources[$source] ?? false)])>{{ $label }}</span>@if (! $loop->last) · @endif
            @endforeach
        </p>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="filter('')" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset', 'bg-brand-50 text-brand-700 ring-brand-300' => $verdict === '', 'text-gray-600 ring-gray-300 dark:text-gray-300 dark:ring-gray-700' => $verdict !== ''])>Tümü ({{ $audit->url_count }})</button>
            @foreach ($verdicts as $key => [$label, $toneKey])
                <button type="button" wire:click="filter('{{ $key }}')" data-verdict-filter="{{ $key }}" @class(['rounded-full px-3 py-1 text-xs ring-1 ring-inset', $tones[$toneKey], 'ring-2 font-semibold' => $verdict === $key])>{{ $label }} ({{ (int) ($counts[$key] ?? 0) }})</button>
            @endforeach
        </div>

        @if ($siteChecks->isNotEmpty())
            <details class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]" @if ($siteChecks->whereIn('state', ['fail', 'review'])->isNotEmpty()) open @endif>
                <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-300">Site geneli kontroller ({{ $siteChecks->whereIn('state', ['fail', 'review'])->count() }} sorun / öneri) — bir kez gösterilir</summary>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($siteChecks as $id => $check)
                        <li class="text-xs">
                            <span class="{{ $stateTones[$check['state']] ?? 'text-gray-500' }} font-medium">{{ $stateLabels[$check['state']] ?? $check['state'] }}</span>
                            · <span class="font-medium text-gray-700 dark:text-gray-300">{{ $check['title'] }}</span>: {{ $check['finding'] }}
                            @if (! empty($check['solution']) && in_array($check['state'], ['fail', 'review', 'info'], true))
                                <span class="block pl-4 text-gray-600 dark:text-gray-400">Çözüm: {{ $check['solution'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (! empty($audit->groups))
                    <p class="mt-3 font-medium text-gray-700 dark:text-gray-300">Benzer sayfa grupları</p>
                    <ul class="mt-1 space-y-1 text-xs text-gray-600 dark:text-gray-400">
                        @foreach (array_slice($audit->groups, 0, 20) as $group)
                            <li>{{ $group['kind'] === 'slug' ? 'Kapı sayfa grubu' : 'Neredeyse aynı başlık' }} “{{ $group['head'] }}”: {{ count($group['members']) }} sayfa — {{ implode(', ', array_slice($group['paths'] ?? [], 0, 8)) }}@if (count($group['members']) > 8) … @endif</li>
                        @endforeach
                    </ul>
                @endif
            </details>
        @endif

        @if ($rows === null || $rows->isEmpty())
            <p class="text-sm text-gray-500">{{ $search !== '' || $verdict !== '' ? 'Filtreye uyan sayfa yok.' : 'Henüz sayfa yok. Site taraması veya Search Console verisi geldikten sonra dolar.' }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-400">
                        <tr>
                            <th class="py-2 pr-3">Sayfa</th>
                            <th class="py-2 pr-3">Karar</th>
                            <th class="py-2 pr-3 text-right">Google tık</th>
                            <th class="py-2 pr-3 text-right">Ziyaret</th>
                            <th class="py-2 pr-3 text-right">Dönüşüm</th>
                            <th class="py-2 pr-3">Ana kanal</th>
                            <th class="py-2 pr-3 text-right">Ads tık / maliyet / dönüşüm</th>
                            <th class="py-2">Dizin · Hız</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            @php
                                $facts = (array) $row->facts;
                                $channels = (array) ($facts['channels'] ?? []);
                                $sessionsTotal = array_sum($channels);
                                $topChannel = array_key_first($channels);
                                [$verdictLabel, $verdictTone] = $verdicts[$row->verdict] ?? [$row->verdict, 'gray'];
                            @endphp
                            <tr wire:key="url-verdict-{{ $row->id }}" wire:click="toggle({{ $row->id }})" class="cursor-pointer align-top hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                <td class="max-w-xs py-2 pr-3">
                                    <span class="block truncate font-medium text-gray-800 dark:text-gray-200" title="{{ $row->url }}">{{ $row->path }}</span>
                                    <span class="text-[11px] text-gray-400">{{ implode(' · ', array_map(fn ($s) => $sourceLabels[$s] ?? $s, (array) ($facts['sources'] ?? []))) }}</span>
                                </td>
                                <td class="max-w-md py-2 pr-3 text-xs">
                                    <span class="inline-block rounded px-1.5 py-0.5 font-medium ring-1 ring-inset {{ $tones[$verdictTone] }}">{{ $verdictLabel }}</span>
                                    <span class="mt-0.5 block text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($row->reason, 160) }}</span>
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ $number($row->clicks) }}
                                    @if ($row->clicks_prev !== null && $row->clicks_prev > 0)
                                        @php $change = (int) round(((int) $row->clicks - $row->clicks_prev) / $row->clicks_prev * 100); @endphp
                                        <span class="text-xs {{ $change >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $change >= 0 ? '+' : '' }}{{ $change }}%</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $number($row->sessions) }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $number($row->key_events) }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-600 dark:text-gray-400">
                                    @if ($topChannel !== null && $sessionsTotal > 0)
                                        {{ $channelLabels[$topChannel] ?? $topChannel }} %{{ (int) round($channels[$topChannel] / $sessionsTotal * 100) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 pr-3 text-right text-xs tabular-nums">
                                    @if ($row->ads_clicks !== null)
                                        {{ $number($row->ads_clicks) }} / {{ $number($row->ads_cost) }} / {{ $number($row->ads_conversions, 1) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-2 text-xs">
                                    <span @class(['text-emerald-600' => $row->indexed === true, 'text-rose-600' => $row->indexed === false, 'text-gray-400' => $row->indexed === null])>{{ $row->indexed === null ? 'Dizin ?' : ($row->indexed ? 'Dizinde' : 'Dizinde değil') }}</span>
                                    · <span @class(['text-rose-600' => ($row->lcp_ms ?? 0) > 4000, 'text-gray-500' => ($row->lcp_ms ?? 0) <= 4000])>{{ $row->lcp_ms !== null ? number_format($row->lcp_ms / 1000, 1, ',', '.').' sn' : 'Hız ?' }}</span>
                                </td>
                            </tr>
                            @if ($open !== null && $open->id === $row->id)
                                @php
                                    $f = (array) $open->facts;
                                    $findings = collect((array) $open->findings);
                                    $active = $findings->whereIn('state', ['fail', 'review']);
                                    $rest = $findings->whereNotIn('state', ['fail', 'review']);
                                @endphp
                                <tr wire:key="url-verdict-detail-{{ $row->id }}">
                                    <td colspan="8" class="bg-gray-50 px-3 py-3 text-xs text-gray-600 dark:bg-white/[0.02] dark:text-gray-400" data-url-detail>
                                        <div class="mb-3 rounded-lg bg-white p-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $verdictLabel }} — {{ $open->reason }}</p>
                                            @if ($open->solution)
                                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">Çözüm: {{ $open->solution }}</p>
                                            @endif
                                            @if (! empty($f['group']))
                                                <p class="mt-1">Grup ({{ count($f['group']['members']) }} sayfa, tutulacak: <span class="font-medium">{{ parse_url('https://'.$f['group']['keeper'], PHP_URL_PATH) ?: '/' }}</span>): {{ implode(', ', array_map(fn ($m) => parse_url('https://'.$m, PHP_URL_PATH) ?: '/', array_slice($f['group']['members'], 0, 25))) }}</p>
                                            @endif
                                            @if (! empty($f['missing']))
                                                <p class="mt-1 text-gray-500">Eksik veri: {{ implode(', ', $f['missing']) }}</p>
                                            @endif
                                            @if (! empty($f['checked']))
                                                <p class="mt-1 text-gray-500">Kontrol edilenler: {{ implode(', ', $f['checked']) }}</p>
                                            @endif
                                        </div>

                                        <p class="font-medium text-gray-700 dark:text-gray-300">Bulgular ({{ $active->count() }})</p>
                                        @forelse ($active as $finding)
                                            <div class="mt-1.5 border-l-2 pl-2 {{ $finding['state'] === 'fail' ? 'border-rose-400' : 'border-amber-400' }}">
                                                <p><span class="{{ $stateTones[$finding['state']] }} font-medium">{{ $stateLabels[$finding['state']] }}</span> · <span class="font-medium text-gray-800 dark:text-gray-200">{{ $finding['rule'] }}</span> <span class="text-gray-400">({{ $severityLabels[$finding['severity']] ?? $finding['severity'] }})</span></p>
                                                <p>Bulgu: {{ $finding['finding'] }}</p>
                                                @if (! empty($finding['solution']))
                                                    <p>Çözüm: {{ $finding['solution'] }}</p>
                                                @endif
                                                @if (! empty($finding['action']))
                                                    <a href="{{ $finding['action']['url'] }}" wire:navigate class="font-medium text-brand-600 hover:underline">{{ $finding['action']['label'] }} →</a>
                                                @endif
                                            </div>
                                        @empty
                                            <p>Sorun yok — gerek yok.</p>
                                        @endforelse

                                        <div class="mt-3 grid gap-4 md:grid-cols-3">
                                            <div>
                                                <p class="font-medium text-gray-700 dark:text-gray-300">Sayfa</p>
                                                <p>HTTP: {{ $f['status_code'] ?? '—' }}@if (! empty($f['final_url']) && $f['final_url'] !== $open->url) → {{ $f['final_url'] }}@endif</p>
                                                <p>Dizine açık: {{ $f['noindex'] === null ? '—' : ($f['noindex'] ? 'hayır (noindex)' : 'evet') }} · Canonical: {{ $f['canonical'] ?? 'bildirilmemiş' }}</p>
                                                <p>Başlık: {{ $f['title'] ?? '—' }}</p>
                                                <p>H1: {{ $f['h1'] ?? '—' }} · Kelime: {{ $number($f['word_count'] ?? null) }}</p>
                                                <p>Tür: {{ ['home' => 'ana sayfa', 'post' => 'yazı', 'service' => 'hizmet', 'contact' => 'iletişim', 'about' => 'hakkımızda', 'utility' => 'yardımcı', 'page' => 'sayfa'][$f['kind'] ?? 'page'] ?? $f['kind'] }}@if (! empty($f['service'])) · Hizmet: {{ $f['service'] }}@endif</p>
                                                @if (! empty($f['cms_type']))
                                                    <p>WordPress: {{ $f['cms_type'] }} / {{ $f['cms_status'] ?? '—' }}@if (! empty($f['modified_at'])) · güncelleme {{ \Illuminate\Support\Carbon::parse($f['modified_at'])->format('d.m.Y') }}@endif @if (! empty($f['wp_language'])) · dil {{ $f['wp_language'] }}@endif</p>
                                                @endif
                                                <p>Sitemap: {{ $f['in_sitemap'] === null ? '—' : ($f['in_sitemap'] ? 'var' : 'yok') }} · İç bağlantı: {{ $f['inlinks'] ?? '—' }}</p>
                                                <p><a href="{{ $open->url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">Sayfayı aç ↗</a></p>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-700 dark:text-gray-300">Google ve ölçüm</p>
                                                <p>Tık: {{ $number($f['clicks'] ?? null) }} (önceki 28 gün {{ $number($f['clicks_prev'] ?? null) }}) · Gösterim: {{ $number($f['impressions'] ?? null) }}</p>
                                                <p>Ortalama sıra: {{ $number($f['position'] ?? null, 1) }}@if (! empty($f['top_queries'])) · {{ implode(', ', array_slice($f['top_queries'], 0, 3)) }}@endif</p>
                                                <p>Dizin durumu: {{ $f['inspection']['coverage_state'] ?? '—' }}</p>
                                                <p>GA4: {{ $number($f['sessions'] ?? null) }} ziyaret, {{ $number($f['key_events'] ?? null) }} dönüşüm</p>
                                                @foreach ($channels as $channel => $sessions)
                                                    <p class="pl-2">{{ $channelLabels[$channel] ?? $channel }}: {{ $number($sessions) }} (%{{ $sessionsTotal > 0 ? (int) round($sessions / $sessionsTotal * 100) : 0 }})</p>
                                                @endforeach
                                                <p>Hız (LCP): {{ isset($f['lcp_ms']) ? number_format($f['lcp_ms'] / 1000, 1, ',', '.').' sn' : '—' }}</p>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-700 dark:text-gray-300">Arama hedefi ve yapılandırılmış veri</p>
                                                <p>Küme / hizmet hedefi: {{ ! empty($f['targets']) ? implode(', ', $f['targets']) : '—' }}</p>
                                                <p>Yamyamlaşma: {{ ! empty($f['cannibal']) ? '“'.$f['cannibal']['subject'].'” ('.count($f['cannibal']['pages']).' sayfa)' : 'yok' }}</p>
                                                @if (is_array($f['signals'] ?? null))
                                                    <p>Şema: {{ implode(', ', array_slice($f['signals']['jsonld_types'] ?? [], 0, 8)) ?: 'yok' }}</p>
                                                    <p>Yazar: {{ ($f['signals']['author'] ?? false) ? 'var' : 'yok' }} · Tarih: {{ ($f['signals']['visible_date'] ?? false) ? 'var' : 'yok' }} · SSS: {{ ($f['signals']['faq_content'] ?? false) ? 'var' : 'yok' }}</p>
                                                    @if (! empty($f['signals']['hreflang']))
                                                        <p>hreflang: {{ implode(', ', array_map(fn ($h) => $h['language'], $f['signals']['hreflang'])) }} · lang: {{ $f['signals']['lang'] ?? '—' }}</p>
                                                    @endif
                                                @else
                                                    <p>Sayfa HTML’i okunmadı.</p>
                                                @endif
                                            </div>
                                        </div>

                                        @if ($rest->isNotEmpty())
                                            <details class="mt-3">
                                                <summary class="cursor-pointer text-gray-500">Geçen ve uygulanmayan kontroller ({{ $rest->count() }})</summary>
                                                <ul class="mt-1 space-y-0.5">
                                                    @foreach ($rest as $finding)
                                                        <li><span class="{{ $stateTones[$finding['state']] ?? '' }}">{{ $stateLabels[$finding['state']] ?? $finding['state'] }}</span> · {{ $finding['rule'] }}: {{ $finding['finding'] }}</li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $rows->links() }}
        @endif
    @endif
</section>
