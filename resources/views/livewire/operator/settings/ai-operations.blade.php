@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 3 : 2, ',', '.');
    $sec = fn (?int $ms): string => $ms === null ? '—' : number_format($ms / 1000, 1, ',', '.').' sn';
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
@endphp
<div class="space-y-5">
    <h1 class="text-2xl font-bold text-gray-800 dark:text-white/90">AI işlemleri</h1>

    @if (session('status'))
        <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @if ($detail === null)
        @php
            $busy = $live['running']->isNotEmpty() || $live['queued']->isNotEmpty();
            $who = fn ($item): string => $item->user_id && isset($live['users'][$item->user_id]) ? $live['users'][$item->user_id] : 'Otomatik';
            $where = fn ($item): array => array_values(array_filter(array_map('trim', explode(' · ', (string) $item->subject))));
        @endphp
        <div class="space-y-5" data-ai-live @if ($busy) wire:poll.5s @else wire:poll.30s @endif>
            <p class="text-sm text-gray-500">Şu an AI nerede ne yapıyor, sırada ne var, otomatik olarak ne zaman ne çalışacak. Liste kendiliğinden yenilenir.</p>

            {{-- Özet --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-ai-summary>
                <div class="{{ $card }} px-4 py-3">
                    <p class="text-xs text-gray-500">Şu an çalışan</p>
                    <p @class(['text-2xl font-bold tabular-nums', 'text-violet-700 dark:text-violet-300' => $live['running']->isNotEmpty(), 'text-gray-800 dark:text-white/90' => $live['running']->isEmpty()])>{{ $live['running']->count() }}</p>
                </div>
                <div class="{{ $card }} px-4 py-3">
                    <p class="text-xs text-gray-500">Sırada bekleyen</p>
                    <p class="text-2xl font-bold tabular-nums text-gray-800 dark:text-white/90">{{ $live['queued']->count() }}</p>
                </div>
                <div class="{{ $card }} px-4 py-3">
                    <p class="text-xs text-gray-500">Bugün biten</p>
                    <p class="text-2xl font-bold tabular-nums text-gray-800 dark:text-white/90">{{ $live['today']['done'] }}@if ($live['today']['failed'] > 0) <span class="text-sm font-semibold text-rose-600">· {{ $live['today']['failed'] }} hata</span>@endif</p>
                </div>
                <div class="{{ $card }} px-4 py-3" data-ai-remaining>
                    <p class="text-xs text-gray-500">Maliyet · bugün / bu ay</p>
                    <p class="text-2xl font-bold tabular-nums text-gray-800 dark:text-white/90">{{ $usd($live['today']['cost']) }} <span class="text-sm font-normal text-gray-500">/ {{ $usd($monthSpend) }}</span></p>
                    <p @class(['text-xs', 'text-rose-600' => $remaining <= 0, 'text-gray-500' => $remaining > 0])>Kalan bakiye: {{ $usd($remaining) }} (bütçe {{ $usd($monthlyBudget) }})</p>
                    <p @class(['text-xs', 'font-semibold text-rose-600' => $autoBudget > 0 && $autoSpend >= $autoBudget, 'text-gray-500' => ! ($autoBudget > 0 && $autoSpend >= $autoBudget)]) data-ai-auto-budget>
                        Bugün tüm AI: {{ $usd($autoSpend) }} / {{ $autoBudget > 0 ? $usd($autoBudget) : 'sınırsız' }}@if ($autoBudget > 0 && $autoSpend >= $autoBudget) · tavan doldu, ücretli AI yarına kadar durdu (Claude işleri sürer) @endif · otomatik çalışan: Sorgular ve Claude'a devredilen işler
                    </p>
                    @if ($quota['enabled'])
                        <p class="text-xs text-emerald-700 dark:text-emerald-400" data-openai-quota>
                            OpenAI ücretsiz kota (UTC günü): {{ collect($quota['tiers'])->map(fn (array $tier): string => $tier['label'].' '.number_format($tier['used'] / 1000, 0, ',', '.').'k / '.number_format($tier['limit'] / 1000, 0, ',', '.').'k')->implode(' · ') }} · bugün kazanılan {{ $usd($quota['saved']) }}
                        </p>
                    @endif
                    @if (($audit['status'] ?? null) === 'mismatch')
                        <p class="text-xs font-semibold text-rose-600" data-openai-audit-alert>{{ $audit['message'] }}</p>
                    @endif
                </div>
            </div>

            {{-- Canlı --}}
            <section id="canli" class="{{ $card }} p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Canlı · şu an çalışıyor</h2>
                    <a href="{{ route('operator.ai-jobs') }}" wire:navigate class="text-xs font-semibold text-brand-600" data-ai-jobs-link>AI işleri: tümü, ayrıntı, durdur / sil →</a>
                </div>
                @forelse ($live['running'] as $item)
                    @php($step = $live['steps'][$item->id] ?? null)
                    <div class="mt-3 rounded-lg border border-violet-200 bg-violet-50/60 p-3 dark:border-violet-500/30 dark:bg-violet-500/10" wire:key="run-{{ $item->id }}" data-ai-live-running>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white">
                                    <span class="mr-1 inline-block h-2 w-2 animate-pulse rounded-full bg-violet-500 align-middle"></span>{{ $item->label }}
                                    @if ($item->cancelRequested())<span class="ml-1 text-xs font-medium text-amber-700">durduruluyor…</span>@endif
                                </p>
                                <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">
                                    <span class="text-gray-500">Nerede:</span>
                                    @if ($where($item) !== []){{ implode(' › ', $where($item)) }}@else<span class="text-gray-400">genel (marka dışı)</span>@endif
                                    @if ($item->link)<a href="{{ $item->link }}" wire:navigate class="ml-1 text-xs font-semibold text-brand-600">aç →</a>@endif
                                </p>
                                @if ($step !== null)
                                    <p class="text-sm text-gray-700 dark:text-gray-300" data-ai-step><span class="text-gray-500">Adım:</span> {{ $step['running'] ? $step['label'] : 'sonraki AI çağrısına hazırlanıyor' }}
                                        <span class="text-xs text-gray-500">· {{ $step['done'] }} çağrı bitti {{ $step['model'] !== '' ? '· '.$step['model'] : '' }}</span></p>
                                @elseif ($item->model)
                                    <p class="text-xs text-gray-500">Model: {{ $item->model }}</p>
                                @endif
                                <p class="text-xs text-gray-500">Başlatan: {{ $who($item) }} · başladı {{ $item->started_at?->timezone('Europe/Istanbul')->format('H:i:s') }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1 text-right">
                                <span class="text-lg font-semibold tabular-nums text-violet-700 dark:text-violet-300">{{ $item->durationLabel() }}</span>
                                <span class="text-xs tabular-nums text-gray-500">{{ $usd($step['cost'] ?? $item->cost_usd) }}</span>
                                <div class="flex gap-1">
                                    <a href="{{ route('operator.ai-jobs', ['is' => $item->id]) }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-white dark:text-gray-300 dark:ring-gray-700">Ayrıntı</a>
                                    @if (! $item->cancelRequested())<button type="button" wire:click="stop({{ $item->id }})" wire:confirm="Bu AI işi durdurulsun mu?" class="rounded-lg px-2 py-1 text-xs font-medium text-rose-700 ring-1 ring-inset ring-rose-300 hover:bg-white" data-ai-stop>Durdur</button>@endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-gray-500" data-ai-idle>Şu an AI çalışmıyor.@if (($schedule[0]['next'] ?? null) !== null) Sıradaki otomatik iş: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $schedule[0]['label'] }}</span> · {{ $schedule[0]['next']->format('d.m H:i') }}.@endif</p>
                @endforelse

                @if ($live['queued']->isNotEmpty())
                    <h3 class="mt-4 text-xs font-semibold uppercase text-gray-400">Sırada ({{ $live['queued']->count() }})</h3>
                    <ul class="mt-1 divide-y divide-gray-100 text-sm dark:divide-gray-700" data-ai-queued>
                        @foreach ($live['queued'] as $item)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-1.5" wire:key="queued-{{ $item->id }}">
                                <span class="min-w-0"><span class="font-medium text-gray-800 dark:text-white/90">{{ $item->label }}</span>
                                    @if ($where($item) !== [])<span class="text-gray-500"> · {{ implode(' › ', $where($item)) }}</span>@endif
                                    <span class="text-xs text-gray-400"> · {{ $who($item) }} · {{ $item->queued_at?->timezone('Europe/Istanbul')->format('H:i') }}'dan beri bekliyor</span></span>
                                <button type="button" wire:click="stop({{ $item->id }})" class="text-xs font-medium text-rose-700">Kaldır</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- AI iş kuyruğu (Claude MCP) --}}
            @if ($mcpTasks !== null)
                <section class="{{ $card }} p-5" data-ai-tasks>
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Claude (MCP) iş kuyruğu</h2>
                    <p class="mt-1 text-xs text-gray-500">"Claude (MCP)" seçilen işlemler API'yi çağırmaz; Claude kuyruğu çekip sonucu yazınca iş devam eder.</p>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Claude bekleniyor: <span class="font-semibold tabular-nums">{{ $mcpTasks['open'] }}</span> · Son 7 günde biten: <span class="tabular-nums">{{ $mcpTasks['done'] }}</span> · Yapılamayan: <span class="tabular-nums">{{ $mcpTasks['failed'] }}</span></p>
                    @if ($mcpTasks['recent']->isNotEmpty())
                        <ul class="mt-3 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                            @foreach ($mcpTasks['recent'] as $task)
                                <li class="flex flex-wrap justify-between gap-2 py-1.5">
                                    <span class="text-gray-700 dark:text-gray-300">{{ \App\Support\Ai\AiOperationLabels::for($task->operation) }} · {{ $task->subject ?? $task->brand?->name ?? '—' }}</span>
                                    <span class="{{ $task->status === \App\Models\AiTask::FAILED ? 'text-rose-600' : 'text-gray-500' }}" title="{{ $task->error }}">{{ \App\Models\AiTask::STATUS_LABELS[$task->status] ?? $task->status }} · {{ $task->created_at?->diffForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            {{-- Harcama nereye gitti --}}
            <section class="{{ $card }} p-5" data-ai-costs>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Harcama nereye gitti</h2>
                    <div class="flex gap-1 text-xs">
                        <button type="button" wire:click="$set('costHours', 24)" @class(['rounded-lg px-2 py-1', 'bg-brand-500 text-white' => $costHours === 24, 'ring-1 ring-inset ring-gray-300 text-gray-600' => $costHours !== 24])>24 saat</button>
                        <button type="button" wire:click="$set('costHours', 168)" @class(['rounded-lg px-2 py-1', 'bg-brand-500 text-white' => $costHours === 168, 'ring-1 ring-inset ring-gray-300 text-gray-600' => $costHours !== 168])>7 gün</button>
                    </div>
                </div>
                @php($costTotal = array_sum(array_column($costs, 'cost')))
                <table class="mt-2 w-full text-sm">
                    <thead class="text-left text-xs text-gray-400"><tr><th class="py-1">İşlem</th><th class="text-right">Çağrı</th><th class="text-right">Maliyet</th><th class="text-right">Pay</th><th class="text-right">Otomatik</th><th class="pl-3">Model</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($costs as $cost)
                            <tr wire:key="cost-{{ $cost['operation'] }}">
                                <td class="py-1.5">{{ $cost['label'] }}</td>
                                <td class="text-right tabular-nums">{{ $cost['calls'] }}</td>
                                <td class="text-right tabular-nums font-medium">{{ $usd($cost['cost']) }}</td>
                                <td class="text-right tabular-nums text-gray-500">{{ $costTotal > 0 ? round($cost['cost'] / $costTotal * 100) : 0 }}%</td>
                                <td class="text-right tabular-nums text-gray-500">{{ $usd($cost['auto_cost']) }}</td>
                                <td class="pl-3 text-xs text-gray-500">{{ $cost['model'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-gray-500">Bu sürede AI harcaması yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>

            <div class="grid gap-5 lg:grid-cols-3">
                {{-- Son 24 saat --}}
                <section class="{{ $card }} p-5 lg:col-span-2" data-ai-finished>
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Son 24 saat</h2>
                    <table class="mt-2 w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($live['finished'] as $item)
                                <tr wire:key="done-{{ $item->id }}">
                                    <td class="py-1.5 pr-2 align-top">
                                        <span @class(['inline-block h-2 w-2 rounded-full', 'bg-emerald-500' => $item->status === 'done', 'bg-rose-500' => $item->status === 'failed', 'bg-gray-400' => ! in_array($item->status, ['done', 'failed'], true)])></span>
                                    </td>
                                    <td class="py-1.5 pr-3">
                                        <a href="{{ route('operator.ai-jobs', ['is' => $item->id]) }}" wire:navigate class="font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ $item->label }}</a>
                                        @if ($where($item) !== [])<span class="text-xs text-gray-500"> · {{ implode(' › ', $where($item)) }}</span>@endif
                                        @if ($item->status !== 'done' && $item->error)<p class="text-xs text-rose-600">{{ $item->error }}</p>@endif
                                    </td>
                                    <td class="whitespace-nowrap py-1.5 pr-3 text-xs text-gray-500">{{ $item->finished_at?->timezone('Europe/Istanbul')->format('H:i') }} · {{ $who($item) }}</td>
                                    <td class="whitespace-nowrap py-1.5 pr-3 text-right text-xs tabular-nums text-gray-500">{{ $item->statusLabel() }} · {{ $item->durationLabel() }}</td>
                                    <td class="py-1.5 text-right text-xs tabular-nums">{{ $item->cost_usd !== null ? $usd($item->cost_usd) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td class="py-2 text-gray-500">Son 24 saatte AI işi yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </section>

                {{-- Otomatik takvim --}}
                <section class="{{ $card }} p-5" data-ai-schedule>
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Otomatik takvim</h2>
                    <p class="text-xs text-gray-500">Kimse tıklamadan AI çalıştıran işler.</p>
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach ($schedule as $task)
                            <li wire:key="sched-{{ $task['name'] }}">
                                <p class="flex justify-between gap-2"><span class="font-medium text-gray-800 dark:text-white/90">{{ $task['label'] }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-gray-500">{{ $task['next']?->format('d.m H:i') ?? '—' }}</span></p>
                                <p class="text-xs text-gray-500">{{ $task['what'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </div>
        </div>

        {{-- GPT / Claude API / Claude abonelik dağılımı ve yüklenen kredi --}}
        <section class="{{ $card }} space-y-4 p-5" data-ai-credits>
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">AI dağılımı ve kredi</h2>
                <p class="text-xs text-gray-500">Her AI işlemi üç yoldan biriyle çalışır: GPT (OpenAI), Claude API (yüklediğin krediden düşer) veya Claude abonelik (MCP kuyruğu). Claude API kredisi biter ya da günlük tavan dolarsa, kuyruğu destekleyen işler kendiliğinden aboneliğe geçer.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($credits as $credit)
                    <div wire:key="credit-{{ $credit['provider'] }}" @class(['rounded-xl border p-3', 'border-red-300 bg-red-50 dark:bg-red-500/10' => $credit['tracked'] && $credit['exhausted'], 'border-amber-300 bg-amber-50 dark:bg-amber-500/10' => $credit['low'], 'border-gray-200 dark:border-gray-700' => ! $credit['low'] && ! ($credit['tracked'] && $credit['exhausted'])]) data-credit="{{ $credit['provider'] }}">
                        <p class="text-xs text-gray-500">{{ $credit['label'] }} kredisi</p>
                        @if ($credit['tracked'])
                            <p class="text-lg font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $usd($credit['remaining']) }} <span class="text-xs font-normal text-gray-500">kaldı</span></p>
                            <p class="text-xs text-gray-500">Yüklenen {{ $usd($credit['loaded']) }} · harcanan {{ $usd($credit['spent']) }}</p>
                            @if ($credit['exhausted'])
                                <p class="text-xs font-medium text-red-600">Kredi bitti; bu sağlayıcıya ücretli çağrı başlamaz.</p>
                            @elseif ($credit['low'])
                                <p class="text-xs font-medium text-amber-700">Kredi azaldı.</p>
                            @endif
                        @else
                            <p class="text-sm text-gray-500">Kredi girilmedi; yalnız aylık bütçe ve günlük tavan uygulanır.</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <form wire:submit="topUpCredit" class="flex flex-wrap items-end gap-3 text-sm" data-credit-topup>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-gray-500">Sağlayıcı</span>
                    <select wire:model="creditProvider" class="rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                        @foreach (\App\Services\Ai\AiCredits::PROVIDERS as $provider)
                            <option value="{{ $provider }}">{{ \App\Services\Ai\AiCredits::label($provider) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-gray-500">Yüklenen kredi (USD)</span>
                    <input type="number" step="1" min="1" wire:model="creditAmount" class="w-28 rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-gray-500">Not (isteğe bağlı)</span>
                    <input type="text" maxlength="190" wire:model="creditNote" class="w-56 rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                </label>
                <button type="submit" class="rounded-lg bg-brand-500 px-3 py-1.5 text-white">Kredi ekle</button>
                @error('creditAmount')<span class="w-full text-xs text-red-600">{{ $message }}</span>@enderror
                <span class="w-full text-xs text-gray-500">Kalan = yüklenen − ilk yüklemeden beri kaydedilen maliyet. Sağlayıcı panelindeki bakiyeyle arada küçük fark olabilir.</span>
            </form>
            <div class="space-y-2 text-sm" data-ai-plans>
                <p class="text-xs text-gray-500">Şu an: @foreach ($kinds as $kind => $count)<span class="mr-2">{{ \App\Services\Ai\AiAssignments::kindLabel($kind) }} {{ $count }}</span>@endforeach</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($plans as $plan => $planLabel)
                        <button type="button" wire:key="plan-{{ $plan }}" wire:click="assignPlan('{{ $plan }}')" wire:confirm="{{ $planLabel }} uygulansın mı? Değişen her işlem için yeni prompt sürümü açılır; sorgu pilotu ve WhatsApp yerinde kalır." class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 hover:border-brand-500 dark:border-gray-700 dark:text-gray-300" data-plan="{{ $plan }}">{{ $planLabel }}</button>
                    @endforeach
                </div>
            </div>
        </section>

        <details class="{{ $card }} px-5 py-3 text-sm" data-ai-budget>
            <summary class="cursor-pointer font-semibold text-gray-800 dark:text-white/90">AI bütçesi · aylık {{ $usd($monthlyBudget) }} · günlük tavan (tüm AI) {{ $autoBudget > 0 ? $usd($autoBudget) : 'sınırsız' }} · Kalan bakiye {{ $usd($remaining) }}</summary>
            <form wire:submit="saveBudget" class="mt-2 flex flex-wrap items-end gap-3">
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-gray-500">Aylık AI bütçesi (USD)</span>
                    <input type="number" step="1" min="0" wire:model="budget" class="w-32 rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-gray-500">Günlük AI tavanı, tüm işler (USD)</span>
                    <input type="number" step="0.5" min="0" wire:model="dailyAutoBudget" class="w-32 rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900" data-daily-auto-budget>
                </label>
                <label class="flex w-full items-start gap-2 text-xs text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="openAiFreeQuota" class="mt-0.5 rounded border-gray-300" data-openai-free-quota>
                    <span><strong>OpenAI ücretsiz paylaşım kotası açık.</strong> OpenAI › Veri kontrolleri › «Giriş ve çıkışları OpenAI ile paylaşın» açıkken günde küçük modellerde 2,5 milyon, büyüklerde 250 bin token ücretsizdir. Bunun %90'ı kadar token harcama sayılmaz; aşan kısım liste fiyatından sayılır.</span>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs text-gray-500">OpenAI Admin anahtarı (kota denetimi için){{ $adminKeySet ? ' · kayıtlı' : '' }}</span>
                    <input type="password" autocomplete="off" wire:model="openAiAdminKey" placeholder="{{ $adminKeySet ? '•••••••• (değiştirmek için yaz)' : 'sk-admin-…' }}" class="w-72 rounded-lg border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900" data-openai-admin-key>
                </label>
                <button type="submit" class="rounded-lg bg-brand-500 px-3 py-1.5 text-white">Kaydet</button>
                <button type="button" wire:click="auditOpenAi" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300" data-openai-audit-now>Şimdi denetle</button>
                <div class="w-full text-xs text-gray-500" data-openai-audit>
                    Kota denetimi saatte bir OpenAI'ın gerçek faturasını (Admin anahtarıyla) bizim tahminimizle karşılaştırır. Dün OpenAI belirgin şekilde fazla faturaladıysa kota hesabını kendisi kapatır. Bugünkü gerçek fatura, günlük tavan için alt sınır sayılır.
                    @if ($audit !== null)
                        <br>Son denetim {{ \Carbon\CarbonImmutable::parse($audit['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}: {{ $audit['message'] ?? $audit['status'] }}
                        @foreach (($audit['days'] ?? []) as $day)
                            <br>{{ $day['date'] }} · OpenAI {{ $usd($day['actual']) }} · tahmin {{ $usd($day['estimated']) }} · liste fiyatı {{ $usd($day['list']) }}
                        @endforeach
                    @endif
                </div>
                <span class="w-full text-xs text-gray-500">Aylık bakiye bitince ay sonuna kadar yalnız ücretsiz modeller çalışır. Günün (İstanbul saati) tüm AI harcaması tavana ulaşınca, tıkladığın işler dahil hiçbir ücretli AI çağrısı başlamaz; yarın yeniden çalışır. Kimse tıklamadan Sorgular alanındaki AI (sorgu pilotu, kümeleme) Claude aboneliğine devredilen işler ve Claude API'ye atanmış işler çalışır; abonelik işleri tavana ve bütçeye sayılmaz, Claude API işleri krediden, tavandan ve bütçeden düşer. GPT'de kalan analistler, bakım ajanı ve Şef yalnız tıklayınca çalışır. 0 = tavan yok.</span>
                @error('dailyAutoBudget')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                @error('budget')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
            </form>
        </details>

        <details class="{{ $card }} overflow-x-auto" data-ai-operations>
            <summary class="cursor-pointer px-5 py-3 text-sm font-semibold text-gray-800 dark:text-white/90">Promptlar ve modeller · {{ count($rows) }} AI işlemi <span class="font-normal text-gray-500">(talimatı görmek / düzenlemek için aç)</span></summary>
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400">
                    <tr>
                        <th class="px-5 py-2">İşlem</th>
                        <th class="px-3 py-2">Model</th>
                        <th class="px-3 py-2 text-right">Sürüm</th>
                        <th class="px-3 py-2 text-right">30 gün</th>
                        <th class="px-3 py-2 text-right">Ort. süre</th>
                        <th class="px-5 py-2 text-right">Maliyet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach (collect($rows)->sortByDesc('runs') as $row)
                        <tr wire:key="op-{{ $row['operation'] }}">
                            <td class="px-5 py-2">
                                <button type="button" wire:click="open('{{ $row['operation'] }}')" class="text-left font-medium text-gray-800 hover:text-brand-600 dark:text-white/90">{{ \App\Support\Ai\AiOperationLabels::for($row['operation']) }}</button>
                                <span class="ml-1 font-mono text-xs text-gray-400">{{ $row['operation'] }}</span>
                                <p class="text-xs text-gray-500">{{ $row['purpose'] }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><span class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ \App\Services\Ai\AiAssignments::kindLabel($row['kind']) }}</span>{{ $row['model'] !== '' ? $row['model'] : 'Rota modeli' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['version'] !== null ? 'v'.$row['version'] : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['runs'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $sec($row['avg_ms']) }}</td>
                            <td class="px-5 py-2 text-right tabular-nums">{{ $usd($row['cost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @else
        @php($definition = $detail['definition'])
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <button type="button" wire:click="close" class="text-sm text-gray-500 hover:text-brand-600">← İşlemler</button>
                <h2 class="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{{ $operation }} <span class="text-sm text-gray-400">v{{ $detail['current']->version }}</span></h2>
                <p class="text-sm text-gray-500">{{ $detail['current']->purpose ?: $definition['purpose'] }}</p>
            </div>
        </div>

        <form wire:submit="save" class="{{ $card }} space-y-4 p-5" data-prompt-editor>
            <div>
                <label for="prompt-template" class="text-xs font-semibold text-gray-600 dark:text-gray-300">Şablon</label>
                <textarea id="prompt-template" wire:model="template" rows="18" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
                @error('template')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-500">Sabit cümle her sürüme kodla eklenir: “{{ \App\Services\Prompts\PromptRegistry::GUARD }}”</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Değişkenler</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @forelse ($definition['variables'] as $variable)
                            <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">&#123;&#123;{{ $variable }}&#125;&#125;</span>
                        @empty
                            <span class="text-xs text-gray-500">Yok</span>
                        @endforelse
                    </div>
                </div>
                <div>
                    <label for="prompt-model" class="text-xs font-semibold text-gray-600 dark:text-gray-300">Model</label>
                    <select id="prompt-model" wire:model="model" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm dark:border-gray-700 dark:text-white/90">
                        @foreach ($detail['models'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Bağlam kaynakları</p>
                <ul class="mt-1 list-inside list-disc text-xs text-gray-600 dark:text-gray-300">
                    @foreach ($definition['context_sources'] as $source)<li>{{ $source }}</li>@endforeach
                </ul>
            </div>
            <details>
                <summary class="cursor-pointer text-xs font-semibold text-gray-600 dark:text-gray-300">Çıktı yapısı</summary>
                <pre class="mt-1 max-h-80 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ json_encode($definition['output_schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">Yeni sürüm olarak kaydet</button>
                <button type="button" wire:click="resetDefault" wire:confirm="Kodun varsayılan promptu yeni sürüm olarak yayınlansın mı?" class="rounded-lg px-4 py-2 text-sm text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Varsayılana dön</button>
            </div>
        </form>

        <section class="{{ $card }} space-y-3 p-5" data-prompt-trial @if (($detail['trial']['status'] ?? null) === 'running') wire:poll.3s @endif>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Örnekte dene</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach ($detail['samples'] as $sample)
                        <button type="button" wire:click="useSample({{ $sample['id'] }})" wire:key="sample-{{ $sample['id'] }}" class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($sample['at'])->format('d.m H:i') }}{{ $sample['version'] !== null ? ' · v'.$sample['version'] : '' }}</button>
                    @endforeach
                </div>
            </div>
            <textarea wire:model="trialInput" rows="5" placeholder="Son çalıştırmalardan birini seçin ya da örnek girdi yapıştırın." class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
            @error('trial')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            <div class="flex items-center gap-3">
                <button type="button" wire:click="runTrial" wire:loading.attr="disabled" @disabled(($detail['trial']['status'] ?? null) === 'running') class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-gray-700 disabled:opacity-50 dark:bg-white dark:text-gray-900">Taslağı dene</button>
                <span class="text-xs text-gray-500">Yayınlanmamış şablon · öneri kaydedilmez</span>
            </div>
            @if ($detail['trial'] !== null)
                @if ($detail['trial']['status'] === 'running')
                    <p class="text-sm text-gray-500">Çalışıyor…</p>
                @elseif ($detail['trial']['status'] === 'failed')
                    <p class="text-sm text-rose-600">{{ $detail['trial']['message'] }}</p>
                @else
                    <p class="text-xs text-gray-500">{{ $detail['trial']['model'] }} · {{ $sec($detail['trial']['duration_ms']) }} · {{ $usd($detail['trial']['cost']) }}</p>
                    <pre class="max-h-96 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $detail['trial']['output'] }}</pre>
                @endif
            @endif
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="{{ $card }} p-5" data-prompt-versions>
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sürüm geçmişi</h3>
                <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($detail['versions'] as $version)
                        <li class="flex items-center justify-between gap-2 py-2" wire:key="v-{{ $version->id }}">
                            <span>
                                v{{ $version->version }} · {{ $version->created_at?->format('d.m.Y H:i') }} · {{ $version->creator?->name ?? 'Sistem' }}
                                @if ($version->model)<span class="text-xs text-gray-500">· {{ $version->model }}</span>@endif
                            </span>
                            @if ($version->is_current)
                                <span class="text-xs font-medium text-emerald-600">Kullanımda</span>
                            @else
                                <button type="button" wire:click="revertTo({{ $version->version }})" wire:confirm="v{{ $version->version }} yeni sürüm olarak yayınlansın mı?" class="text-xs font-medium text-brand-600 hover:underline">Bu sürüme dön</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
            <section class="{{ $card }} p-5" data-prompt-runs>
                <h3 class="text-sm font-semibold text-gray-800 dark:text-white/90">Son çalıştırmalar</h3>
                <table class="mt-2 w-full text-sm">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($detail['runs'] as $run)
                            <tr>
                                <td class="py-2">{{ \Illuminate\Support\Carbon::parse($run['at'])->format('d.m.Y H:i') }}</td>
                                <td class="py-2 text-xs text-gray-500">{{ $run['version'] !== null ? 'v'.$run['version'] : '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $sec($run['duration_ms']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $usd($run['cost']) }}</td>
                                <td @class(['py-2 text-right text-xs', 'text-emerald-600' => $run['status'] === 'ok', 'text-rose-600' => $run['status'] !== 'ok'])>{{ $run['status'] === 'ok' ? 'Başarılı' : 'Hata' }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-gray-500">Çalıştırma yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    @endif
</div>
