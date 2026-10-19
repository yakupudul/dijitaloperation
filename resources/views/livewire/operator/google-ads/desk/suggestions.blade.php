@php
    $isAdmin = (bool) auth()->user()?->hasRole(\App\Support\Roles::ADMIN);
    $groups = $items->groupBy(fn (\App\Models\Suggestion $s): string => (string) ($accounts[$s->target_id]?->brand?->name ?? ''));
    $matchLabels = ['EXACT' => 'tam eşleme', 'PHRASE' => 'sıralı eşleme', 'BROAD' => 'geniş eşleme'];
    $manual = fn (\App\Models\Suggestion $s): bool => in_array($s->action_type, ['ads_check', \App\Services\GoogleAds\GoogleAdsSuggestions::ADVICE_TYPE], true);
    $kindLabel = fn (\App\Models\Suggestion $s): string => match ($s->action_type) {
        'ads_negative' => (data_get($s->action, 'scope') === 'shared' ? 'Paylaşılan liste' : 'Editor dosyası'),
        'ads_change' => 'Ayar değişikliği · '.(\App\Services\Repair\RepairDesk::RISKS[data_get($s->action, 'risk', 'medium')] ?? 'Orta').' risk',
        'ads_check' => 'Sistem kontrolü · elle',
        'ads_advice' => 'Tavsiye · elle',
        'ads_campaign' => 'Kampanya taslağı',
        'ads_rsa' => 'Reklam metni',
        'ads_budget_split' => 'Bütçe dağılımı',
        'ads_experiments' => 'Deney planı',
        default => $s->action_type,
    };
@endphp
<div class="space-y-5 dark:text-gray-200" data-google-ads-tab="{{ $active }}">
    @include('livewire.operator.google-ads.desk.header', ['active' => $active, 'intro' => $intro])

    @if ($editor !== [])
        <section class="surface p-4" data-testid="google-ads-editor-waiting">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Editor dosyası bekleyen hesaplar</h2>
            <ul class="mt-1 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($editor as $e)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-1.5 text-sm">
                        <span class="min-w-0 break-words">{{ $e['brand'] }} · <span class="text-gray-500">{{ $e['drafts'] > 0 ? $e['drafts'].' onaylı taslak indirilmedi' : '' }}{{ $e['drafts'] > 0 && $e['batches'] > 0 ? ' · ' : '' }}{{ $e['batches'] > 0 ? $e['batches'].' dosya “Editor’a aktardım” bekliyor' : '' }}</span></span>
                        <a href="{{ route('operator.google-ads.overview', ['assetId' => $e['asset']->id, 'tab' => 'strategy']) }}" wire:navigate class="link-neutral text-xs font-medium">Dosyayı indir →</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($items->isNotEmpty() && $isAdmin)
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-gray-500">{{ $items->count() }} öneri · {{ count($selected) }} seçili</span>
            <button type="button" wire:click="approveSelected" @disabled($selected === []) class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-40" data-testid="google-ads-approve-selected">Seçilenleri onayla</button>
            <span class="text-xs text-gray-500">Yüksek riskliler tek tek onaylanır. Elle yapılacak kontroller onaylanmaz, Google Ads’te yapılınca ertesi sabah kendiliğinden kapanır.</span>
        </div>
    @endif

    @forelse ($groups as $brandName => $brandItems)
        <section class="surface overflow-hidden" wire:key="grp-{{ md5((string) $brandName) }}">
            <h2 class="flex min-w-0 items-center gap-2 border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-white"><x-ta.brand-avatar :name="(string) $brandName" size="xs" /><span class="min-w-0 break-words">{{ $brandName }}</span> <span class="font-normal tabular-nums text-gray-500">· {{ $brandItems->count() }}</span></h2>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($brandItems as $s)
                    @php $a = (array) $s->action; @endphp
                    <li class="flex flex-wrap items-start gap-3 px-4 py-3" wire:key="sg-{{ $s->id }}" data-testid="google-ads-suggestion">
                        @if ($isAdmin && ! $manual($s))
                            <input type="checkbox" wire:model.live="selected" value="{{ $s->id }}" class="mt-1 rounded border-gray-300 dark:border-gray-600" aria-label="Seç">
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }} <span class="ml-1 whitespace-nowrap rounded bg-gray-100 px-1.5 text-[11px] font-normal text-gray-600 dark:bg-white/[0.06] dark:text-gray-400">{{ $kindLabel($s) }}</span></p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $s->reason }}</p>
                            @switch($s->action_type)
                                @case('ads_negative')
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $matchLabels[$a['match_type'] ?? ''] ?? '' }}@if (filled($a['campaign'] ?? null)) · {{ $a['campaign'] }}@endif @if (isset($a['cost'])) · boşa giden <span class="font-semibold text-error-600">{{ number_format((float) $a['cost'], 0, ',', '.') }}</span>@endif
                                        @if (($a['blocks'] ?? []) !== [])<span class="block text-warning-700 dark:text-warning-300">Engelleyebileceği faydalı aramalar: {{ implode(', ', array_slice((array) $a['blocks'], 0, 5)) }}</span>@endif</p>
                                    @break
                                @case('ads_change')
                                    <p class="mt-0.5 text-xs"><span class="text-gray-500">{{ $a['target'] ?? '' }}:</span> <span class="text-gray-500 line-through">{{ \App\Services\ExternalWrites\GoogleAdsChangeWriter::text($a['before'] ?? null) }}</span> → <span class="font-semibold text-gray-900 dark:text-white">{{ $a['label'] ?? '' }}</span></p>
                                    @break
                                @case('ads_check')
                                @case('ads_advice')
                                    @if (filled($a['todo'] ?? null))<p class="mt-0.5 text-xs text-gray-700 dark:text-gray-300">Yapılacak: {{ $a['todo'] }}</p>@endif
                                    @break
                                @case('ads_campaign')
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $a['service'] ?? '' }} · günlük bütçe {{ $a['daily_budget'] ?? '—' }} · {{ count((array) ($a['ad_groups'] ?? [])) }} reklam grubu</p>
                                    @break
                                @case('ads_rsa')
                                    <p class="mt-0.5 break-words text-xs text-gray-500">{{ $a['campaign'] ?? '' }} › {{ $a['ad_group'] ?? '' }} · {{ count((array) ($a['headlines'] ?? [])) }} başlık, {{ count((array) ($a['descriptions'] ?? [])) }} açıklama · {{ $a['final_url'] ?? '' }}</p>
                                    @break
                            @endswitch
                            @if (filled($a[\App\Services\Repair\RepairDesk::DESK_ERROR] ?? null))<p class="mt-0.5 text-xs text-error-600">Önceki gönderim başarısız: {{ $a[\App\Services\Repair\RepairDesk::DESK_ERROR] }}</p>@endif
                            @if (\App\Services\Repair\RepairDesk::isQueued($s))<p class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><span class="live-dot"></span>Gönderiliyor…</p>@endif
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2 text-xs">
                            @if ($isAdmin && ! $manual($s) && ! \App\Services\Repair\RepairDesk::isQueued($s))
                                <button type="button" wire:click="approve({{ $s->id }})" class="rounded-lg bg-white px-2.5 py-1 font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-transparent dark:text-gray-100 dark:ring-gray-600 dark:hover:bg-white/[0.04]">Onayla</button>
                            @endif
                            @if ($isAdmin)
                                <button type="button" wire:click="snooze({{ $s->id }})" class="rounded-lg px-2 py-1 text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-white/[0.04]">7 gün ertele</button>
                                <button type="button" wire:click="dismiss({{ $s->id }})" class="rounded-lg px-2 py-1 text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.04] dark:hover:text-white">Reddet</button>
                            @endif
                            <a href="{{ route('operator.google-ads.overview', ['assetId' => $s->target_id, 'tab' => $accountTab]) }}" wire:navigate class="link-neutral">Hesapta aç →</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <x-ta.empty-state size="sm" :title="$empty" data-testid="google-ads-empty" />
    @endforelse
</div>
