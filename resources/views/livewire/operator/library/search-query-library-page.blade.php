<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Kütüphane</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sorgular</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Manuel araştırma, Google Ads, Search Console ve DataForSEO sorgularını kaynak bilgisiyle birlikte tek bir tekrar kullanılabilir havuzda tutar.</p>
        </div>
        <div class="flex flex-wrap gap-2"><a href="{{ route('operator.library.services') }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30">← Hizmet kütüphanesi</a><a href="{{ route('operator.library.brand-query-portfolios') }}" wire:navigate class="rounded-lg px-4 py-2.5 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:ring-brand-500/30">Marka portföyleri →</a></div>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $message_tone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : ($message_tone === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800') }}">{{ $message }}</div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['Tüm sorgular', $summary['total']], ['Etkin', $summary['active']], ['Hizmet atanmamış', $summary['unassigned']], ['Markalı / hariç tutulabilir', $summary['branded']]] as [$label, $value])
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($value, 0, ',', '.') }}</p></div>
        @endforeach
    </div>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Sorgu ekle</h2><p class="mt-1 text-sm text-gray-500">Tek sorgu ekleyin veya her satıra bir sorgu gelecek şekilde toplu yapıştırın.</p></div></div>
        <div class="mt-4 grid gap-5 xl:grid-cols-2">
            <form wire:submit="addQuery" class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Tek sorgu</h3>
                <textarea wire:model="query_text" rows="3" placeholder="Örn. bornova implant doktoru" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                @error('query_text') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <div class="grid gap-3 sm:grid-cols-2">
                    <select wire:model="query_service_id" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hizmet seçin</option>@foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                    <select wire:model="query_sector" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Sektör (isteğe bağlı)</option>@foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <input wire:model="query_language" type="text" placeholder="Dil: tr" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <input wire:model="query_market" type="text" placeholder="Pazar: TR" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <input wire:model="query_demand_family" type="text" placeholder="Talep ailesi (isteğe bağlı)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <select wire:model="query_location_scope" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="none">Lokasyon yok</option><option value="country">Ülke</option><option value="city">Şehir</option><option value="district">İlçe</option><option value="pattern">{location} kalıbı</option></select>
                    <input wire:model="query_location_value" type="text" placeholder="Lokasyon / {location}" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
                    <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300"><input wire:model="query_is_branded" type="checkbox" class="rounded border-gray-300 text-brand-500" /> Marka/lisanslı ürün sorgusu</label>
                </div>
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Kütüphaneye ekle</button>
            </form>

            <form wire:submit="addPastedQueries" class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Toplu metin</h3>
                <p class="text-xs text-gray-500">Soldaki hizmet, dil, pazar ve diğer sınıflandırmalar yapıştırılan tüm satırlara uygulanır.</p>
                <textarea wire:model="paste_text" rows="10" placeholder="Her satıra bir sorgu" class="w-full rounded-lg border-gray-300 font-mono text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
                @error('paste_text') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg px-4 py-2.5 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 disabled:opacity-50">Satırları işle</button>
            </form>
        </div>
    </section>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">CSV / Excel içe aktar</h2>
        <p class="mt-1 text-sm text-gray-500">CSV, TSV, TXT ve XLSX desteklenir. Sorgu sütunu için <code>query</code>, <code>search_term</code>, <code>keyword</code>, <code>sorgu</code> veya <code>arama_terimi</code> kullanılabilir.</p>
        <form wire:submit="importQueries" class="mt-4 grid gap-3 lg:grid-cols-[1.5fr_1fr_1fr_0.6fr_0.6fr_auto]">
            <input wire:model="import_file" type="file" accept=".csv,.tsv,.txt,.xlsx" class="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700" />
            <select wire:model="import_source_type" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="csv">CSV / genel liste</option><option value="xlsx">Excel / genel liste</option><option value="google_ads">Google Ads</option><option value="search_console">Search Console</option><option value="dataforseo">DataForSEO</option></select>
            <select wire:model="import_service_id" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Dosyadaki hizmet / atanmamış</option>@foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            <input wire:model="import_language" type="text" placeholder="tr" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <input wire:model="import_market" type="text" placeholder="TR" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove>İçe aktar</span><span wire:loading>İşleniyor…</span></button>
        </form>
        @error('import_file') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

        @if ($imports->isNotEmpty())
            <details class="mt-4 text-sm"><summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">Son içe aktarmalar</summary><div class="mt-3 overflow-x-auto"><table class="min-w-full text-xs"><thead class="text-left text-gray-400"><tr><th class="py-2">Dosya</th><th>Kaynak</th><th>Durum</th><th>Kabul</th><th>Hata</th><th>Zaman</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach ($imports as $import)<tr><td class="py-2 pr-4">{{ $import->original_filename ?: '—' }}</td><td class="pr-4">{{ $sourceOptions[$import->source_type] ?? $import->source_type }}</td><td class="pr-4">{{ $import->status }}</td><td class="pr-4">{{ $import->accepted_rows }}</td><td class="pr-4">{{ $import->failed_rows }}</td><td>{{ $import->completed_at?->diffForHumans() ?: 'İşleniyor' }}</td></tr>@endforeach</tbody></table></div></details>
        @endif
    </section>

    <section
        @if ($aiRun && in_array($aiRun->status, ['queued', 'running'], true)) wire:poll.4s="refreshAiRun" @endif
        class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">AI Sorgu Kütüphanecisi</h2>
                    <span class="rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">İnsan onaylı</span>
                </div>
                <p class="mt-1 max-w-3xl text-sm text-gray-500">AI yalnızca aday üretir ve sınıflandırma önerir. Hacim, trafik veya sıralama uydurmaz; hiçbir öneri siz onaylamadan kütüphaneye uygulanmaz.</p>
            </div>
            @if ($aiRuns->isNotEmpty())
                <div class="flex flex-wrap gap-2">
                    @foreach ($aiRuns as $pastRun)
                        <button wire:click="openAiRun({{ $pastRun->id }})" type="button" class="rounded-lg px-2.5 py-1.5 text-xs {{ $aiRun?->id === $pastRun->id ? 'bg-violet-100 font-semibold text-violet-800 dark:bg-violet-500/20 dark:text-violet-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                            #{{ $pastRun->id }} · {{ $pastRun->operation_type === 'generate' ? 'üretim' : 'sınıflama' }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <form wire:submit="queueAiGeneration" class="mt-4 grid gap-3 rounded-lg border border-gray-200 p-4 lg:grid-cols-[1.4fr_0.55fr_0.55fr_1fr_1.2fr_0.55fr_auto] dark:border-gray-700">
            <select wire:model="ai_service_id" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                <option value="">Hizmet seçin</option>
                @foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </select>
            <input wire:model="ai_language" type="text" placeholder="Dil: tr" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <input wire:model="ai_market" type="text" placeholder="Pazar: TR" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <select wire:model="ai_sector" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Hizmet sektörü</option>@foreach ($sectorOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <input wire:model="ai_location_context" type="text" placeholder="Bölge bağlamı (isteğe bağlı)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <input wire:model="ai_candidate_count" type="number" min="5" max="50" aria-label="Aday sayısı" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="queueAiGeneration">AI ile sorgu üret</span>
                <span wire:loading wire:target="queueAiGeneration">Kuyruğa alınıyor…</span>
            </button>
        </form>
        @error('ai_service_id') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-gray-50 px-4 py-3 text-sm dark:bg-white/[0.03]">
            <div>
                <span class="font-medium text-gray-800 dark:text-gray-200">Mevcut sorguları sınıflandır:</span>
                <span class="text-gray-500">Aşağıdaki tablodan sorguları seçin; kaynak kayıtlar değişmeden öneri oluşur.</span>
            </div>
            <button wire:click="queueAiClassification" wire:loading.attr="disabled" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold text-violet-700 ring-1 ring-inset ring-violet-200 hover:bg-violet-50 disabled:opacity-50 dark:text-violet-300 dark:ring-violet-500/30">
                Seçilen {{ count($selectedQueryIds) }} sorguyu AI ile sınıflandır
            </button>
        </div>
        @error('selectedQueryIds') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

        @if ($aiRun)
            <div class="mt-5 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-white/[0.03]">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="font-semibold text-gray-800 dark:text-gray-200">Çalışma #{{ $aiRun->id }}</span>
                        <span class="rounded px-2 py-0.5 {{ $aiRun->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($aiRun->status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $aiRun->status }}</span>
                        <span class="text-gray-500">{{ $aiRun->operation_type === 'generate' ? 'Sorgu üretimi' : 'Sorgu sınıflandırma' }}</span>
                        @if ($aiRun->service)<span class="text-gray-500">· {{ $aiRun->service->primaryName?->raw_label }}</span>@endif
                        @if ($aiRun->model)<span class="text-gray-400">· {{ $aiRun->provider }}/{{ $aiRun->model }}</span>@endif
                    </div>
                    <div class="flex gap-3 text-xs text-gray-500">
                        <span>{{ $aiRun->pending_candidates }} bekleyen</span>
                        <span>{{ $aiRun->approved_candidates }} onaylı</span>
                        <span>{{ $aiRun->rejected_candidates }} reddedilmiş</span>
                    </div>
                </div>

                @if ($aiRun->status === 'failed')
                    <div class="border-b border-red-100 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $aiRun->error_summary ?: 'AI çalışması tamamlanamadı.' }}</div>
                @elseif ($aiRun->abstained && $aiRun->candidates->isEmpty())
                    <div class="border-b border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">AI çekimser kaldı: {{ $aiRun->abstention_reason ?: 'Yeterli bağlam bulunamadı.' }}</div>
                @elseif (in_array($aiRun->status, ['queued', 'running'], true))
                    <div class="border-b border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">Çalışma {{ $aiRun->status === 'queued' ? 'kuyrukta bekliyor' : 'devam ediyor' }}; ekran otomatik yenilenir.</div>
                @endif

                @if ($aiRun->candidates->isNotEmpty())
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                        <button wire:click="selectPendingAiCandidates" type="button" class="text-xs font-semibold text-violet-700 dark:text-violet-300">Onaylanabilir bekleyenlerin tümünü seç</button>
                        <div class="flex gap-2">
                            <button wire:click="reviewAiCandidates('reject')" wire:loading.attr="disabled" type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 disabled:opacity-50">Seçilenleri reddet</button>
                            <button wire:click="reviewAiCandidates('approve')" wire:loading.attr="disabled" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Düzenlemelerle onayla</button>
                        </div>
                    </div>
                    @error('selectedAiCandidateIds') <p class="px-4 pt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('candidateEdits') <p class="px-4 pt-2 text-xs text-red-600">{{ $message }}</p> @enderror

                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($aiRun->candidates->sortBy('id') as $candidate)
                            <article wire:key="ai-candidate-{{ $candidate->id }}" class="grid gap-3 px-4 py-4 lg:grid-cols-[auto_1.4fr_1fr_0.8fr_auto] {{ $candidate->status !== 'pending' ? 'bg-gray-50/70 dark:bg-white/[0.02]' : '' }}">
                                <div class="pt-2">
                                    @if ($candidate->status === 'pending')
                                        <input wire:model="selectedAiCandidateIds" value="{{ $candidate->id }}" type="checkbox" class="rounded border-gray-300 text-violet-600" />
                                    @else
                                        <span class="rounded px-1.5 py-0.5 text-[10px] {{ $candidate->status === 'approved' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $candidate->status }}</span>
                                    @endif
                                </div>
                                <div class="space-y-2">
                                    @if ($aiRun->operation_type === 'generate' && $candidate->status === 'pending')
                                        <input wire:model.blur="candidateEdits.{{ $candidate->id }}.proposed_text" type="text" class="w-full rounded-lg border-gray-300 text-sm font-medium dark:border-gray-700 dark:bg-gray-950" />
                                    @else
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $candidate->proposed_text }}</p>
                                    @endif
                                    <p class="text-xs text-gray-500">{{ $candidate->rationale ?: 'Gerekçe belirtilmedi.' }}</p>
                                    @if ($candidate->abstained)<p class="text-xs font-medium text-amber-700">Çekimser: {{ $candidate->abstention_reason ?: 'Belirsiz sonuç' }}</p>@endif
                                </div>
                                <div class="space-y-2">
                                    <input wire:model.blur="candidateEdits.{{ $candidate->id }}.demand_family" @disabled($candidate->status !== 'pending') type="text" placeholder="Talep ailesi" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                    <input wire:model.blur="candidateEdits.{{ $candidate->id }}.service_alias" @disabled($candidate->status !== 'pending') type="text" placeholder="Hizmet alias önerisi" class="w-full rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                    <div class="flex flex-wrap gap-1 text-[10px] text-gray-500">
                                        @if($candidate->search_intent)<span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">{{ $candidate->search_intent }}</span>@endif
                                        @if($candidate->decision_stage)<span class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">{{ $candidate->decision_stage }}</span>@endif
                                        @if($candidate->is_branded_suspected)<span class="rounded bg-red-50 px-1.5 py-0.5 text-red-700">marka/lisans şüphesi</span>@endif
                                    </div>
                                </div>
                                <div class="space-y-1 text-xs text-gray-500">
                                    <p><span class="text-gray-400">SERP grubu:</span> {{ $candidate->serp_intent_group ?: '—' }}</p>
                                    <p><span class="text-gray-400">İçerik kümesi:</span> {{ $candidate->content_target_cluster ?: '—' }}</p>
                                    <p><span class="text-gray-400">Lokasyon:</span> {{ $candidate->location_scope }}{{ $candidate->location_value ? ' · '.$candidate->location_value : '' }}</p>
                                </div>
                                <div class="flex items-start gap-2 lg:flex-col lg:items-end">
                                    <span class="rounded-full bg-violet-50 px-2 py-1 text-[10px] font-semibold text-violet-700">{{ $candidate->confidence !== null ? '%'.$candidate->confidence : 'güven yok' }}</span>
                                    @if ($candidate->status === 'pending')
                                        <button wire:click="reviewAiCandidate({{ $candidate->id }}, 'reject')" type="button" class="text-[11px] font-medium text-red-600">Reddet</button>
                                        @unless ($candidate->abstained)<button wire:click="reviewAiCandidate({{ $candidate->id }}, 'approve')" type="button" class="text-[11px] font-medium text-emerald-600">Onayla</button>@endunless
                                    @endif
                                </div>
                                @if ($candidate->status === 'pending')
                                    <details class="lg:col-span-3 lg:col-start-2">
                                        <summary class="cursor-pointer text-[11px] font-medium text-violet-700 dark:text-violet-300">Tüm sınıflandırma alanlarını düzenle</summary>
                                        <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                            <input wire:model.blur="candidateEdits.{{ $candidate->id }}.search_intent" type="text" placeholder="Arama niyeti" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model.blur="candidateEdits.{{ $candidate->id }}.decision_stage" type="text" placeholder="Karar aşaması" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model.blur="candidateEdits.{{ $candidate->id }}.serp_intent_group" type="text" placeholder="SERP niyet grubu" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model.blur="candidateEdits.{{ $candidate->id }}.content_target_cluster" type="text" placeholder="İçerik hedef kümesi" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <select wire:model="candidateEdits.{{ $candidate->id }}.location_scope" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950"><option value="none">Lokasyon yok</option><option value="country">Ülke</option><option value="city">Şehir</option><option value="district">İlçe</option><option value="pattern">{location} kalıbı</option></select>
                                            <input wire:model.blur="candidateEdits.{{ $candidate->id }}.location_value" type="text" placeholder="Lokasyon / {location}" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <input wire:model.blur="candidateEdits.{{ $candidate->id }}.user_problem" type="text" placeholder="Kullanıcı problemi" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950" />
                                            <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-300"><input wire:model="candidateEdits.{{ $candidate->id }}.is_branded_suspected" type="checkbox" class="rounded border-gray-300 text-red-500" /> Marka/lisans şüphesi</label>
                                        </div>
                                    </details>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif

                <div class="border-t border-gray-100 px-4 py-2 text-[10px] text-gray-400 dark:border-gray-800">
                    Agent {{ $aiRun->agent_signature }} · skill {{ implode(', ', $aiRun->skill_signatures ?? []) }} · girdi {{ substr($aiRun->input_fingerprint, 0, 12) }}…
                </div>
            </div>
        @endif
    </section>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 border-b border-gray-100 p-4 md:grid-cols-[1fr_1fr_1fr_1fr_auto] dark:border-gray-800">
            <input wire:model.live.debounce.350ms="search" type="search" placeholder="Sorgu veya talep ailesi ara" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" />
            <select wire:model.live="service" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm hizmetler</option>@foreach ($serviceOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="source" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Tüm kaynaklar</option>@foreach ($sourceOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="active">Etkin</option><option value="candidate">Aday</option><option value="excluded">Hariç</option><option value="archived">Arşiv</option><option value="all">Tümü</option></select>
            <div class="flex items-center whitespace-nowrap text-xs text-gray-500">AI sınıflama için {{ count($selectedQueryIds) }} seçili</div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-4 py-3"></th><th class="px-4 py-3">Sorgu</th><th class="px-4 py-3">Hizmet / aile</th><th class="px-4 py-3">Pazar</th><th class="px-4 py-3 text-right">Gösterim</th><th class="px-4 py-3 text-right">Tıklama</th><th class="px-4 py-3 text-right">Dönüşüm</th><th class="px-4 py-3 text-right">Hacim</th><th class="px-4 py-3">Kaynak</th><th class="px-4 py-3"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($queries as $query)
                        <tr class="align-top hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                            <td class="px-4 py-3"><input wire:model="selectedQueryIds" value="{{ $query->id }}" type="checkbox" aria-label="{{ $query->canonical_text }} sorgusunu seç" class="rounded border-gray-300 text-violet-600" /></td>
                            <td class="max-w-sm px-4 py-3"><div class="font-medium text-gray-900 dark:text-white">{{ $query->canonical_text }}</div><div class="mt-1 flex flex-wrap gap-1">@if($query->is_branded)<span class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-700">markalı</span>@endif<span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 dark:bg-gray-800">{{ $query->status }}</span></div></td>
                            <td class="px-4 py-3"><div class="text-gray-700 dark:text-gray-300">{{ $query->services->map(fn($service) => $service->primaryName?->raw_label)->filter()->implode(' · ') ?: 'Atanmamış' }}</div><div class="mt-1 text-gray-400">{{ $query->demand_family ?: 'Talep ailesi bekliyor' }}</div>@if($query->search_intent)<div class="mt-1 text-[10px] text-violet-600">{{ $query->search_intent }}{{ $query->decision_stage ? ' · '.$query->decision_stage : '' }}</div>@endif</td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-300">{{ $query->market_code ?: '—' }} · {{ $query->language_code ?: '—' }}@if($query->location_value)<div class="text-gray-400">{{ $query->location_value }}</div>@endif</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_impressions !== null ? number_format((float)$query->source_records_sum_impressions, 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_clicks !== null ? number_format((float)$query->source_records_sum_clicks, 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_conversions !== null ? number_format((float)$query->source_records_sum_conversions, 1, ',', '.') : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $query->source_records_sum_search_volume !== null ? number_format((float)$query->source_records_sum_search_volume, 0, ',', '.') : '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3"><div>{{ $query->source_records_count }} kayıt</div><div class="mt-1 text-gray-400">{{ $query->source_records_max_observed_at ? \Carbon\Carbon::parse($query->source_records_max_observed_at)->diffForHumans() : '—' }}</div></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">@if($query->status === 'active')<button wire:click="setQueryStatus({{ $query->id }}, 'excluded')" class="text-amber-600">Hariç tut</button>@else<button wire:click="setQueryStatus({{ $query->id }}, 'active')" class="text-brand-600">Etkinleştir</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="px-4 py-10 text-center text-sm text-gray-500">Bu filtrelerle eşleşen sorgu yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-400 dark:border-gray-800">En fazla son 300 eşleşme gösterilir. Kaynak kayıtları korunur; metrik olmayan sorgular sıfır değil “—” görünür.</div>
    </section>
</div>
