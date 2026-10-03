<div class="space-y-5 dark:text-gray-200" data-improvements>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.settings') }}" wire:navigate class="text-xs text-gray-500">← Ayarlar</a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Geliştirme havuzu</h1>
            <p class="mt-1 text-xs text-gray-500">
                Claude sistemi tarar ve bulduklarını buraya ekler. Onayladıklarını kodlar, deploy kodunu buraya yazar. Sen deploy edip işaretleyince canlıda kontrol eder.
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
        <div class="space-y-3">
            @forelse($changes as $change)
                <article wire:key="change-{{ $change->id }}" class="rounded-xl bg-white p-4 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-change="{{ $change->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs dark:bg-gray-800">{{ \App\Models\SystemChange::KIND_LABELS[$change->kind] ?? $change->kind }}</span>
                        @if($change->priority === 1)<span class="rounded-full bg-rose-50 px-2 py-0.5 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Acil</span>@elseif($change->priority === 3)<span class="rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800">Düşük</span>@endif
                        <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $change->status === 'verified', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => in_array($change->status, ['failed', 'rejected'], true), 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => ! in_array($change->status, ['verified', 'failed', 'rejected'], true)])>{{ \App\Models\SystemChange::STATUS_LABELS[$change->status] ?? $change->status }}</span>
                        <span class="text-xs text-gray-400">#{{ $change->id }} · {{ $change->source === 'operator' ? 'Senin isteğin' : 'Claude' }} · {{ $change->created_at?->timezone('Europe/Istanbul')->format('d.m H:i') }}</span>
                    </div>
                    <h2 class="mt-2 font-semibold text-gray-900 dark:text-white">{{ $change->title }}</h2>
                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300">{{ $change->detail }}</p>
                    @if($change->evidence)
                        <ul class="mt-2 space-y-0.5 text-xs text-gray-500">@foreach($change->evidence as $item)<li class="break-all">· {{ $item }}</li>@endforeach</ul>
                    @endif
                    @if($change->work_note)<p class="mt-2 rounded-lg bg-gray-50 p-2 text-xs dark:bg-gray-800"><span class="font-semibold">Claude:</span> {{ $change->work_note }}</p>@endif
                    @if($change->verify_note)<p @class(['mt-2 rounded-lg p-2 text-xs', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $change->status === 'verified', 'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => $change->status !== 'verified'])><span class="font-semibold">Kontrol:</span> {{ $change->verify_note }}</p>@endif
                    @if($change->operator_note)<p class="mt-2 text-xs text-gray-500">Notun: {{ $change->operator_note }}</p>@endif
                    @if($change->commit_sha)<p class="mt-1 text-xs text-gray-400">Commit {{ substr($change->commit_sha, 0, 8) }}</p>@endif
                    @if(in_array($change->status, ['proposed', 'failed'], true))
                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                            <input type="text" wire:model="notes.{{ $change->id }}" placeholder="Not (isteğe bağlı): nasıl yapılsın" aria-label="Not" class="min-w-48 flex-1 rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950">
                            <button type="button" wire:click="approve({{ $change->id }})" class="rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white">{{ $change->status === 'failed' ? 'Tekrar dene' : 'Onayla' }}</button>
                            <button type="button" wire:click="reject({{ $change->id }})" class="rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Reddet</button>
                        </div>
                    @endif
                </article>
            @empty
                <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Bu bölümde değişiklik yok.</p>
            @endforelse
        </div>
    @endif
</div>
