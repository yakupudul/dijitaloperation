@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 3 : 2, ',', '.');
    $sec = fn (?int $ms): string => $ms === null ? '—' : number_format($ms / 1000, 1, ',', '.').' sn';
    $title = 'text-sm font-medium text-gray-900 dark:text-white';
    $line = 'mt-0.5 text-[13px] text-gray-500 dark:text-gray-400';
    $input = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white';
    $rowBtn = 'btn h-7! px-2.5! text-xs!';
    $field = 'flex min-w-0 flex-col gap-1 text-[13px] text-gray-600 dark:text-gray-400';
@endphp
<div class="space-y-10">
    @if (session('status'))
        <p class="tone-ok rounded-lg px-4 py-2 text-sm">{{ session('status') }}</p>
    @endif

    @if ($detail === null)
        @php
            $busy = $live['running']->isNotEmpty() || $live['queued']->isNotEmpty();
            $who = fn ($item): string => $item->user_id && isset($live['users'][$item->user_id]) ? $live['users'][$item->user_id] : 'Otomatik';
            $where = fn ($item): array => array_values(array_filter(array_map('trim', explode(' · ', (string) $item->subject))));
            $capHit = $autoBudget > 0 && $autoSpend >= $autoBudget;
        @endphp
        <header>
            <h1 class="page-title">AI işlemleri</h1>
            <p class="page-sub">{{ $live['running']->isNotEmpty() ? $live['running']->count().' AI işi çalışıyor' : 'Şu an çalışan AI işi yok' }}{{ $live['queued']->isNotEmpty() ? ', '.$live['queued']->count().' iş sırada' : '' }}.</p>
        </header>

        <div class="space-y-10" data-ai-live title="Liste kendiliğinden yenilenir." @if ($busy) wire:poll.5s @else wire:poll.30s @endif>
            {{-- 1. Four numbers: running, queued, finished today, money. --}}
            <div class="metric-strip -mt-4 grid-cols-2 lg:grid-cols-4" data-ai-summary>
                <x-ta.metric-card label="Şu an çalışan" :value="$live['running']->count()" />
                <x-ta.metric-card label="Sırada bekleyen" :value="$live['queued']->count()" />
                <x-ta.metric-card label="Bugün biten" :value="$live['today']['done']" :delta="$live['today']['failed'] > 0 ? $live['today']['failed'].' hata' : null" tone="critical" />
                <x-ta.metric-card label="Maliyet, bugün / bu ay" :value="$usd($live['today']['cost'])" :hint="'Bu ay '.$usd($monthSpend)" data-ai-remaining>
                    <p @class(['text-error-600 dark:text-error-400' => $remaining <= 0, 'text-gray-500' => $remaining > 0])>Kalan bakiye: {{ $usd($remaining) }} (bütçe {{ $usd($monthlyBudget) }})</p>
                    <p @class(['mt-0.5', 'font-medium text-error-600 dark:text-error-400' => $capHit, 'text-gray-500' => ! $capHit]) data-ai-auto-budget>
                        Bugün tüm AI: {{ $usd($autoSpend) }} / {{ $autoBudget > 0 ? $usd($autoBudget) : 'sınırsız' }}@if ($capHit), tavan doldu, ücretli AI yarına kadar durdu (Claude işleri sürer) @endif
                    </p>
                </x-ta.metric-card>
            </div>

            {{-- 2. What runs right now. --}}
            <section id="canli" class="scroll-mt-20">
                <div class="section-head">
                    <h2 class="section-title">Canlı <span class="section-count">{{ $live['running']->count() }}</span></h2>
                    <a href="{{ route('operator.ai-jobs') }}" wire:navigate class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white" data-ai-jobs-link title="Tümü, ayrıntı, durdur / sil">AI işleri</a>
                </div>
                <div class="card">
                    @forelse ($live['running'] as $item)
                        @php($step = $live['steps'][$item->id] ?? null)
                        <div class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="run-{{ $item->id }}" data-ai-live-running>
                            <span class="row-icon" aria-hidden="true"><span class="live-dot"></span></span>
                            <div class="min-w-0 flex-1">
                                <p class="{{ $title }}">
                                    {{ $item->label }}
                                    @if ($item->cancelRequested())<span class="ml-1 text-xs font-medium text-warning-700 dark:text-warning-400">durduruluyor…</span>@endif
                                </p>
                                <p class="{{ $line }}">
                                    @if ($where($item) !== []){{ implode(' › ', $where($item)) }}@else genel (marka dışı)@endif
                                    @if ($item->link)<a href="{{ $item->link }}" wire:navigate class="link-neutral ml-1">aç</a>@endif
                                </p>
                                @if ($step !== null)
                                    <p class="mt-0.5 text-[13px] text-gray-700 dark:text-gray-300" data-ai-step><span class="text-gray-500">Adım:</span> {{ $step['running'] ? $step['label'] : 'sonraki AI çağrısına hazırlanıyor' }}<span class="text-gray-500">, {{ $step['done'] }} çağrı bitti{{ $step['model'] !== '' ? ', '.$step['model'] : '' }}</span></p>
                                @elseif ($item->model)
                                    <p class="text-xs text-gray-500">Model: {{ $item->model }}</p>
                                @endif
                                <p class="text-xs text-gray-500">Başlatan: {{ $who($item) }}, başladı {{ $item->started_at?->timezone('Europe/Istanbul')->format('H:i:s') }}</p>
                            </div>
                            <div class="flex w-full shrink-0 items-center gap-3 sm:w-auto">
                                <span class="text-right">
                                    <span class="block text-sm font-semibold tabular-nums text-gray-900 dark:text-white">{{ $item->durationLabel() }}</span>
                                    <span class="block text-xs tabular-nums text-gray-500">{{ $usd($step['cost'] ?? $item->cost_usd) }}</span>
                                </span>
                                <a href="{{ route('operator.ai-jobs', ['is' => $item->id]) }}" wire:navigate class="{{ $rowBtn }} ml-auto sm:ml-0">Ayrıntı</a>
                                @if (! $item->cancelRequested())<button type="button" wire:click="stop({{ $item->id }})" wire:confirm="Bu AI işi durdurulsun mu?" class="{{ $rowBtn }}" data-ai-stop>Durdur</button>@endif
                            </div>
                        </div>
                    @empty
                        <div class="card-row" data-ai-idle>
                            <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                            <div class="min-w-0 flex-1">
                                <p class="{{ $title }}">Şu an AI çalışmıyor.</p>
                                @if (($schedule[0]['next'] ?? null) !== null)<p class="{{ $line }}">Sıradaki otomatik iş: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $schedule[0]['label'] }}</span>, {{ $schedule[0]['next']->format('d.m H:i') }}.</p>@endif
                            </div>
                        </div>
                    @endforelse
                </div>

                @if ($live['queued']->isNotEmpty())
                    <div class="section-head mt-6"><h3 class="section-title">Sırada <span class="section-count">{{ $live['queued']->count() }}</span></h3></div>
                    <ul class="card" data-ai-queued>
                        @foreach ($live['queued'] as $item)
                            <li class="card-row" wire:key="queued-{{ $item->id }}">
                                <div class="min-w-0 flex-1">
                                    <p class="{{ $title }}">{{ $item->label }}</p>
                                    <p class="{{ $line }}">@if ($where($item) !== []){{ implode(' › ', $where($item)) }}, @endif{{ $who($item) }}, {{ $item->queued_at?->timezone('Europe/Istanbul')->format('H:i') }}'dan beri bekliyor</p>
                                </div>
                                <button type="button" wire:click="stop({{ $item->id }})" class="{{ $rowBtn }}">Kaldır</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- AI iş kuyruğu (Claude MCP) --}}
            @if ($mcpTasks !== null)
                <section data-ai-tasks>
                    <div class="section-head">
                        <h2 class="section-title" title="&quot;Claude (MCP)&quot; seçilen işlemler API'yi çağırmaz; Claude kuyruğu çekip sonucu yazınca iş devam eder.">Claude (MCP) iş kuyruğu</h2>
                        <span class="text-[13px] text-gray-500">Claude bekleniyor: <span class="font-medium tabular-nums text-gray-900 dark:text-white">{{ $mcpTasks['open'] }}</span>, son 7 günde biten: <span class="tabular-nums">{{ $mcpTasks['done'] }}</span>, yapılamayan: <span class="tabular-nums">{{ $mcpTasks['failed'] }}</span></span>
                    </div>
                    @if ($mcpTasks['recent']->isNotEmpty())
                        <ul class="card">
                            @foreach ($mcpTasks['recent'] as $task)
                                <li class="card-row flex-wrap justify-between gap-y-1 py-3!">
                                    <span class="min-w-0 text-sm text-gray-900 dark:text-white">{{ \App\Support\Ai\AiOperationLabels::for($task->operation) }} <span class="text-[13px] text-gray-500">{{ $task->subject ?? $task->brand?->name ?? '—' }}</span></span>
                                    <x-ta.pill :tone="$task->status === \App\Models\AiTask::FAILED ? 'critical' : 'info'" :title="$task->error">{{ \App\Models\AiTask::STATUS_LABELS[$task->status] ?? $task->status }}, {{ $task->created_at?->diffForHumans() }}</x-ta.pill>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            {{-- Harcama nereye gitti --}}
            <section data-ai-costs>
                <div class="section-head items-center">
                    <h2 class="section-title">Harcama nereye gitti</h2>
                    <div class="ml-auto inline-flex gap-1.5">
                        <button type="button" wire:click="$set('costHours', 24)" @class(['seg h-8! px-3!', 'seg-active' => $costHours === 24])>24 saat</button>
                        <button type="button" wire:click="$set('costHours', 168)" @class(['seg h-8! px-3!', 'seg-active' => $costHours === 168])>7 gün</button>
                    </div>
                </div>
                @php($costTotal = array_sum(array_column($costs, 'cost')))
                <div class="card overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>İşlem</th><th class="text-right!">Çağrı</th><th class="text-right!">Maliyet</th><th class="text-right!">Pay</th><th class="hidden text-right! sm:table-cell">Otomatik</th><th class="hidden md:table-cell">Model</th></tr></thead>
                        <tbody>
                            @forelse ($costs as $cost)
                                <tr wire:key="cost-{{ $cost['operation'] }}">
                                    <td class="text-gray-900 dark:text-white">{{ $cost['label'] }}</td>
                                    <td class="text-right tabular-nums">{{ $cost['calls'] }}</td>
                                    <td class="text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $usd($cost['cost']) }}</td>
                                    <td class="text-right tabular-nums text-gray-500">{{ $costTotal > 0 ? round($cost['cost'] / $costTotal * 100) : 0 }}%</td>
                                    <td class="hidden text-right tabular-nums text-gray-500 sm:table-cell">{{ $usd($cost['auto_cost']) }}</td>
                                    <td class="hidden max-w-[16rem] truncate text-xs text-gray-500 md:table-cell" title="{{ $cost['model'] ?? '' }}">{{ $cost['model'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-gray-500">Bu sürede AI harcaması yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-10 lg:grid-cols-3 lg:gap-6">
                {{-- Son 24 saat --}}
                <section class="min-w-0 lg:col-span-2" data-ai-finished>
                    <div class="section-head"><h2 class="section-title">Son 24 saat <span class="section-count">{{ count($live['finished']) }}</span></h2></div>
                    <ul class="card">
                        @forelse ($live['finished'] as $item)
                            <li class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="done-{{ $item->id }}">
                                <x-ta.pill :tone="match ($item->status) { 'done' => 'ok', 'cancelled', 'running', 'queued' => 'info', default => 'critical' }" class="mt-1.5 shrink-0" aria-hidden="true"></x-ta.pill>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('operator.ai-jobs', ['is' => $item->id]) }}" wire:navigate class="{{ $title }} hover:underline">{{ $item->label }}</a>
                                    <p class="{{ $line }}">@if ($where($item) !== []){{ implode(' › ', $where($item)) }}, @endif{{ $item->finished_at?->timezone('Europe/Istanbul')->format('H:i') }}, {{ $who($item) }}</p>
                                    @if ($item->status !== 'done' && $item->error)<p class="mt-0.5 line-clamp-2 break-all text-xs text-error-600 dark:text-error-400" title="{{ $item->error }}">{{ $item->error }}</p>@endif
                                </div>
                                <span class="flex w-full shrink-0 justify-between gap-3 pl-5 sm:block sm:w-auto sm:pl-0 sm:text-right">
                                    <span class="block whitespace-nowrap text-xs tabular-nums text-gray-500">{{ $item->statusLabel() }} · {{ $item->durationLabel() }}</span>
                                    <span class="block text-xs tabular-nums text-gray-900 dark:text-white">{{ $item->cost_usd !== null ? $usd($item->cost_usd) : '—' }}</span>
                                </span>
                            </li>
                        @empty
                            <li><x-ta.empty-state size="sm" icon="none" title="Son 24 saatte AI işi yok." /></li>
                        @endforelse
                    </ul>
                </section>

                {{-- Otomatik takvim --}}
                <section class="min-w-0" data-ai-schedule>
                    <div class="section-head"><h2 class="section-title" title="Kimse tıklamadan AI çalıştıran işler.">Otomatik takvim</h2></div>
                    <ul class="card">
                        @foreach ($schedule as $task)
                            <li class="block border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" wire:key="sched-{{ $task['name'] }}">
                                <p class="flex items-baseline justify-between gap-2"><span class="{{ $title }}">{{ $task['label'] }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $task['next']?->format('d.m H:i') ?? '—' }}</span></p>
                                <p class="mt-0.5 text-xs text-gray-500">{{ $task['what'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>

        {{-- GPT / Claude API / Claude abonelik dağılımı ve yüklenen kredi --}}
        <section data-ai-credits>
            <div class="section-head">
                <h2 class="section-title" title="Her AI işlemi üç yoldan biriyle çalışır: GPT (OpenAI), Claude API (yüklediğin krediden düşer) veya Claude abonelik (MCP kuyruğu). Claude API kredisi biter ya da günlük tavan dolarsa, kuyruğu destekleyen işler kendiliğinden aboneliğe geçer.">AI dağılımı ve kredi</h2>
            </div>
            <div class="card">
                @foreach ($credits as $credit)
                    <div wire:key="credit-{{ $credit['provider'] }}" class="card-row flex-wrap sm:flex-nowrap" data-credit="{{ $credit['provider'] }}">
                        <div class="min-w-0 flex-1">
                            <p class="{{ $title }}">{{ $credit['label'] }} kredisi</p>
                            @if ($credit['tracked'])
                                <p class="{{ $line }}">Yüklenen {{ $usd($credit['loaded']) }}, harcanan {{ $usd($credit['spent']) }}</p>
                            @else
                                <p class="{{ $line }}">Kredi girilmedi; yalnız aylık bütçe ve günlük tavan uygulanır.</p>
                            @endif
                        </div>
                        @if ($credit['tracked'])
                            <span class="text-right">
                                <span class="block text-base font-semibold tabular-nums text-gray-900 dark:text-white">{{ $usd($credit['remaining']) }} <span class="text-xs font-normal text-gray-500">kaldı</span></span>
                                @if ($credit['exhausted'])
                                    <x-ta.pill tone="critical">Kredi bitti; bu sağlayıcıya ücretli çağrı başlamaz.</x-ta.pill>
                                @elseif ($credit['low'])
                                    <x-ta.pill tone="look">Kredi azaldı.</x-ta.pill>
                                @endif
                            </span>
                        @endif
                    </div>
                @endforeach
                <form wire:submit="topUpCredit" class="card-row flex-wrap items-end gap-3" data-credit-topup>
                    <label class="{{ $field }} w-full sm:w-auto">
                        <span>Sağlayıcı</span>
                        <select wire:model="creditProvider" class="{{ $input }}">
                            @foreach (\App\Services\Ai\AiCredits::PROVIDERS as $provider)
                                <option value="{{ $provider }}">{{ \App\Services\Ai\AiCredits::label($provider) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="{{ $field }} w-full sm:w-auto">
                        <span>Yüklenen kredi (USD)</span>
                        <input type="number" step="1" min="1" wire:model="creditAmount" class="{{ $input }} w-full sm:w-32">
                    </label>
                    <label class="{{ $field }} w-full sm:w-auto sm:flex-1">
                        <span>Not (isteğe bağlı)</span>
                        <input type="text" maxlength="190" wire:model="creditNote" class="{{ $input }} w-full">
                    </label>
                    <button type="submit" class="btn h-9! w-full sm:w-auto">Kredi ekle</button>
                    @error('creditAmount')<span class="w-full text-xs text-error-600">{{ $message }}</span>@enderror
                    <span class="w-full text-xs text-gray-500">Kalan = yüklenen − ilk yüklemeden beri kaydedilen maliyet. Sağlayıcı panelindeki bakiyeyle arada küçük fark olabilir.</span>
                </form>
                <div class="card-row block space-y-2.5" data-ai-plans>
                    <p class="text-[13px] text-gray-500">Şu an: @foreach ($kinds as $kind => $count)<span class="mr-3 whitespace-nowrap"><span class="text-gray-700 dark:text-gray-300">{{ \App\Services\Ai\AiAssignments::kindLabel($kind) }}</span> <span class="tabular-nums text-gray-900 dark:text-white">{{ $count }}</span></span>@endforeach</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($plans as $plan => $planLabel)
                            <button type="button" wire:key="plan-{{ $plan }}" wire:click="assignPlan('{{ $plan }}')" wire:confirm="{{ $planLabel }} uygulansın mı? Değişen her işlem için yeni prompt sürümü açılır; sorgu pilotu ve WhatsApp yerinde kalır." class="btn h-auto! min-h-8 max-w-full whitespace-normal! py-1.5! text-left" data-plan="{{ $plan }}">{{ $planLabel }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <details class="group/budget" data-ai-budget>
            <summary class="card flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.02] [&::-webkit-details-marker]:hidden">
                <span class="min-w-0 flex-1">
                    <span class="block {{ $title }}">AI bütçesi</span>
                    <span class="block {{ $line }}">aylık {{ $usd($monthlyBudget) }}, günlük tavan (tüm AI) {{ $autoBudget > 0 ? $usd($autoBudget) : 'sınırsız' }}, Kalan bakiye {{ $usd($remaining) }}</span>
                </span>
                <span class="btn-quiet"><span class="group-open/budget:hidden">Düzenle</span><span class="hidden group-open/budget:inline">Kapat</span></span>
            </summary>
            <form wire:submit="saveBudget" class="card mt-3">
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <label for="ai-budget" class="min-w-0 flex-1 {{ $title }}">Aylık AI bütçesi (USD)</label>
                    <input id="ai-budget" type="number" step="1" min="0" wire:model="budget" class="{{ $input }} w-full sm:w-40">
                </div>
                <div class="card-row flex-wrap sm:flex-nowrap">
                    <label for="ai-daily" class="min-w-0 flex-1 {{ $title }}">Günlük AI tavanı, tüm işler (USD)<span class="block {{ $line }} font-normal">0 = tavan yok.</span></label>
                    <input id="ai-daily" type="number" step="0.5" min="0" wire:model="dailyAutoBudget" class="{{ $input }} w-full sm:w-40" data-daily-auto-budget>
                </div>
                {{-- OpenAI kota / Admin anahtarı alanları kalktı (2026-10-20): tüm AI Claude ile çalışır. --}}
                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                    <p class="min-w-0 flex-1 basis-full text-xs text-gray-500 sm:basis-0">Aylık bakiye bitince ay sonuna kadar yalnız ücretsiz modeller çalışır. Günün (İstanbul saati) tüm AI harcaması tavana ulaşınca, tıkladığın işler dahil hiçbir ücretli AI çağrısı başlamaz; yarın yeniden çalışır. Kimse tıklamadan Sorgular alanındaki AI (sorgu pilotu, kümeleme) Claude aboneliğine devredilen işler ve Claude API'ye atanmış işler çalışır; abonelik işleri tavana ve bütçeye sayılmaz, Claude API işleri krediden, tavandan ve bütçeden düşer. GPT'de kalan analistler, bakım ajanı ve Şef yalnız tıklayınca çalışır.</p>
                    @error('dailyAutoBudget')<span class="w-full text-xs text-error-600">{{ $message }}</span>@enderror
                    @error('budget')<span class="w-full text-xs text-error-600">{{ $message }}</span>@enderror
                    <button type="submit" class="btn-primary w-full sm:w-auto">Kaydet</button>
                </div>
            </form>
        </details>

        <details class="group/ops -mt-7" data-ai-operations>
            <summary class="card flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.02] [&::-webkit-details-marker]:hidden" title="Talimatı görmek / düzenlemek için aç">
                <span class="min-w-0 flex-1">
                    <span class="block {{ $title }}">Promptlar ve modeller</span>
                    <span class="block {{ $line }}">{{ count($rows) }} AI işlemi</span>
                </span>
                <span class="btn-quiet"><span class="group-open/ops:hidden">Aç</span><span class="hidden group-open/ops:inline">Kapat</span></span>
            </summary>
            <div class="card mt-3 overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>İşlem</th>
                            <th class="hidden md:table-cell">Model</th>
                            <th class="text-right!">Sürüm</th>
                            <th class="text-right!">30 gün</th>
                            <th class="hidden text-right! sm:table-cell">Ort. süre</th>
                            <th class="text-right!">Maliyet</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (collect($rows)->sortByDesc('runs') as $row)
                            <tr wire:key="op-{{ $row['operation'] }}">
                                <td>
                                    <button type="button" wire:click="open('{{ $row['operation'] }}')" class="text-left font-medium text-gray-900 hover:underline dark:text-white" title="{{ $row['operation'] }}">{{ \App\Support\Ai\AiOperationLabels::for($row['operation']) }}</button>
                                    <p class="text-xs text-gray-500">{{ $row['purpose'] }}</p>
                                </td>
                                <td class="hidden text-xs text-gray-500 md:table-cell"><span class="text-gray-700 dark:text-gray-200">{{ \App\Services\Ai\AiAssignments::kindLabel($row['kind']) }},</span> {{ $row['model'] !== '' ? $row['model'] : 'Rota modeli' }}</td>
                                <td class="text-right tabular-nums">{{ $row['version'] !== null ? 'v'.$row['version'] : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row['runs'] }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell">{{ $sec($row['avg_ms']) }}</td>
                                <td class="text-right tabular-nums">{{ $usd($row['cost']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @else
        @php($definition = $detail['definition'])
        <header>
            <button type="button" wire:click="close" class="btn-quiet -ml-2.5 mb-1 h-7!">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>İşlemler
            </button>
            <h1 class="page-title" title="{{ $operation }}">{{ \App\Support\Ai\AiOperationLabels::for($operation) }} <span class="text-sm font-normal text-gray-500">v{{ $detail['current']->version }}</span></h1>
            <p class="page-sub">{{ $detail['current']->purpose ?: $definition['purpose'] }}</p>
        </header>

        <form wire:submit="save" class="card" data-prompt-editor>
            <div class="px-4 py-4">
                <label for="prompt-template" class="text-[13px] font-medium text-gray-700 dark:text-gray-300">Şablon</label>
                <textarea id="prompt-template" wire:model="template" rows="18" class="mt-1.5 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-mono text-xs leading-5 text-gray-800 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white/90"></textarea>
                @error('template')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-500">Sabit cümle her sürüme kodla eklenir: “{{ \App\Services\Prompts\PromptRegistry::GUARD }}”</p>
            </div>
            <div class="card-row flex-wrap items-start sm:flex-nowrap">
                <div class="min-w-0 flex-1">
                    <p class="{{ $title }}">Değişkenler</p>
                    <div class="mt-1 flex flex-wrap gap-x-2 gap-y-1">
                        @forelse ($definition['variables'] as $variable)
                            <span class="font-mono text-xs text-gray-700 dark:text-gray-200">&#123;&#123;{{ $variable }}&#125;&#125;</span>
                        @empty
                            <span class="text-xs text-gray-500">Yok</span>
                        @endforelse
                    </div>
                </div>
                <div class="w-full sm:w-72">
                    <label for="prompt-model" class="{{ $title }}">Model</label>
                    <select id="prompt-model" wire:model="model" class="{{ $input }} mt-1 w-full">
                        @foreach ($detail['models'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="card-row block">
                <p class="{{ $title }}">Bağlam kaynakları</p>
                <ul class="mt-1 list-inside list-disc text-[13px] text-gray-600 dark:text-gray-300">
                    @foreach ($definition['context_sources'] as $source)<li>{{ $source }}</li>@endforeach
                </ul>
            </div>
            <details class="card-row block">
                <summary class="cursor-pointer {{ $title }}">Çıktı yapısı</summary>
                <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ json_encode($definition['output_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                <button type="button" wire:click="resetDefault" wire:confirm="Kodun varsayılan promptu yeni sürüm olarak yayınlansın mı?" class="btn w-full sm:w-auto">Varsayılana dön</button>
                <button type="submit" class="btn-primary w-full sm:w-auto">Yeni sürüm olarak kaydet</button>
            </div>
        </form>

        <section data-prompt-trial @if (($detail['trial']['status'] ?? null) === 'running') wire:poll.3s @endif>
            <div class="section-head items-center">
                <h2 class="section-title">Örnekte dene</h2>
                <div class="ml-auto flex flex-wrap gap-1">
                    @foreach ($detail['samples'] as $sample)
                        <button type="button" wire:click="useSample({{ $sample['id'] }})" wire:key="sample-{{ $sample['id'] }}" class="{{ $rowBtn }}">{{ \Illuminate\Support\Carbon::parse($sample['at'])->format('d.m H:i') }}{{ $sample['version'] !== null ? ', v'.$sample['version'] : '' }}</button>
                    @endforeach
                </div>
            </div>
            <div class="card">
                <div class="px-4 py-4">
                    <textarea wire:model="trialInput" rows="5" placeholder="Son çalıştırmalardan birini seçin ya da örnek girdi yapıştırın." class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-mono text-xs text-gray-800 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white/90"></textarea>
                    @error('trial')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="runTrial" wire:loading.attr="disabled" @disabled(($detail['trial']['status'] ?? null) === 'running') class="btn">Taslağı dene</button>
                        <span class="text-xs text-gray-500">Yayınlanmamış şablon, öneri kaydedilmez</span>
                    </div>
                </div>
                @if ($detail['trial'] !== null)
                    <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-800">
                        @if ($detail['trial']['status'] === 'running')
                            <p class="text-sm text-gray-500">Çalışıyor…</p>
                        @elseif ($detail['trial']['status'] === 'failed')
                            <p class="text-sm text-error-600">{{ $detail['trial']['message'] }}</p>
                        @else
                            <p class="text-xs text-gray-500">{{ $detail['trial']['model'] }}, {{ $sec($detail['trial']['duration_ms']) }}, {{ $usd($detail['trial']['cost']) }}</p>
                            <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $detail['trial']['output'] }}</pre>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
            <section class="min-w-0" data-prompt-versions>
                <div class="section-head"><h2 class="section-title">Sürüm geçmişi <span class="section-count">{{ count($detail['versions']) }}</span></h2></div>
                <ul class="card">
                    @foreach ($detail['versions'] as $version)
                        <li class="card-row" wire:key="v-{{ $version->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="{{ $title }}">v{{ $version->version }} <span class="font-normal text-gray-500">{{ $version->creator?->name ?? 'Sistem' }}</span></p>
                                <p class="{{ $line }}">{{ $version->created_at?->format('d.m.Y H:i') }}@if ($version->model), {{ $version->model }}@endif</p>
                            </div>
                            @if ($version->is_current)
                                <x-ta.pill tone="ok">Kullanımda</x-ta.pill>
                            @else
                                <button type="button" wire:click="revertTo({{ $version->version }})" wire:confirm="v{{ $version->version }} yeni sürüm olarak yayınlansın mı?" class="{{ $rowBtn }}">Bu sürüme dön</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="min-w-0" data-prompt-runs>
                <div class="section-head"><h2 class="section-title">Son çalıştırmalar</h2></div>
                <div class="card overflow-x-auto">
                    <table class="data-table">
                        <tbody>
                            @forelse ($detail['runs'] as $run)
                                <tr>
                                    <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($run['at'])->format('d.m.Y H:i') }}</td>
                                    <td class="text-xs text-gray-500">{{ $run['version'] !== null ? 'v'.$run['version'] : '—' }}</td>
                                    <td class="text-right tabular-nums">{{ $sec($run['duration_ms']) }}</td>
                                    <td class="text-right tabular-nums">{{ $usd($run['cost']) }}</td>
                                    <td class="text-right"><x-ta.pill :tone="$run['status'] === 'ok' ? 'ok' : 'critical'">{{ $run['status'] === 'ok' ? 'Başarılı' : 'Hata' }}</x-ta.pill></td>
                                </tr>
                            @empty
                                <tr><td class="text-gray-500">Çalıştırma yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif
</div>
