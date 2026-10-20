@php
    $isAdmin = (bool) auth()->user()?->hasRole(\App\Support\Roles::ADMIN);
    $groups = $items->groupBy(fn (\App\Models\Suggestion $s): string => (string) ($accounts[$s->target_id]?->brand?->name ?? ''));
    $matchLabels = ['EXACT' => 'tam eşleme', 'PHRASE' => 'sıralı eşleme', 'BROAD' => 'geniş eşleme'];
    $manual = fn (\App\Models\Suggestion $s): bool => in_array($s->action_type, ['ads_check', \App\Services\GoogleAds\GoogleAdsSuggestions::ADVICE_TYPE], true);
    $kindLabel = fn (\App\Models\Suggestion $s): string => match ($s->action_type) {
        'ads_negative' => (data_get($s->action, 'scope') === 'shared' ? 'Paylaşılan liste' : 'Editor dosyası'),
        'ads_change' => 'Ayar değişikliği, '.(\App\Services\Repair\RepairDesk::RISKS[data_get($s->action, 'risk', 'medium')] ?? 'Orta').' risk',
        'ads_check' => 'Sistem kontrolü, elle',
        'ads_advice' => 'Tavsiye, elle',
        'ads_campaign' => 'Kampanya taslağı',
        'ads_rsa' => 'Reklam metni',
        'ads_budget_split' => 'Bütçe dağılımı',
        'ads_experiments' => 'Deney planı',
        default => $s->action_type,
    };
    // The page's one-line answer: how much the waiting negatives would save, or how many decisions wait.
    $wasted = (float) $items->where('action_type', 'ads_negative')->sum(fn (\App\Models\Suggestion $s): float => (float) data_get($s->action, 'cost', 0));
    $sub = match (true) {
        $items->isEmpty() => null,
        $active === 'operator.google-ads-terms' => $items->count().' negatif önerisi'.($wasted > 0 ? ', bu aramalara giden '.number_format($wasted, 0, ',', '.').' TRY' : '').'.',
        $active === 'operator.google-ads-ads' => $items->count().' taslak bekliyor. Onaylanan taslak hesabın Editor dosyasına girer.',
        default => $items->count().' değişiklik ve kontrol bekliyor, '.$groups->count().' markada.',
    };
@endphp
<div class="space-y-10 dark:text-gray-200" data-google-ads-tab="{{ $active }}">
    @include('livewire.operator.google-ads.desk.header', ['active' => $active, 'intro' => $intro, 'sub' => $sub, 'bulk' => $items->isNotEmpty() && $isAdmin ? ['count' => $items->count()] : null])

    @if ($editor !== [])
        <section data-testid="google-ads-editor-waiting">
            <div class="section-head"><h2 class="section-title">Editor dosyası bekleyen hesaplar <span class="section-count">{{ count($editor) }}</span></h2></div>
            <ul class="card">
                @foreach ($editor as $e)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <x-ta.brand-avatar :name="$e['brand']" :logo="$e['asset']->brand?->logo_url" />
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $e['brand'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ $e['drafts'] > 0 ? $e['drafts'].' onaylı taslak indirilmedi' : '' }}{{ $e['drafts'] > 0 && $e['batches'] > 0 ? ', ' : '' }}{{ $e['batches'] > 0 ? $e['batches'].' dosya “Editor’a aktardım” bekliyor' : '' }}</p>
                        </div>
                        <a href="{{ route('operator.google-ads.overview', ['assetId' => $e['asset']->id, 'tab' => 'strategy']) }}" wire:navigate class="btn w-full sm:w-auto">Dosyayı indir</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @forelse ($groups as $brandName => $brandItems)
        <section wire:key="grp-{{ md5((string) $brandName) }}">
            <div class="section-head">
                <h2 class="section-title flex min-w-0 items-center gap-2"><x-ta.brand-avatar :name="(string) $brandName" :logo="$accounts[$brandItems->first()->target_id]?->brand?->logo_url" size="sm" /><span class="min-w-0 break-words">{{ $brandName }}</span> <span class="section-count">{{ $brandItems->count() }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($brandItems as $s)
                    @php $a = (array) $s->action; @endphp
                    <li class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="sg-{{ $s->id }}" data-testid="google-ads-suggestion">
                        @if ($isAdmin && ! $manual($s))
                            <input type="checkbox" wire:model.live="selected" value="{{ $s->id }}" class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 dark:border-gray-600" aria-label="Seç">
                        @elseif ($isAdmin)
                            <span class="hidden w-4 shrink-0 sm:block" aria-hidden="true"></span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm font-medium text-gray-900 dark:text-white">{{ $s->title }} <span class="ml-1 whitespace-nowrap text-xs font-normal text-gray-500">{{ $kindLabel($s) }}</span></p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $s->reason }}</p>
                            @switch($s->action_type)
                                @case('ads_negative')
                                    @php $negParts = array_values(array_filter([$matchLabels[$a['match_type'] ?? ''] ?? '', (string) ($a['campaign'] ?? '')])); @endphp
                                    <p class="mt-0.5 text-xs text-gray-500">{{ implode(', ', $negParts) }}@if (isset($a['cost'])){{ $negParts !== [] ? ', ' : '' }}boşa giden <span class="font-medium text-error-700 dark:text-error-400">{{ number_format((float) $a['cost'], 0, ',', '.') }}</span>@endif
                                        @if (($a['blocks'] ?? []) !== [])<span class="block text-warning-700 dark:text-warning-300">Engelleyebileceği faydalı aramalar: {{ implode(', ', array_slice((array) $a['blocks'], 0, 5)) }}</span>@endif</p>
                                    @break
                                @case('ads_change')
                                    <p class="mt-0.5 text-xs"><span class="text-gray-500">{{ $a['target'] ?? '' }}:</span> <span class="text-gray-500 line-through">{{ \App\Services\ExternalWrites\GoogleAdsChangeWriter::text($a['before'] ?? null) }}</span> <span class="text-gray-500">yerine</span> <span class="font-medium text-gray-900 dark:text-white">{{ $a['label'] ?? '' }}</span></p>
                                    @break
                                @case('ads_check')
                                @case('ads_advice')
                                    @if (filled($a['todo'] ?? null))<p class="mt-0.5 text-xs text-gray-700 dark:text-gray-300">Yapılacak: {{ $a['todo'] }}</p>@endif
                                    @break
                                @case('ads_campaign')
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $a['service'] ?? '' }}, günlük bütçe {{ $a['daily_budget'] ?? '—' }}, {{ count((array) ($a['ad_groups'] ?? [])) }} reklam grubu</p>
                                    @break
                                @case('ads_rsa')
                                    <p class="mt-0.5 break-words text-xs text-gray-500">{{ $a['campaign'] ?? '' }} › {{ $a['ad_group'] ?? '' }}, {{ count((array) ($a['headlines'] ?? [])) }} başlık, {{ count((array) ($a['descriptions'] ?? [])) }} açıklama, {{ $a['final_url'] ?? '' }}</p>
                                    @break
                            @endswitch
                            @if (filled($a[\App\Services\Repair\RepairDesk::DESK_ERROR] ?? null))<p class="mt-0.5 text-xs text-error-700 dark:text-error-400">Önceki gönderim başarısız: {{ $a[\App\Services\Repair\RepairDesk::DESK_ERROR] }}</p>@endif
                            @if (\App\Services\Repair\RepairDesk::isQueued($s))<p class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300"><span class="live-dot"></span>Gönderiliyor…</p>@endif
                        </div>
                        <div class="flex w-full shrink-0 flex-wrap items-center gap-1.5 sm:w-auto sm:justify-end">
                            @if ($isAdmin && ! $manual($s) && ! \App\Services\Repair\RepairDesk::isQueued($s))
                                <button type="button" wire:click="approve({{ $s->id }})" class="btn">Onayla</button>
                            @endif
                            @if ($isAdmin)
                                <button type="button" wire:click="snooze({{ $s->id }})" class="btn-quiet px-2!">7 gün ertele</button>
                                <button type="button" wire:click="dismiss({{ $s->id }})" class="btn-quiet px-2!">Reddet</button>
                            @endif
                            <a href="{{ route('operator.google-ads.overview', ['assetId' => $s->target_id, 'tab' => $accountTab]) }}" wire:navigate class="btn-quiet px-2!">Hesapta aç</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card"><x-ta.empty-state size="sm" :title="$empty" data-testid="google-ads-empty" /></div>
    @endforelse
</div>
