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
<div class="space-y-5 dark:text-gray-200" data-improvements>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.settings') }}" wire:navigate class="text-xs text-gray-500">← Ayarlar</a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Geliştirme havuzu</h1>
            <p class="mt-1 text-xs text-gray-500">
                Claude her sabah sistemi tarar ve doğruladığı sorunları buraya önerir. Onayladıklarını hafta içi 10:37, 14:37 ve 18:37 turlarında koda yazar ve dala gönderir; canlı sisteme kendisi dokunmaz. @if($autoDeploy !== null)Sunucu dala 15 dakikada bir bakar; testler geçen yeni kod kendiliğinden canlıya çıkar (otomatik deploy).@else Değişiklik sizin deploy'unuzla (git pull + deploy.sh) canlıya çıkar.@endif Canlıya çıkan değişiklik kendiliğinden “Kontrol”e geçer, Claude canlıda doğrulayıp kapatır.
                · <a href="{{ route('operator.settings.releases') }}" wire:navigate class="link-neutral">Canlı sürüm {{ $release['sha'] ? substr($release['sha'], 0, 8) : 'bilinmiyor' }} · Sürümler</a>
                · Sayfa taraması: {{ $screens['total'] }} ekran @if($screens['failed'] > 0), <span class="font-semibold text-error-600">{{ $screens['failed'] }} hatalı</span>@endif @if($screens['checked_at']) · {{ \Illuminate\Support\Carbon::parse($screens['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}@endif
            </p>
        </div>
        <x-ta.button variant="outline" size="sm" wire:click="$toggle('writing')">Değişiklik iste</x-ta.button>
    </header>

    @if($autoDeploy !== null)
        <p @class(['rounded-lg p-3 text-sm', 'bg-error-50 text-error-800 dark:bg-error-950 dark:text-error-200' => $autoDeploy['problem'], 'bg-gray-50 text-gray-700 dark:bg-gray-900 dark:text-gray-300' => ! $autoDeploy['problem']]) data-auto-deploy="{{ $autoDeploy['state'] }}">
            <span class="font-semibold">Otomatik deploy: {{ $autoDeploy['label'] }}</span>
            @if($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle') · {{ $autoDeploy['message'] }}@endif
            @if($autoDeploy['checked_at']) <span class="text-xs opacity-70">· son kontrol {{ \Illuminate\Support\Carbon::parse($autoDeploy['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>@endif
        </p>
    @endif

    @if($message !== '')<p role="status" class="rounded-lg bg-gray-50 p-3 text-sm text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $message }}</p>@endif

    @if($writing)
        <section class="space-y-2 surface p-4" data-new-request>
            <div class="flex flex-wrap gap-2">
                <select wire:model="newKind" aria-label="Tür" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach(\App\Models\SystemChange::KIND_LABELS as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                </select>
                <input type="text" wire:model="newTitle" placeholder="Ne değişsin?" aria-label="Başlık" class="min-w-0 flex-1 sm:min-w-60 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>
            <textarea wire:model="newDetail" rows="3" placeholder="Ayrıntı: hangi ekran, ne görüyorsun, ne olmalı" aria-label="Ayrıntı" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
            @error('newTitle')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            @error('newDetail')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            <x-ta.button size="xs" wire:click="saveRequest">Ekle (onaylı)</x-ta.button>
        </section>
    @endif

    <nav class="flex gap-5 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Durum">
        @foreach(\App\Livewire\Operator\Settings\ImprovementsPage::TABS as $code => [$label])
            <button type="button" wire:click="setTab('{{ $code }}')" @class(['-mb-px h-11 shrink-0 border-b-2 whitespace-nowrap', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $tab === $code, 'border-transparent text-gray-500 hover:text-gray-800' => $tab !== $code])>
                {{ $label }}@if($tabCounts[$code] > 0) <span @class(['ml-1 rounded-full px-1.5 text-xs', 'bg-warning-100 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300' => in_array($code, ['onay', 'deploy'], true), 'bg-gray-100 text-gray-600 dark:bg-gray-800' => ! in_array($code, ['onay', 'deploy'], true)])>{{ $tabCounts[$code] }}</span>@endif
            </button>
        @endforeach
    </nav>

    @if($tab === 'deploy')
        @forelse($groups as $commit => $items)
            <section wire:key="deploy-{{ $commit }}" class="space-y-3 surface p-4" data-deploy-group>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm"><span class="font-semibold">Commit {{ substr((string) $commit, 0, 8) }}</span> <span class="text-gray-500">· {{ $items->first()->branch }} · {{ $items->count() }} değişiklik</span></p>
                    <button type="button" wire:click="deployed('{{ $commit }}')" wire:confirm="Bu commit deploy edildi mi?" class="h-7 w-full rounded-lg bg-brand-500 px-2.5 text-xs font-semibold text-white hover:bg-brand-600 sm:w-auto">Deploy tamamlandı</button>
                </div>
                <ul class="space-y-1 text-sm">
                    @foreach($items as $change)<li>· {{ $change->title }}@if($change->work_note)<span class="text-xs text-gray-500"> · {{ $change->work_note }}</span>@endif</li>@endforeach
                </ul>
                <div x-data="{ text: @js($items->first()->deploy_commands) }" class="rounded-lg bg-gray-950 p-3">
                    <pre class="overflow-x-auto whitespace-pre text-xs text-gray-100">{{ $items->first()->deploy_commands }}</pre>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="mt-2 text-xs font-semibold text-success-300 hover:underline">Kopyala</button>
                </div>
            </section>
        @empty
            <p class="surface p-6 text-center text-sm text-gray-500">Deploy bekleyen değişiklik yok.</p>
        @endforelse
    @else
        @if($kinds !== [])
            <div class="flex flex-wrap items-center gap-1.5 text-xs" data-kind-filter>
                <button type="button" wire:click="setKind('')" @class(['rounded-full px-3 py-1 font-medium ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $kind === '', 'bg-white text-gray-600 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => $kind !== ''])>Hepsi ({{ array_sum($kinds) }})</button>
                @foreach(\App\Models\SystemChange::KIND_LABELS as $code => $label)
                    @if(($kinds[$code] ?? 0) > 0)
                        <button type="button" wire:click="setKind('{{ $code }}')" @class(['rounded-full px-3 py-1 font-medium ring-1 ring-inset', 'bg-gray-900 text-white ring-gray-900 dark:bg-white dark:text-gray-900' => $kind === $code, 'bg-white text-gray-600 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => $kind !== $code])>{{ $label }} ({{ $kinds[$code] }})</button>
                    @endif
                @endforeach
            </div>
        @endif

        @if($tab === 'onay' && $changes->isNotEmpty())
            <section class="sticky top-2 z-20 flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 text-sm shadow-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-bulk>
                <span class="font-semibold">{{ count($selected) }} seçili</span>
                <button type="button" wire:click="pickAll" class="link-neutral text-xs">Görünenlerin hepsini seç</button>
                @if($selected !== [])<button type="button" wire:click="pickAll(false)" class="text-xs text-gray-500 hover:underline">Temizle</button>@endif
                <span class="ml-auto flex gap-2">
                    <button type="button" wire:click="approveSelected" @disabled($selected === []) class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50">Seçilenleri onayla</button>
                    <button type="button" wire:click="rejectSelected" wire:confirm="Seçilen öneriler reddedilsin mi? Aynı bulgu tekrar önerilmez." @disabled($selected === []) class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-gray-300 disabled:opacity-50 dark:ring-gray-700">Seçilenleri reddet</button>
                </span>
            </section>
        @endif

        <div class="space-y-3">
            @forelse($changes as $change)
                @php
                    $part = $change->sections();
                    $lead = \App\Models\SystemChange::lead($part['why'] !== '' ? $part['why'] : $part['problem']);
                    $waiting = in_array($change->status, ['approved', 'in_progress'], true) && $change->decided_at !== null ? (int) $change->decided_at->diffInDays(now(), true) : null;
                @endphp
                <article wire:key="change-{{ $change->id }}" x-data="{ open: false }" class="surface p-4 text-sm" data-change="{{ $change->id }}">
                    <div class="flex items-start gap-3">
                        @if($change->status === 'proposed')
                            <input type="checkbox" wire:model.live="selected" value="{{ $change->id }}" aria-label="Seç" class="mt-1 rounded border-gray-300 text-brand-600">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-800">{{ \App\Models\SystemChange::KIND_LABELS[$change->kind] ?? $change->kind }}</span>
                                @if($change->priority === 1)<span class="rounded-full bg-error-50 px-2 py-0.5 text-xs text-error-700 dark:bg-error-500/10 dark:text-error-300">Acil</span>@elseif($change->priority === 3)<span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800">Düşük</span>@endif
                                @if($tab !== 'onay')<span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $change->status === 'verified', 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300' => in_array($change->status, ['failed', 'rejected'], true), 'bg-warning-50 text-warning-800 dark:bg-warning-500/10 dark:text-warning-300' => ! in_array($change->status, ['verified', 'failed', 'rejected'], true)])>{{ \App\Models\SystemChange::STATUS_LABELS[$change->status] ?? $change->status }}</span>@endif
                                @if($waiting !== null && $waiting >= 1)<span class="text-xs text-warning-700 dark:text-warning-300">{{ $waiting }} gündür sırada</span>@endif
                                <span class="ml-auto text-xs text-gray-400">#{{ $change->id }} · {{ $change->source === 'operator' ? 'Senin isteğin' : 'Claude' }} · {{ $change->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                            </div>
                            @php
                                $plainTitle = $plain($change->title) ?: $change->title;
                                $plainLead = $plain($lead);
                                $plainNote = $plain($change->work_note);
                            @endphp
                            <h2 class="mt-2 break-words font-semibold text-gray-900 dark:text-white" @if ($plainTitle !== $change->title) title="{{ $change->title }}" @endif>{{ $plainTitle }}</h2>
                            @if($plainLead !== '')<p class="mt-1 break-words text-gray-700 dark:text-gray-300"><span class="font-medium text-gray-500">{{ $part['why'] !== '' ? 'Neden önemli:' : 'Sorun:' }}</span> {{ $plainLead }}</p>@endif
                            @if($plainNote !== '')<p class="mt-2 break-words rounded-lg bg-gray-50 p-2 text-xs dark:bg-gray-800"><span class="font-semibold">Claude:</span> {{ $plainNote }}</p>@endif
                            @if($change->verify_note)<p @class(['mt-2 rounded-lg p-2 text-xs', 'bg-success-50 text-success-800 dark:bg-success-500/10 dark:text-success-300' => $change->status === 'verified', 'bg-error-50 text-error-800 dark:bg-error-500/10 dark:text-error-300' => $change->status !== 'verified'])><span class="font-semibold">Kontrol:</span> {{ $change->verify_note }}</p>@endif
                            @if($change->operator_note)<p class="mt-2 text-xs text-gray-500">Notun: {{ $change->operator_note }}</p>@endif
                            <button type="button" x-on:click="open = ! open" class="link-neutral mt-2 text-xs" x-text="open ? 'Ayrıntıyı gizle' : 'Teknik ayrıntı ve kanıt'">Teknik ayrıntı ve kanıt</button>
                            <div x-show="open" x-cloak class="mt-2 min-w-0 space-y-2 break-words rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800/60 dark:text-gray-300" data-detail>
                                @if($plainTitle !== $change->title)<div><p class="font-semibold text-gray-500">Başlık</p><p>{{ $change->title }}</p></div>@endif
                                @if($change->work_note && $plainNote !== $change->work_note)<div><p class="font-semibold text-gray-500">Claude notu</p><p class="whitespace-pre-line">{{ $change->work_note }}</p></div>@endif
                                @foreach(['problem' => 'Sorun', 'why' => 'Neden önemli', 'fix' => 'Önerilen düzeltme', 'test' => 'Test'] as $key => $label)
                                    @if($part[$key] !== '')<div><p class="font-semibold text-gray-500">{{ $label }}</p><p class="whitespace-pre-line">{{ $part[$key] }}</p></div>@endif
                                @endforeach
                                @if($change->evidence)
                                    <div><p class="font-semibold text-gray-500">Kanıt</p><ul class="space-y-0.5">@foreach($change->evidence as $item)<li class="break-all">· {{ $item }}</li>@endforeach</ul></div>
                                @endif
                                @if($change->commit_sha)<p class="text-gray-400">Commit {{ substr($change->commit_sha, 0, 8) }}</p>@endif
                            </div>
                            @if(in_array($change->status, ['proposed', 'failed'], true))
                                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                                    <input type="text" wire:model="notes.{{ $change->id }}" placeholder="Not (isteğe bağlı): nasıl yapılsın" aria-label="Not" class="min-w-0 flex-1 basis-full sm:basis-auto sm:min-w-48 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                    <button type="button" wire:click="approve({{ $change->id }})" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-white/5">{{ $change->status === 'failed' ? 'Tekrar dene' : 'Onayla' }}</button>
                                    <button type="button" wire:click="reject({{ $change->id }})" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-800 dark:hover:bg-white/5">Reddet</button>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <p class="surface p-6 text-center text-sm text-gray-500">Bu bölümde değişiklik yok.</p>
            @endforelse
        </div>
    @endif
</div>
