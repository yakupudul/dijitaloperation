<div class="space-y-5 dark:text-gray-200" data-improvements>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.settings') }}" wire:navigate class="text-xs text-gray-500">← Ayarlar</a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Geliştirme havuzu</h1>
            <p class="mt-1 text-xs text-gray-500">
                Claude her sabah sistemi tarar ve doğruladığı sorunları buraya önerir. Onayladıklarını hafta içi 10:37, 14:37 ve 18:37 turlarında kodlar; canlıya çıkan değişiklik kendiliğinden “Kontrol”e geçer, Claude canlıda doğrulayıp kapatır.
                · Canlı sürüm {{ $release['sha'] ? substr($release['sha'], 0, 8) : 'bilinmiyor' }}
                · Sayfa taraması: {{ $screens['total'] }} ekran @if($screens['failed'] > 0), <span class="font-semibold text-rose-600">{{ $screens['failed'] }} hatalı</span>@endif @if($screens['checked_at']) · {{ \Illuminate\Support\Carbon::parse($screens['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}@endif
            </p>
        </div>
        <button type="button" wire:click="$toggle('writing')" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white">Değişiklik iste</button>
    </header>

    @if($message !== '')<p role="status" class="rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950 dark:text-blue-200">{{ $message }}</p>@endif

    @if($writing)
        <section class="space-y-2 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-new-request>
            <div class="flex flex-wrap gap-2">
                <select wire:model="newKind" aria-label="Tür" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach(\App\Models\SystemChange::KIND_LABELS as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                </select>
                <input type="text" wire:model="newTitle" placeholder="Ne değişsin?" aria-label="Başlık" class="min-w-60 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
            </div>
            <textarea wire:model="newDetail" rows="3" placeholder="Ayrıntı: hangi ekran, ne görüyorsun, ne olmalı" aria-label="Ayrıntı" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"></textarea>
            @error('newTitle')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            @error('newDetail')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            <button type="button" wire:click="saveRequest" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white">Ekle (onaylı)</button>
        </section>
    @endif

    <nav class="flex gap-5 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Durum">
        @foreach(\App\Livewire\Operator\Settings\ImprovementsPage::TABS as $code => [$label])
            <button type="button" wire:click="setTab('{{ $code }}')" @class(['-mb-px h-11 shrink-0 border-b-2 whitespace-nowrap', 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white' => $tab === $code, 'border-transparent text-gray-500 hover:text-gray-800' => $tab !== $code])>
                {{ $label }}@if($tabCounts[$code] > 0) <span @class(['ml-1 rounded-full px-1.5 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => in_array($code, ['onay', 'deploy'], true), 'bg-gray-100 text-gray-600 dark:bg-gray-800' => ! in_array($code, ['onay', 'deploy'], true)])>{{ $tabCounts[$code] }}</span>@endif
            </button>
        @endforeach
    </nav>

    @if($tab === 'deploy')
        @forelse($groups as $commit => $items)
            <section wire:key="deploy-{{ $commit }}" class="space-y-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-deploy-group>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm"><span class="font-semibold">Commit {{ substr((string) $commit, 0, 8) }}</span> <span class="text-gray-500">· {{ $items->first()->branch }} · {{ $items->count() }} değişiklik</span></p>
                    <button type="button" wire:click="deployed('{{ $commit }}')" wire:confirm="Bu commit deploy edildi mi?" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Deploy tamamlandı</button>
                </div>
                <ul class="space-y-1 text-sm">
                    @foreach($items as $change)<li>· {{ $change->title }}@if($change->work_note)<span class="text-xs text-gray-500"> · {{ $change->work_note }}</span>@endif</li>@endforeach
                </ul>
                <div x-data="{ text: @js($items->first()->deploy_commands) }" class="rounded-lg bg-gray-950 p-3">
                    <pre class="overflow-x-auto whitespace-pre text-xs text-gray-100">{{ $items->first()->deploy_commands }}</pre>
                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="mt-2 text-xs font-semibold text-emerald-300 hover:underline">Kopyala</button>
                </div>
            </section>
        @empty
            <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Deploy bekleyen değişiklik yok.</p>
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
                <button type="button" wire:click="pickAll" class="text-xs font-medium text-brand-600 hover:underline">Görünenlerin hepsini seç</button>
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
                <article wire:key="change-{{ $change->id }}" x-data="{ open: false }" class="rounded-xl bg-white p-4 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-change="{{ $change->id }}">
                    <div class="flex items-start gap-3">
                        @if($change->status === 'proposed')
                            <input type="checkbox" wire:model.live="selected" value="{{ $change->id }}" aria-label="Seç" class="mt-1 rounded border-gray-300 text-brand-600">
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-800">{{ \App\Models\SystemChange::KIND_LABELS[$change->kind] ?? $change->kind }}</span>
                                @if($change->priority === 1)<span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Acil</span>@elseif($change->priority === 3)<span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800">Düşük</span>@endif
                                @if($tab !== 'onay')<span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $change->status === 'verified', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => in_array($change->status, ['failed', 'rejected'], true), 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => ! in_array($change->status, ['verified', 'failed', 'rejected'], true)])>{{ \App\Models\SystemChange::STATUS_LABELS[$change->status] ?? $change->status }}</span>@endif
                                @if($waiting !== null && $waiting >= 1)<span class="text-xs text-amber-700 dark:text-amber-300">{{ $waiting }} gündür sırada</span>@endif
                                <span class="ml-auto text-xs text-gray-400">#{{ $change->id }} · {{ $change->source === 'operator' ? 'Senin isteğin' : 'Claude' }} · {{ $change->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                            </div>
                            <h2 class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $change->title }}</h2>
                            @if($lead !== '')<p class="mt-1 text-gray-700 dark:text-gray-300"><span class="font-medium text-gray-500">{{ $part['why'] !== '' ? 'Neden önemli:' : 'Sorun:' }}</span> {{ $lead }}</p>@endif
                            @if($change->work_note)<p class="mt-2 rounded-lg bg-gray-50 p-2 text-xs dark:bg-gray-800"><span class="font-semibold">Claude:</span> {{ $change->work_note }}</p>@endif
                            @if($change->verify_note)<p @class(['mt-2 rounded-lg p-2 text-xs', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $change->status === 'verified', 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => $change->status !== 'verified'])><span class="font-semibold">Kontrol:</span> {{ $change->verify_note }}</p>@endif
                            @if($change->operator_note)<p class="mt-2 text-xs text-gray-500">Notun: {{ $change->operator_note }}</p>@endif
                            <button type="button" x-on:click="open = ! open" class="mt-2 text-xs font-medium text-brand-600 hover:underline" x-text="open ? 'Ayrıntıyı gizle' : 'Teknik ayrıntı ve kanıt'">Teknik ayrıntı ve kanıt</button>
                            <div x-show="open" x-cloak class="mt-2 space-y-2 rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800/60 dark:text-gray-300" data-detail>
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
                                    <input type="text" wire:model="notes.{{ $change->id }}" placeholder="Not (isteğe bağlı): nasıl yapılsın" aria-label="Not" class="min-w-48 flex-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                                    <button type="button" wire:click="approve({{ $change->id }})" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white">{{ $change->status === 'failed' ? 'Tekrar dene' : 'Onayla' }}</button>
                                    <button type="button" wire:click="reject({{ $change->id }})" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Reddet</button>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Bu bölümde değişiklik yok.</p>
            @endforelse
        </div>
    @endif
</div>
