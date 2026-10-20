@php
    // Card text in plain Turkish (2026-10-19): code identifiers (gbp.post_queue, slots(), ClaudeApiWindow::end()) are kept
    // only inside the collapsed "Teknik ayrıntı" part.
    $plain = function (?string $text): string {
        $text = (string) $text;
        $patterns = [
            '/`[^`]*`/u',
            '/\b[\w\\\\]+::\w+(\(\))?/u',
            '/\b[A-Za-z_][\w.]*\(\)/u',
            '/\b[a-z0-9]+(?:\.[a-z0-9_]+)*_[a-z0-9_.]*[a-z0-9]\b/u',
            '/[\w.-]*(?:\/[\w.-]+)*\.(?:php|js|ts|css|json)\b/u',
            '/\b(?:[A-Z][a-z0-9]+){3,}\b/u',
            '/\b(?:[A-Z][a-z0-9]+)+(?:Job|Service|Command|Reader|Writer|Controller|Component|Window|Tab|Page)\b/u',
        ];
        $clean = preg_replace($patterns, '', $text) ?? $text;
        $clean = preg_replace(['/\(\s*[,;·]?\s*\)/u', '/\s+([,.;:·])/u', '/([,;·])\s*\1+/u', '/\s{2,}/u'], ['', '$1', '$1', ' '], $clean) ?? $clean;

        return trim($clean, " \t\n\r\0\x0B,;·:");
    };
@endphp
<div class="space-y-10 dark:text-gray-200" data-improvements>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title" title="Claude her sabah sistemi tarar ve doğruladığı sorunları buraya önerir. Onayladıklarını hafta içi 10:37, 14:37 ve 18:37 turlarında koda yazar ve dala gönderir; canlı sisteme kendisi dokunmaz. @if($autoDeploy !== null)Sunucu dala 15 dakikada bir bakar; testler geçen yeni kod kendiliğinden canlıya çıkar (otomatik deploy).@else Değişiklik sizin deploy'unuzla (git pull + deploy.sh) canlıya çıkar.@endif Canlıya çıkan değişiklik kendiliğinden “Kontrol”e geçer, Claude canlıda doğrulayıp kapatır.">Geliştirme havuzu</h1>
            <p class="page-sub">
                <a href="{{ route('operator.settings.releases') }}" wire:navigate class="link-neutral font-normal!">Canlı sürüm {{ $release['sha'] ? substr($release['sha'], 0, 8) : 'bilinmiyor' }}</a>,
                sayfa taraması: {{ $screens['total'] }} ekran @if($screens['failed'] > 0), <span class="font-medium text-error-600 dark:text-error-400">{{ $screens['failed'] }} hatalı</span>@endif @if($screens['checked_at']), {{ \Illuminate\Support\Carbon::parse($screens['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}@endif
            </p>
        </div>
        <button type="button" wire:click="$toggle('writing')" class="btn w-full sm:w-auto">Değişiklik iste</button>
    </header>

    @if($autoDeploy !== null)
        <div @class(['-mt-4', 'card' => ! $autoDeploy['problem'], 'rounded-3xl border soft-red' => $autoDeploy['problem']]) data-auto-deploy="{{ $autoDeploy['state'] }}">
            <div class="card-row items-start">
                <span @class(['row-icon' => ! $autoDeploy['problem'], 'soft-icon text-error-600 dark:text-error-400' => $autoDeploy['problem']]) aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p @class(['text-sm font-medium', 'text-error-700 dark:text-error-300' => $autoDeploy['problem'], 'text-gray-900 dark:text-white' => ! $autoDeploy['problem']])>Otomatik deploy: {{ $autoDeploy['label'] }}</p>
                    @if(($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle') || $autoDeploy['checked_at'])
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">@if($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle'){{ $autoDeploy['message'] }} @endif @if($autoDeploy['checked_at'])Son kontrol {{ \Illuminate\Support\Carbon::parse($autoDeploy['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}.@endif</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if($message !== '')<p role="status" class="tone-info -mt-4 rounded-lg px-3 py-2 text-sm">{{ $message }}</p>@endif

    @if($writing)
        <section class="card -mt-4" data-new-request>
            <div class="space-y-3 p-4">
                <div class="flex flex-wrap gap-2">
                    <select wire:model="newKind" aria-label="Tür" class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm sm:w-auto dark:border-gray-700 dark:bg-transparent">
                        @foreach(\App\Models\SystemChange::KIND_LABELS as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="newTitle" placeholder="Ne değişsin?" aria-label="Başlık" class="h-9 min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 text-sm sm:min-w-60 dark:border-gray-700 dark:bg-transparent">
                </div>
                <textarea wire:model="newDetail" rows="3" placeholder="Ayrıntı: hangi ekran, ne görüyorsun, ne olmalı" aria-label="Ayrıntı" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-transparent"></textarea>
                @error('newTitle')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
                @error('newDetail')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex justify-end border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                <button type="button" wire:click="saveRequest" class="btn-primary w-full sm:w-auto">Ekle (onaylı)</button>
            </div>
        </section>
    @endif

    {{-- Canlı panel (2026-10-20): the status lanes are pill tabs (seg) like the Onarım masası lanes. --}}
    <nav class="no-scrollbar flex gap-1.5 overflow-x-auto" aria-label="Durum">
        @foreach(\App\Livewire\Operator\Settings\ImprovementsPage::TABS as $code => [$label])
            <button type="button" wire:click="setTab('{{ $code }}')" @class(['seg', 'seg-active' => $tab === $code])>
                {{ $label }}@if($tabCounts[$code] > 0) <span @class(['text-xs font-normal tabular-nums', 'text-accent' => $tab === $code, 'text-warning-700 dark:text-warning-300' => $tab !== $code && in_array($code, ['onay', 'deploy'], true), 'text-gray-400 dark:text-gray-500' => $tab !== $code && ! in_array($code, ['onay', 'deploy'], true)])>{{ $tabCounts[$code] }}</span>@endif
            </button>
        @endforeach
    </nav>

    @if($tab === 'deploy')
        <div class="space-y-4">
            @forelse($groups as $commit => $items)
                <section wire:key="deploy-{{ $commit }}" class="card" data-deploy-group>
                    <div class="card-row flex-wrap sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Commit <span class="font-mono">{{ substr((string) $commit, 0, 8) }}</span></p>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $items->first()->branch }}, {{ $items->count() }} değişiklik</p>
                        </div>
                        <button type="button" wire:click="deployed('{{ $commit }}')" wire:confirm="Bu commit deploy edildi mi?" class="btn-dark w-full sm:w-auto">Deploy tamamlandı</button>
                    </div>
                    <ul class="space-y-1 border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-800">
                        @foreach($items as $change)<li class="flex gap-2"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-gray-400" aria-hidden="true"></span><span>{{ $change->title }}@if($change->work_note)<span class="text-xs text-gray-500">, {{ $change->work_note }}</span>@endif</span></li>@endforeach
                    </ul>
                    <div x-data="{ text: @js($items->first()->deploy_commands) }" class="border-t border-gray-200 bg-gray-950 p-3 dark:border-gray-800">
                        <pre class="overflow-x-auto whitespace-pre text-xs text-gray-100">{{ $items->first()->deploy_commands }}</pre>
                        <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="mt-2 text-xs font-medium text-gray-300 hover:text-white hover:underline">Kopyala</button>
                    </div>
                </section>
            @empty
                <div class="card"><x-ta.empty-state size="sm" title="Deploy bekleyen değişiklik yok." /></div>
            @endforelse
        </div>
    @else
        @if($kinds !== [])
            <div class="-mt-6 flex flex-wrap items-center gap-1.5" data-kind-filter>
                <button type="button" wire:click="setKind('')" @class(['chip h-7! gap-1! px-2.5! font-medium! border', 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' => $kind === '', 'border-gray-200 bg-white text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-300' => $kind !== ''])>Hepsi <span class="font-normal opacity-70">{{ array_sum($kinds) }}</span></button>
                @foreach(\App\Models\SystemChange::KIND_LABELS as $code => $label)
                    @if(($kinds[$code] ?? 0) > 0)
                        <button type="button" wire:click="setKind('{{ $code }}')" @class(['chip h-7! gap-1! px-2.5! font-medium! border', 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' => $kind === $code, 'border-gray-200 bg-white text-gray-600 hover:text-gray-900 dark:border-gray-700 dark:bg-white/[0.04] dark:text-gray-300' => $kind !== $code])>{{ $label }} <span class="font-normal opacity-70">{{ $kinds[$code] }}</span></button>
                    @endif
                @endforeach
            </div>
        @endif

        @if($tab === 'onay' && $changes->isNotEmpty())
            <section class="card sticky top-2 z-20 -mt-6 flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm" data-bulk>
                <span class="font-medium text-gray-900 dark:text-white">{{ count($selected) }} seçili</span>
                <button type="button" wire:click="pickAll" class="link-neutral text-[13px]">Görünenlerin hepsini seç</button>
                @if($selected !== [])<button type="button" wire:click="pickAll(false)" class="text-[13px] text-gray-500 hover:underline">Temizle</button>@endif
                <span class="flex w-full gap-2 sm:ml-auto sm:w-auto">
                    <button type="button" wire:click="rejectSelected" wire:confirm="Seçilen öneriler reddedilsin mi? Aynı bulgu tekrar önerilmez." @disabled($selected === []) class="btn flex-1 sm:flex-none">Seçilenleri reddet</button>
                    <button type="button" wire:click="approveSelected" @disabled($selected === []) class="btn-primary flex-1 sm:flex-none">Seçilenleri onayla</button>
                </span>
            </section>
        @endif

        <div @class(['card', '-mt-6' => $kinds !== [] || ($tab === 'onay' && $changes->isNotEmpty())])>
            @forelse($changes as $change)
                @php
                    $part = $change->sections();
                    $lead = \App\Models\SystemChange::lead($part['why'] !== '' ? $part['why'] : $part['problem']);
                    $waiting = in_array($change->status, ['approved', 'in_progress'], true) && $change->decided_at !== null ? (int) $change->decided_at->diffInDays(now(), true) : null;
                @endphp
                <article wire:key="change-{{ $change->id }}" x-data="{ open: false }" class="border-t border-gray-200 px-4 py-4 text-sm first:border-t-0 dark:border-gray-800" data-change="{{ $change->id }}">
                    <div class="flex items-start gap-3">
                        @if($change->status === 'proposed')
                            <input type="checkbox" wire:model.live="selected" value="{{ $change->id }}" aria-label="Seç" class="mt-1 h-4 w-4 rounded border-gray-300 text-gray-900">
                        @endif
                        <div class="min-w-0 flex-1">
                            @php
                                $plainTitle = $plain($change->title) ?: $change->title;
                                $plainLead = $plain($lead);
                                $plainNote = $plain($change->work_note);
                            @endphp
                            <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                <h2 class="min-w-0 flex-1 break-words text-sm font-medium text-gray-900 dark:text-white" @if ($plainTitle !== $change->title) title="{{ $change->title }}" @endif>{{ $plainTitle }}</h2>
                                <span class="w-full text-xs text-gray-500 sm:w-auto" title="#{{ $change->id }}">{{ $change->source === 'operator' ? 'Senin isteğin' : 'Claude' }}, {{ $change->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="text-xs text-gray-500">{{ \App\Models\SystemChange::KIND_LABELS[$change->kind] ?? $change->kind }}</span>
                                @if($change->priority === 1)<x-ta.pill tone="critical">Acil</x-ta.pill>@elseif($change->priority === 3)<x-ta.pill tone="info">Düşük</x-ta.pill>@endif
                                @if($tab !== 'onay')<x-ta.pill :tone="$change->status === 'verified' ? 'ok' : (in_array($change->status, ['failed', 'rejected'], true) ? 'critical' : 'look')">{{ \App\Models\SystemChange::STATUS_LABELS[$change->status] ?? $change->status }}</x-ta.pill>@endif
                                @if($waiting !== null && $waiting >= 1)<span class="text-xs text-warning-700 dark:text-warning-300">{{ $waiting }} gündür sırada</span>@endif
                            </div>
                            @if($plainLead !== '')<p class="mt-2 break-words text-[13px] text-gray-700 dark:text-gray-300"><span class="text-gray-500">{{ $part['why'] !== '' ? 'Neden önemli:' : 'Sorun:' }}</span> {{ $plainLead }}</p>@endif
                            @if($plainNote !== '')<p class="mt-1.5 break-words text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium">Claude:</span> {{ $plainNote }}</p>@endif
                            @if($change->verify_note)<p @class(['mt-1.5 text-[13px]', 'text-success-700 dark:text-success-300' => $change->status === 'verified', 'text-error-700 dark:text-error-300' => $change->status !== 'verified'])><span class="font-medium">Kontrol:</span> {{ $change->verify_note }}</p>@endif
                            @if($change->operator_note)<p class="mt-1.5 text-xs text-gray-500">Notun: {{ $change->operator_note }}</p>@endif
                            <button type="button" x-on:click="open = ! open" class="link-neutral mt-2 text-xs" x-text="open ? 'Ayrıntıyı gizle' : 'Teknik ayrıntı ve kanıt'">Teknik ayrıntı ve kanıt</button>
                            <div x-show="open" x-cloak class="mt-2 min-w-0 space-y-2 break-words rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300" data-detail>
                                @if($plainTitle !== $change->title)<div><p class="font-medium text-gray-500">Başlık</p><p>{{ $change->title }}</p></div>@endif
                                @if($change->work_note && $plainNote !== $change->work_note)<div><p class="font-medium text-gray-500">Claude notu</p><p class="whitespace-pre-line">{{ $change->work_note }}</p></div>@endif
                                @foreach(['problem' => 'Sorun', 'why' => 'Neden önemli', 'fix' => 'Önerilen düzeltme', 'test' => 'Test'] as $key => $label)
                                    @if($part[$key] !== '')<div><p class="font-medium text-gray-500">{{ $label }}</p><p class="whitespace-pre-line">{{ $part[$key] }}</p></div>@endif
                                @endforeach
                                @if($change->evidence)
                                    <div><p class="font-medium text-gray-500">Kanıt</p><ul class="list-disc space-y-0.5 pl-4">@foreach($change->evidence as $item)<li class="break-all">{{ $item }}</li>@endforeach</ul></div>
                                @endif
                                @if($change->commit_sha)<p class="text-gray-500">Commit {{ substr($change->commit_sha, 0, 8) }}</p>@endif
                            </div>
                            @if(in_array($change->status, ['proposed', 'failed'], true))
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="notes.{{ $change->id }}" placeholder="Not (isteğe bağlı): nasıl yapılsın" aria-label="Not" class="h-8 min-w-0 flex-1 basis-full rounded-lg border border-gray-200 bg-white px-3 text-[13px] sm:min-w-48 sm:basis-auto dark:border-gray-700 dark:bg-transparent">
                                    <button type="button" wire:click="reject({{ $change->id }})" class="btn-quiet">Reddet</button>
                                    <button type="button" wire:click="approve({{ $change->id }})" class="btn-dark">{{ $change->status === 'failed' ? 'Tekrar dene' : 'Onayla' }}</button>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <x-ta.empty-state size="sm" icon="none" title="Bu bölümde değişiklik yok." />
            @endforelse
        </div>
    @endif

    <p class="text-[13px]"><a href="{{ route('operator.settings') }}" wire:navigate class="link-neutral">Ayarlar</a></p>
</div>
