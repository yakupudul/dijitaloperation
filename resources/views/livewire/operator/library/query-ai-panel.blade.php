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

