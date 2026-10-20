@php
    $usd = fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, $value > 0 && $value < 1 ? 3 : 2, ',', '.');
    $sec = fn (?int $ms): string => $ms === null ? '—' : number_format($ms / 1000, 1, ',', '.').' sn';
@endphp
<div class="space-y-10">
    <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">AI işlemleri</h1>

    @if (session('status'))
        <p class="tone-ok rounded-lg px-4 py-2 text-sm">{{ session('status') }}</p>
    @endif

    @if ($detail === null)
        @php
            $busy = $live['running']->isNotEmpty() || $live['queued']->isNotEmpty();
            $who = fn ($item): string => $item->user_id && isset($live['users'][$item->user_id]) ? $live['users'][$item->user_id] : 'Otomatik';
            $where = fn ($item): array => array_values(array_filter(array_map('trim', explode(' · ', (string) $item->subject))));
        @endphp
        <div class="space-y-10" data-ai-live title="Liste kendiliğinden yenilenir." @if ($busy) wire:poll.5s @else wire:poll.30s @endif>
            {{-- Özet --}}
            <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-4" data-ai-summary>
                <x-ta.metric-card label="Şu an çalışan" :value="$live['running']->count()" />
                <x-ta.metric-card label="Sırada bekleyen" :value="$live['queued']->count()" />
                <x-ta.metric-card label="Bugün biten" :value="$live['today']['done']" :delta="$live['today']['failed'] > 0 ? $live['today']['failed'].' hata' : null" tone="critical" />
                <div class="col-span-2 flex min-w-0 flex-col gap-1 py-1 sm:col-span-3 lg:col-span-1" data-ai-remaining>
                    <p class="text-[26px] leading-8 font-semibold tracking-tight tabular-nums text-gray-900 dark:text-white">{{ $usd($live['today']['cost']) }} <span class="text-sm font-normal text-gray-500">/ {{ $usd($monthSpend) }}</span></p>
                    <p class="text-xs text-gray-500">Maliyet, bugün / bu ay</p>
                    <p @class(['text-xs', 'text-error-600' => $remaining <= 0, 'text-gray-500' => $remaining > 0])>Kalan bakiye: {{ $usd($remaining) }} (bütçe {{ $usd($monthlyBudget) }})</p>
                    <p @class(['text-xs', 'font-semibold text-error-600' => $autoBudget > 0 && $autoSpend >= $autoBudget, 'text-gray-500' => ! ($autoBudget > 0 && $autoSpend >= $autoBudget)]) data-ai-auto-budget>
                        Bugün tüm AI: {{ $usd($autoSpend) }} / {{ $autoBudget > 0 ? $usd($autoBudget) : 'sınırsız' }}@if ($autoBudget > 0 && $autoSpend >= $autoBudget), tavan doldu, ücretli AI yarına kadar durdu (Claude işleri sürer) @endif
                    </p>
                    @if ($quota['enabled'])
                        <p class="text-xs text-gray-500" data-openai-quota>
                            OpenAI ücretsiz kota (UTC günü): {{ collect($quota['tiers'])->map(fn (array $tier): string => $tier['label'].' '.number_format($tier['used'] / 1000, 0, ',', '.').'k / '.number_format($tier['limit'] / 1000, 0, ',', '.').'k')->implode(' · ') }} · bugün kazanılan {{ $usd($quota['saved']) }}
                        </p>
                    @endif
                    @if (($audit['status'] ?? null) === 'mismatch')
                        <p class="text-xs font-semibold text-error-600" data-openai-audit-alert>{{ $audit['message'] }}</p>
                    @endif
                </div>
            </div>

            {{-- Canlı --}}
            <section id="canli">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="section-title">Canlı <span class="text-sm font-normal text-gray-500">şu an çalışıyor</span></h2>
                    <a href="{{ route('operator.ai-jobs') }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-900 dark:hover:text-white" data-ai-jobs-link title="Tümü, ayrıntı, durdur / sil">AI işleri</a>
                </div>
                @forelse ($live['running'] as $item)
                    @php($step = $live['steps'][$item->id] ?? null)
                    <div class="border-b border-gray-200 py-3 dark:border-gray-800" wire:key="run-{{ $item->id }}" data-ai-live-running>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-white">
                                    <span class="mr-1 inline-block h-2 w-2 animate-pulse rounded-full bg-live-500 align-middle"></span>{{ $item->label }}
                                    @if ($item->cancelRequested())<span class="ml-1 text-xs font-medium text-warning-700">durduruluyor…</span>@endif
                                </p>
                                <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">
                                    <span class="text-gray-500">Nerede:</span>
                                    @if ($where($item) !== []){{ implode(' › ', $where($item)) }}@else<span class="text-gray-500">genel (marka dışı)</span>@endif
                                    @if ($item->link)<a href="{{ $item->link }}" wire:navigate class="link-neutral ml-1 text-xs">aç</a>@endif
                                </p>
                                @if ($step !== null)
                                    <p class="text-sm text-gray-700 dark:text-gray-300" data-ai-step><span class="text-gray-500">Adım:</span> {{ $step['running'] ? $step['label'] : 'sonraki AI çağrısına hazırlanıyor' }}
                                        <span class="text-xs text-gray-500">, {{ $step['done'] }} çağrı bitti {{ $step['model'] !== '' ? '· '.$step['model'] : '' }}</span></p>
                                @elseif ($item->model)
                                    <p class="text-xs text-gray-500">Model: {{ $item->model }}</p>
                                @endif
                                <p class="text-xs text-gray-500">Başlatan: {{ $who($item) }}, başladı {{ $item->started_at?->timezone('Europe/Istanbul')->format('H:i:s') }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1 text-right">
                                <span class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">{{ $item->durationLabel() }}</span>
                                <span class="text-xs tabular-nums text-gray-500">{{ $usd($step['cost'] ?? $item->cost_usd) }}</span>
                                <div class="flex gap-1">
                                    <a href="{{ route('operator.ai-jobs', ['is' => $item->id]) }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Ayrıntı</a>
                                    @if (! $item->cancelRequested())<button type="button" wire:click="stop({{ $item->id }})" wire:confirm="Bu AI işi durdurulsun mu?" class="rounded-lg px-2 py-1 text-xs font-medium text-error-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:ring-gray-700" data-ai-stop>Durdur</button>@endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-gray-500" data-ai-idle>Şu an AI çalışmıyor.@if (($schedule[0]['next'] ?? null) !== null) Sıradaki otomatik iş: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $schedule[0]['label'] }}</span>, {{ $schedule[0]['next']->format('d.m H:i') }}.@endif</p>
                @endforelse

                @if ($live['queued']->isNotEmpty())
                    <h3 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">Sırada ({{ $live['queued']->count() }})</h3>
                    <ul class="mt-1 divide-y divide-gray-200 text-sm dark:divide-gray-700" data-ai-queued>
                        @foreach ($live['queued'] as $item)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2.5" wire:key="queued-{{ $item->id }}">
                                <span class="min-w-0"><span class="font-medium text-gray-800 dark:text-white/90">{{ $item->label }}</span>
                                    @if ($where($item) !== [])<span class="text-gray-500"> · {{ implode(' › ', $where($item)) }}</span>@endif
                                    <span class="text-xs text-gray-500">, {{ $who($item) }}, {{ $item->queued_at?->timezone('Europe/Istanbul')->format('H:i') }}'dan beri bekliyor</span></span>
                                <button type="button" wire:click="stop({{ $item->id }})" class="text-xs font-medium text-error-700">Kaldır</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- AI iş kuyruğu (Claude MCP) --}}
            @if ($mcpTasks !== null)
                <section data-ai-tasks>
                    <h2 class="section-title" title="&quot;Claude (MCP)&quot; seçilen işlemler API'yi çağırmaz; Claude kuyruğu çekip sonucu yazınca iş devam eder.">Claude (MCP) iş kuyruğu</h2>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Claude bekleniyor: <span class="font-semibold tabular-nums">{{ $mcpTasks['open'] }}</span>, son 7 günde biten: <span class="tabular-nums">{{ $mcpTasks['done'] }}</span>, yapılamayan: <span class="tabular-nums">{{ $mcpTasks['failed'] }}</span></p>
                    @if ($mcpTasks['recent']->isNotEmpty())
                        <ul class="mt-3 divide-y divide-gray-200 text-xs dark:divide-gray-800">
                            @foreach ($mcpTasks['recent'] as $task)
                                <li class="flex flex-wrap justify-between gap-2 py-2.5">
                                    <span class="text-gray-700 dark:text-gray-300">{{ \App\Support\Ai\AiOperationLabels::for($task->operation) }} · {{ $task->subject ?? $task->brand?->name ?? '—' }}</span>
                                    <span class="{{ $task->status === \App\Models\AiTask::FAILED ? 'text-error-600' : 'text-gray-500' }}" title="{{ $task->error }}">{{ \App\Models\AiTask::STATUS_LABELS[$task->status] ?? $task->status }}, {{ $task->created_at?->diffForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            {{-- Harcama nereye gitti --}}
            <section data-ai-costs>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="section-title">Harcama nereye gitti</h2>
                    <div class="flex gap-1 text-xs">
                        <button type="button" wire:click="$set('costHours', 24)" @class(['rounded-lg px-2 py-1', 'bg-gray-100 font-medium text-gray-900 dark:bg-gray-800 dark:text-white' => $costHours === 24, 'text-gray-500 hover:text-gray-900' => $costHours !== 24])>24 saat</button>
                        <button type="button" wire:click="$set('costHours', 168)" @class(['rounded-lg px-2 py-1', 'bg-gray-100 font-medium text-gray-900 dark:bg-gray-800 dark:text-white' => $costHours === 168, 'text-gray-500 hover:text-gray-900' => $costHours !== 168])>7 gün</button>
                    </div>
                </div>
                @php($costTotal = array_sum(array_column($costs, 'cost')))
                <div class="mt-3 overflow-x-auto"><table class="w-full min-w-[34rem] text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><tr><th class="py-2 font-normal">İşlem</th><th class="text-right font-normal">Çağrı</th><th class="text-right font-normal">Maliyet</th><th class="text-right font-normal">Pay</th><th class="text-right font-normal">Otomatik</th><th class="pl-3 font-normal">Model</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($costs as $cost)
                            <tr wire:key="cost-{{ $cost['operation'] }}">
                                <td class="py-2.5">{{ $cost['label'] }}</td>
                                <td class="text-right tabular-nums">{{ $cost['calls'] }}</td>
                                <td class="text-right tabular-nums font-medium">{{ $usd($cost['cost']) }}</td>
                                <td class="text-right tabular-nums text-gray-500">{{ $costTotal > 0 ? round($cost['cost'] / $costTotal * 100) : 0 }}%</td>
                                <td class="text-right tabular-nums text-gray-500">{{ $usd($cost['auto_cost']) }}</td>
                                <td class="pl-3 text-xs text-gray-500">{{ $cost['model'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-2.5 text-gray-500">Bu sürede AI harcaması yok.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </section>

            <div class="grid gap-10 lg:grid-cols-3">
                {{-- Son 24 saat --}}
                <section class="min-w-0 lg:col-span-2" data-ai-finished>
                    <h2 class="section-title">Son 24 saat</h2>
                    <div class="mt-3 overflow-x-auto"><table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($live['finished'] as $item)
                                <tr wire:key="done-{{ $item->id }}">
                                    <td class="py-1.5 pr-2 align-top">
                                        <span @class(['inline-block h-2 w-2 rounded-full', 'bg-success-500' => $item->status === 'done', 'bg-error-500' => $item->status === 'failed', 'bg-gray-400' => ! in_array($item->status, ['done', 'failed'], true)])></span>
                                    </td>
                                    <td class="py-1.5 pr-3">
                                        <a href="{{ route('operator.ai-jobs', ['is' => $item->id]) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $item->label }}</a>
                                        @if ($where($item) !== [])<span class="text-xs text-gray-500"> · {{ implode(' › ', $where($item)) }}</span>@endif
                                        @if ($item->status !== 'done' && $item->error)<p class="text-xs text-error-600">{{ $item->error }}</p>@endif
                                    </td>
                                    <td class="whitespace-nowrap py-1.5 pr-3 text-xs text-gray-500">{{ $item->finished_at?->timezone('Europe/Istanbul')->format('H:i') }}, {{ $who($item) }}</td>
                                    <td class="whitespace-nowrap py-1.5 pr-3 text-right text-xs tabular-nums text-gray-500">{{ $item->statusLabel() }} · {{ $item->durationLabel() }}</td>
                                    <td class="py-1.5 text-right text-xs tabular-nums">{{ $item->cost_usd !== null ? $usd($item->cost_usd) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-2.5 text-gray-500">Son 24 saatte AI işi yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                </section>

                {{-- Otomatik takvim --}}
                <section data-ai-schedule>
                    <h2 class="section-title" title="Kimse tıklamadan AI çalıştıran işler.">Otomatik takvim</h2>
                    <ul class="mt-3 divide-y divide-gray-200 text-sm dark:divide-gray-800">
                        @foreach ($schedule as $task)
                            <li class="py-2.5" wire:key="sched-{{ $task['name'] }}">
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
        <section class="space-y-4" data-ai-credits>
            <div>
                <h2 class="section-title">AI dağılımı ve kredi</h2>
                <p class="mt-1 text-xs text-gray-500">Her AI işlemi üç yoldan biriyle çalışır: GPT (OpenAI), Claude API (yüklediğin krediden düşer) veya Claude abonelik (MCP kuyruğu). Claude API kredisi biter ya da günlük tavan dolarsa, kuyruğu destekleyen işler kendiliğinden aboneliğe geçer.</p>
            </div>
            <div class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
                @foreach ($credits as $credit)
                    <div wire:key="credit-{{ $credit['provider'] }}" class="min-w-0" data-credit="{{ $credit['provider'] }}">
                        <p class="text-xs text-gray-500">{{ $credit['label'] }} kredisi</p>
                        @if ($credit['tracked'])
                            <p class="text-[26px] leading-8 font-semibold tracking-tight tabular-nums text-gray-900 dark:text-white">{{ $usd($credit['remaining']) }} <span class="text-xs font-normal text-gray-500">kaldı</span></p>
                            <p class="text-xs text-gray-500">Yüklenen {{ $usd($credit['loaded']) }}, harcanan {{ $usd($credit['spent']) }}</p>
                            @if ($credit['exhausted'])
                                <p class="text-xs font-medium text-error-600">Kredi bitti; bu sağlayıcıya ücretli çağrı başlamaz.</p>
                            @elseif ($credit['low'])
                                <p class="text-xs font-medium text-warning-700">Kredi azaldı.</p>
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
                <button type="submit" class="rounded-lg px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-white dark:ring-gray-700">Kredi ekle</button>
                @error('creditAmount')<span class="w-full text-xs text-error-600">{{ $message }}</span>@enderror
                <span class="w-full text-xs text-gray-500">Kalan = yüklenen − ilk yüklemeden beri kaydedilen maliyet. Sağlayıcı panelindeki bakiyeyle arada küçük fark olabilir.</span>
            </form>
            <div class="space-y-2 text-sm" data-ai-plans>
                <p class="text-xs text-gray-500">Şu an: @foreach ($kinds as $kind => $count)<span class="mr-2">{{ \App\Services\Ai\AiAssignments::kindLabel($kind) }} {{ $count }}</span>@endforeach</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($plans as $plan => $planLabel)
                        <button type="button" wire:key="plan-{{ $plan }}" wire:click="assignPlan('{{ $plan }}')" wire:confirm="{{ $planLabel }} uygulansın mı? Değişen her işlem için yeni prompt sürümü açılır; sorgu pilotu ve WhatsApp yerinde kalır." class="rounded-lg px-3 py-1.5 text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700" data-plan="{{ $plan }}">{{ $planLabel }}</button>
                    @endforeach
                </div>
            </div>
        </section>

        <details class="border-t border-gray-200 pt-4 text-sm dark:border-gray-800" data-ai-budget>
            <summary class="cursor-pointer font-semibold text-gray-800 dark:text-white/90">AI bütçesi <span class="font-normal text-gray-500">aylık {{ $usd($monthlyBudget) }}, günlük tavan (tüm AI) {{ $autoBudget > 0 ? $usd($autoBudget) : 'sınırsız' }}, Kalan bakiye {{ $usd($remaining) }}</span></summary>
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
                    <input type="password" autocomplete="off" wire:model="openAiAdminKey" placeholder="{{ $adminKeySet ? '•••••••• (değiştirmek için yaz)' : 'sk-admin-…' }}" class="w-full min-w-0 rounded-lg border border-gray-300 px-2 py-1 sm:w-72 dark:border-gray-700 dark:bg-gray-900" data-openai-admin-key>
                </label>
                <button type="submit" class="rounded-lg px-3 py-1.5 text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-white dark:ring-gray-700">Kaydet</button>
                <button type="button" wire:click="auditOpenAi" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 dark:border-gray-700 dark:text-gray-300" data-openai-audit-now>Şimdi denetle</button>
                <div class="w-full text-xs text-gray-500" data-openai-audit>
                    Kota denetimi saatte bir OpenAI'ın gerçek faturasını (Admin anahtarıyla) bizim tahminimizle karşılaştırır. Dün OpenAI belirgin şekilde fazla faturaladıysa kota hesabını kendisi kapatır. Bugünkü gerçek fatura, günlük tavan için alt sınır sayılır.
                    @if ($audit !== null)
                        <br>Son denetim {{ \Carbon\CarbonImmutable::parse($audit['checked_at'])->timezone('Europe/Istanbul')->format('d.m H:i') }}: {{ $audit['message'] ?? $audit['status'] }}
                        @foreach (($audit['days'] ?? []) as $day)
                            <br>{{ $day['date'] }}: OpenAI {{ $usd($day['actual']) }}, tahmin {{ $usd($day['estimated']) }}, liste fiyatı {{ $usd($day['list']) }}
                        @endforeach
                    @endif
                </div>
                <span class="w-full text-xs text-gray-500">Aylık bakiye bitince ay sonuna kadar yalnız ücretsiz modeller çalışır. Günün (İstanbul saati) tüm AI harcaması tavana ulaşınca, tıkladığın işler dahil hiçbir ücretli AI çağrısı başlamaz; yarın yeniden çalışır. Kimse tıklamadan Sorgular alanındaki AI (sorgu pilotu, kümeleme) Claude aboneliğine devredilen işler ve Claude API'ye atanmış işler çalışır; abonelik işleri tavana ve bütçeye sayılmaz, Claude API işleri krediden, tavandan ve bütçeden düşer. GPT'de kalan analistler, bakım ajanı ve Şef yalnız tıklayınca çalışır. 0 = tavan yok.</span>
                @error('dailyAutoBudget')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
                @error('budget')<span class="text-xs text-error-600">{{ $message }}</span>@enderror
            </form>
        </details>

        <details class="overflow-x-auto border-t border-gray-200 pt-4 dark:border-gray-800" data-ai-operations>
            <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-white" title="Talimatı görmek / düzenlemek için aç">Promptlar ve modeller <span class="font-normal text-gray-500">{{ count($rows) }} AI işlemi</span></summary>
            <table class="mt-3 w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800">
                    <tr>
                        <th class="py-2 pr-3 font-normal">İşlem</th>
                        <th class="px-3 py-2 font-normal">Model</th>
                        <th class="px-3 py-2 text-right font-normal">Sürüm</th>
                        <th class="px-3 py-2 text-right font-normal">30 gün</th>
                        <th class="px-3 py-2 text-right font-normal">Ort. süre</th>
                        <th class="py-2 pl-3 text-right font-normal">Maliyet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach (collect($rows)->sortByDesc('runs') as $row)
                        <tr wire:key="op-{{ $row['operation'] }}">
                            <td class="py-2.5 pr-3">
                                <button type="button" wire:click="open('{{ $row['operation'] }}')" class="text-left font-medium text-gray-900 hover:underline dark:text-white">{{ \App\Support\Ai\AiOperationLabels::for($row['operation']) }}</button>
                                <span class="ml-1 font-mono text-xs text-gray-500">{{ $row['operation'] }}</span>
                                <p class="text-xs text-gray-500">{{ $row['purpose'] }}</p>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500"><span class="mr-1 text-gray-700 dark:text-gray-200">{{ \App\Services\Ai\AiAssignments::kindLabel($row['kind']) }},</span>{{ $row['model'] !== '' ? $row['model'] : 'Rota modeli' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['version'] !== null ? 'v'.$row['version'] : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $row['runs'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $sec($row['avg_ms']) }}</td>
                            <td class="py-2.5 pl-3 text-right tabular-nums">{{ $usd($row['cost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @else
        @php($definition = $detail['definition'])
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <button type="button" wire:click="close" class="text-sm text-gray-500 hover:text-gray-900 dark:hover:text-white">İşlemler</button>
                <h2 class="mt-1 text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $operation }} <span class="text-sm font-normal text-gray-500">v{{ $detail['current']->version }}</span></h2>
                <p class="text-sm text-gray-500">{{ $detail['current']->purpose ?: $definition['purpose'] }}</p>
            </div>
        </div>

        <form wire:submit="save" class="space-y-4" data-prompt-editor>
            <div>
                <label for="prompt-template" class="text-xs font-semibold text-gray-600 dark:text-gray-300">Şablon</label>
                <textarea id="prompt-template" wire:model="template" rows="18" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
                @error('template')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-gray-500">Sabit cümle her sürüme kodla eklenir: “{{ \App\Services\Prompts\PromptRegistry::GUARD }}”</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">Değişkenler</p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @forelse ($definition['variables'] as $variable)
                            <span class="font-mono text-xs text-gray-700 dark:text-gray-200">&#123;&#123;{{ $variable }}&#125;&#125;</span>
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

        <section class="space-y-3 border-t border-gray-200 pt-6 dark:border-gray-800" data-prompt-trial @if (($detail['trial']['status'] ?? null) === 'running') wire:poll.3s @endif>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="section-title">Örnekte dene</h3>
                <div class="flex flex-wrap gap-1">
                    @foreach ($detail['samples'] as $sample)
                        <button type="button" wire:click="useSample({{ $sample['id'] }})" wire:key="sample-{{ $sample['id'] }}" class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($sample['at'])->format('d.m H:i') }}{{ $sample['version'] !== null ? ' · v'.$sample['version'] : '' }}</button>
                    @endforeach
                </div>
            </div>
            <textarea wire:model="trialInput" rows="5" placeholder="Son çalıştırmalardan birini seçin ya da örnek girdi yapıştırın." class="w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:text-white/90"></textarea>
            @error('trial')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            <div class="flex items-center gap-3">
                <button type="button" wire:click="runTrial" wire:loading.attr="disabled" @disabled(($detail['trial']['status'] ?? null) === 'running') class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 disabled:opacity-50 dark:text-white dark:ring-gray-700">Taslağı dene</button>
                <span class="text-xs text-gray-500">Yayınlanmamış şablon, öneri kaydedilmez</span>
            </div>
            @if ($detail['trial'] !== null)
                @if ($detail['trial']['status'] === 'running')
                    <p class="text-sm text-gray-500">Çalışıyor…</p>
                @elseif ($detail['trial']['status'] === 'failed')
                    <p class="text-sm text-error-600">{{ $detail['trial']['message'] }}</p>
                @else
                    <p class="text-xs text-gray-500">{{ $detail['trial']['model'] }} · {{ $sec($detail['trial']['duration_ms']) }} · {{ $usd($detail['trial']['cost']) }}</p>
                    <pre class="max-h-96 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $detail['trial']['output'] }}</pre>
                @endif
            @endif
        </section>

        <div class="grid gap-10 lg:grid-cols-2">
            <section data-prompt-versions>
                <h3 class="section-title">Sürüm geçmişi</h3>
                <ul class="mt-2 divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    @foreach ($detail['versions'] as $version)
                        <li class="flex items-center justify-between gap-2 py-2" wire:key="v-{{ $version->id }}">
                            <span>
                                v{{ $version->version }}, {{ $version->created_at?->format('d.m.Y H:i') }}, {{ $version->creator?->name ?? 'Sistem' }}
                                @if ($version->model)<span class="text-xs text-gray-500">· {{ $version->model }}</span>@endif
                            </span>
                            @if ($version->is_current)
                                <span class="text-xs font-medium text-success-600">Kullanımda</span>
                            @else
                                <button type="button" wire:click="revertTo({{ $version->version }})" wire:confirm="v{{ $version->version }} yeni sürüm olarak yayınlansın mı?" class="link-neutral text-xs">Bu sürüme dön</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
            <section data-prompt-runs>
                <h3 class="section-title">Son çalıştırmalar</h3>
                <table class="mt-2 w-full text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($detail['runs'] as $run)
                            <tr>
                                <td class="py-2">{{ \Illuminate\Support\Carbon::parse($run['at'])->format('d.m.Y H:i') }}</td>
                                <td class="py-2 text-xs text-gray-500">{{ $run['version'] !== null ? 'v'.$run['version'] : '—' }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $sec($run['duration_ms']) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $usd($run['cost']) }}</td>
                                <td @class(['py-2 text-right text-xs', 'text-success-600' => $run['status'] === 'ok', 'text-error-600' => $run['status'] !== 'ok'])>{{ $run['status'] === 'ok' ? 'Başarılı' : 'Hata' }}</td>
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
