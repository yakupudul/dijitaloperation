@php
    $field = 'h-9 rounded-lg border border-gray-200 bg-white px-3 text-sm dark:border-gray-700 dark:bg-transparent';
    $at = fn ($time): string => $time ? \Illuminate\Support\Carbon::parse($time)->timezone('Europe/Istanbul')->format('d.m H:i') : 'Hiç';
    $stateTone = ['active' => 'live', 'standby' => 'ok', 'paused' => 'look', 'down' => 'critical'];
    $stateText = ['active' => 'Çalışıyor', 'standby' => 'Yedekte bekliyor', 'paused' => 'Duraklatıldı', 'down' => 'Çalışmıyor'];
@endphp
<div class="space-y-10" data-ai-workers>
    <header class="min-w-0">
        <h1 class="page-title">AI sağlayıcıları</h1>
        <p class="page-sub">Kuyruktaki AI işlerini sıradaki ilk çalışan sağlayıcı yapar; diğerleri yedekte bekler ve sıra kendilerine gelince devralır.</p>
    </header>

    <section class="card -mt-4 flex flex-wrap items-center gap-3 p-4" data-ai-active>
        <div class="min-w-0 flex-1">
            @if ($active)
                <p class="text-sm font-medium text-gray-900 dark:text-white"><x-ta.pill tone="live">Şu an çalışan: {{ $active['label'] }}</x-ta.pill></p>
                <p class="mt-1 text-[13px] text-gray-500">Son tur {{ $at($active['seen_at']) }} · bugün {{ $doneToday }} iş bitti · {{ $waiting }} iş sırada</p>
            @else
                <p class="text-sm font-medium text-error-700 dark:text-error-400">Şu an çalışan sağlayıcı yok</p>
                <p class="mt-1 text-[13px] text-gray-500">{{ $waiting }} iş sırada; aşağıdan bir sağlayıcı bağla ya da duraklatılanı devam ettir.</p>
            @endif
        </div>
        @if ($active)
            <button type="button" class="btn w-full sm:w-auto" wire:click="switchToBackup" wire:confirm="{{ $active['label'] }} 24 saat duraklatılsın, sıradaki sağlayıcı devralsın mı?" data-switch-backup>Yedeğe geç</button>
        @endif
    </section>

    <section x-data="{ dragging: null }">
        <div class="section-head">
            <h2 class="section-title">Sıra <span class="section-count">{{ count($rows) }}</span></h2>
            <p class="text-xs text-gray-500">Sürükle ya da oklarla sırala. Asıl sağlayıcı rutin saatindeki turuna gelmezse sıradaki devralır; aldığı işler 2 saat ona ayrılır.</p>
        </div>
        <ol class="card" data-ai-worker-list>
            @foreach ($rows as $index => $row)
                <li wire:key="worker-{{ $row['key'] }}" class="card-row block! space-y-3" draggable="true"
                    x-on:dragstart="dragging = '{{ $row['key'] }}'" x-on:dragover.prevent x-on:drop.prevent="if (dragging && dragging !== '{{ $row['key'] }}') { $wire.moveTo(dragging, {{ $index }}) } dragging = null"
                    data-ai-worker="{{ $row['key'] }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="row-icon cursor-grab font-semibold tabular-nums text-gray-700" title="Sürükleyerek sırala" aria-hidden="true">{{ $row['position'] }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-medium text-gray-900 dark:text-white">
                                {{ $row['label'] }}
                                <span class="text-xs font-normal text-gray-500">{{ $index === 0 ? 'Asıl' : $index.'. yedek' }}</span>
                                <x-ta.pill :tone="$stateTone[$row['state']]">{{ $stateText[$row['state']] }}</x-ta.pill>
                            </p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                                @if ($row['reason']){{ $row['reason'] }} · @endif
                                Son {{ $row['mcp'] ? 'geliş' : 'tur' }} {{ $at($row['seen_at']) }} ·
                                @if ($row['mcp'] && $row['next_round']) sıradaki tur {{ $row['next_round']->format('H:i') }} · @endif son 24 saatte {{ $row['done_24h'] }} iş · 7 günde {{ $row['done_7d'] }}
                                @if (! $row['mcp']) · bugün {{ number_format((float) $row['spend_today'], 2, ',', '.') }} TL · bu ay {{ number_format((float) $row['spend_month'], 2, ',', '.') }} TL @endif
                            </p>
                            @if ($row['error'])
                                <p class="mt-0.5 text-xs text-gray-500">Son hata {{ $at($row['error']['at']) }}: {{ $row['error']['message'] }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1">
                            @if ($row['state'] === 'paused' || ($row['error'] && ! $row['mcp']))
                                <button type="button" class="btn h-8! px-2.5! text-xs!" wire:click="resume('{{ $row['key'] }}')">Devam ettir</button>
                            @endif
                            <button type="button" class="btn-quiet h-8! w-8! px-0!" wire:click="moveBy('{{ $row['key'] }}', -1)" @disabled($index === 0) aria-label="Yukarı al">↑</button>
                            <button type="button" class="btn-quiet h-8! w-8! px-0!" wire:click="moveBy('{{ $row['key'] }}', 1)" @disabled($index === count($rows) - 1) aria-label="Aşağı al">↓</button>
                        </div>
                    </div>

                    @if ($row['mcp'])
                        <form wire:submit="saveRounds('{{ $row['key'] }}')" class="ml-0 flex flex-wrap items-center gap-2 text-[13px] text-gray-600 sm:ml-11" data-ai-worker-rounds>
                            Rutin saatleri
                            <input type="text" wire:model="rounds.{{ $row['key'] }}" placeholder="08:27, 12:27, 16:27, 21:27" aria-label="Rutin saatleri" class="{{ $field }} h-8! min-w-0 flex-1 sm:max-w-72">
                            <button type="submit" class="btn h-8! px-2.5! text-xs!">Kaydet</button>
                            @if ($saved === 'rounds-'.$row['key'])<span class="text-xs text-gray-500">Kaydedildi</span>@endif
                        </form>
                        <div class="ml-0 space-y-1 sm:ml-11" data-ai-worker-connections>
                            @forelse ($row['tokens'] as $token)
                                <div class="flex flex-wrap items-center gap-2 text-[13px]" wire:key="token-{{ $token->id }}">
                                    <span class="min-w-0 flex-1 text-gray-700 dark:text-gray-300">{{ $token->name }} <span class="text-xs text-gray-500">…{{ $token->last_four }} · son kullanım {{ $at($token->last_used_at) }}</span></span>
                                    <button type="button" class="btn h-7! px-2.5! text-xs!" wire:click="startRemove({{ $token->id }})" data-remove-start>Bağlantıyı kaldır</button>
                                </div>
                            @empty
                                <p class="text-[13px] text-gray-500">
                                    @if ($row['key'] === \App\Services\AiTasks\AiWorkers::CLAUDE_MCP && $row['connected'])
                                        Sunucu anahtarıyla bağlı (Ayarlar › MCP anahtarları).
                                    @else
                                        Bağlantı yok. Aşağıdaki "Nasıl bağlanır" adımlarını izle.
                                    @endif
                                </p>
                            @endforelse
                        </div>
                    @else
                        @php $key = $row['key']; $provider = \App\Services\AiTasks\AiWorkers::API_PROVIDERS[$key]; @endphp
                        <form wire:submit="saveApi('{{ $key }}')" class="ml-0 flex flex-wrap items-center gap-2 sm:ml-11" data-ai-worker-api>
                            <label class="inline-flex items-center gap-2 text-[13px] text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="api.{{ $key }}.enabled" class="rounded border-gray-300"> Açık</label>
                            <select wire:model="api.{{ $key }}.model" aria-label="Model" class="{{ $field }} w-full sm:w-auto">
                                @foreach ($models[$key] as $model)
                                    <option value="{{ $model }}">{{ $model }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="0" step="1" wire:model="api.{{ $key }}.daily_try" placeholder="Günlük sınır (TL)" aria-label="Günlük sınır (TL)" class="{{ $field }} w-full sm:w-40">
                            <input type="number" min="0" step="1" wire:model="api.{{ $key }}.monthly_try" placeholder="Aylık sınır (TL)" aria-label="Aylık sınır (TL)" class="{{ $field }} w-full sm:w-40">
                            <button type="submit" class="btn w-full sm:w-auto">Kaydet</button>
                            <a href="{{ route('operator.integrations.ai', ['provider' => $provider]) }}" wire:navigate class="link-neutral text-xs">{{ $row['connected'] ? 'API anahtarı' : 'API anahtarını gir' }}</a>
                            @if ($saved === $key)<span class="text-xs text-gray-500">Kaydedildi</span>@endif
                            @error('api.'.$key.'.model')<p class="w-full text-xs text-error-600">{{ $message }}</p>@enderror
                            @error('api.'.$key.'.daily_try')<p class="w-full text-xs text-error-600">{{ $message }}</p>@enderror
                            @error('api.'.$key.'.monthly_try')<p class="w-full text-xs text-error-600">{{ $message }}</p>@enderror
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
        <form wire:submit="saveRate" class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500" data-usd-try>
            API harcaması TL'ye 1 USD = <input type="number" min="1" step="0.01" wire:model="usdTry" aria-label="Dolar kuru" class="{{ $field }} h-7! w-24"> TL ile çevrilir.
            <button type="submit" class="btn h-7! px-2.5! text-xs!">Kaydet</button>
            @if ($saved === 'rate')<span>Kaydedildi</span>@endif
            @error('usdTry')<span class="text-error-600">{{ $message }}</span>@enderror
            <span class="w-full">Sınır boş ya da 0 ise sınır yoktur. Sınır dolunca o API kendini kapatır, sıradaki devralır.</span>
        </form>
    </section>

    @if ($removing && ! $removing->revoked_at)
        <section class="card space-y-3 p-4" data-remove-confirm="{{ $removeStep }}">
            @if ($removeStep === 1)
                <p class="text-sm font-medium text-gray-900 dark:text-white">"{{ $removing->name }}" bağlantısı kaldırılsın mı?</p>
                <p class="text-[13px] text-gray-500">Kaldırınca bu bağlantı MoxDOP'a ulaşamaz; geri getirmek için yeniden bağlanman gerekir.</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn w-full sm:w-auto" wire:click="cancelRemove">Vazgeç</button>
                    <button type="button" class="btn-dark w-full sm:w-auto" wire:click="confirmRemove">Evet, devam et</button>
                </div>
            @else
                <p class="text-sm font-medium text-gray-900 dark:text-white">Son onay: kaldırmak için bağlantının adını yaz</p>
                <p class="text-[13px] text-gray-500">{{ $removing->name }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" wire:model="removeName" aria-label="Bağlantının adı" class="{{ $field }} min-w-0 flex-1 sm:min-w-60" autocomplete="off">
                    <button type="button" class="btn w-full sm:w-auto" wire:click="cancelRemove">Vazgeç</button>
                    <button type="button" class="btn-dark w-full sm:w-auto" wire:click="confirmRemove">Bağlantıyı kaldır</button>
                </div>
                @error('removeName')<p class="text-xs text-error-600">{{ $message }}</p>@enderror
            @endif
        </section>
    @endif

    <section>
        <div class="section-head"><h2 class="section-title">Nasıl bağlanır</h2></div>
        <div class="card">
            <div class="card-row block!">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Claude</p>
                <p class="mt-0.5 text-[13px] text-gray-500">claude.ai › Ayarlar › Bağlayıcılar › Özel bağlayıcı ekle: <code>{{ $serverUrl }}</code>. Bağlan'a bas, açılan MoxDOP ekranında onayla. Anahtar sohbete yazılmaz.</p>
            </div>
            <div class="card-row block!">
                <p class="text-sm font-medium text-gray-900 dark:text-white">ChatGPT</p>
                <p class="mt-0.5 text-[13px] text-gray-500">ChatGPT › Ayarlar › Bağlayıcılar (Apps) › Gelişmiş › Developer mode'u aç. Sonra "Oluştur" ile yeni bağlayıcı ekle: ad "MoxDOP", adres <code>{{ $serverUrl }}</code>, kimlik doğrulama OAuth. Açılan MoxDOP ekranında onayla; bağlantı yukarıda ChatGPT MCP satırında görünür.</p>
            </div>
        </div>
    </section>

    <section>
        <div class="section-head"><h2 class="section-title">Rutin yönergeleri</h2></div>
        <div class="card">
            @foreach ($routines as $routine)
                <div class="card-row block! space-y-2" x-data="{ copied: false }" data-routine="{{ $routine['label'] }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="min-w-0 flex-1 text-sm font-medium text-gray-900 dark:text-white">{{ $routine['label'] }} <span class="text-xs font-normal text-gray-500">{{ $routine['times'] }}</span></p>
                        <button type="button" class="btn h-8! px-2.5! text-xs!" x-on:click="navigator.clipboard.writeText($refs.prompt.innerText); copied = true" x-text="copied ? 'Kopyalandı' : 'Kopyala'">Kopyala</button>
                    </div>
                    <p class="text-xs text-gray-500">{{ $routine['where'] }}. Yedek, asıldan 30 dakika sonra çalışır; asıl gelmişse "sıra sende değil" cevabını alır ve hemen biter.</p>
                    <pre class="whitespace-pre-wrap rounded-lg bg-gray-100 p-3 text-[12px] leading-relaxed text-gray-700 dark:bg-white/10 dark:text-gray-300" x-ref="prompt">{{ $routine['prompt'] }}</pre>
                </div>
            @endforeach
        </div>
    </section>
</div>
