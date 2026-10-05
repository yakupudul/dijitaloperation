@php
    $brain = is_array($config['brain'] ?? null) ? $config['brain'] : null;
    $brainStatus = $config['brain_status'] ?? null;
    $brainBusy = in_array($brainStatus, ['queued', 'running'], true);
    $list = fn ($items): array => array_values(array_filter((array) $items, fn ($item) => is_string($item) && trim($item) !== ''));
@endphp
<section class="{{ $card }} space-y-4 p-5" data-wa-brain>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="font-semibold text-gray-900 dark:text-white">Beyin</h2>
            <p class="mt-1 text-sm text-gray-500">
                @if($brainBusy)
                    Görüşmeleri okuyor; birkaç dakika içinde burada görünür.
                @elseif($brain)
                    {{ $when($brain['learned_at'] ?? null) }} tarihinde {{ $brain['conversations'] ?? 0 }} görüşmeden öğrendi. Cevaplar bunu ve aşağıdaki talimatlarınızı kullanır; talimatlarınız her zaman önce gelir.
                @else
                    İlk yedek çıkarılınca görüşmelerden hizmetlerinizi, verdiğiniz fiyatları, sık soruları ve yazış üslubunuzu öğrenir.
                @endif
            </p>
        </div>
        <button type="button" wire:click="learnBrain" wire:loading.attr="disabled" wire:target="learnBrain" @disabled($brainBusy) class="shrink-0 rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:ring-gray-700 dark:hover:bg-white/[0.04]">{{ $brain ? 'Yeniden öğren' : 'Öğren' }}</button>
    </div>
    @if($brainStatus === 'failed')
        <p role="alert" class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['bad'] }}">{{ \App\Services\WhatsApp\WhatsAppBrain::ERROR_LABELS[$config['brain_error'] ?? ''] ?? 'Öğrenme tamamlanamadı.' }}</p>
    @endif
    @error('brain')<p role="alert" class="text-sm text-rose-600">{{ $message }}</p>@enderror

    @if($brain)
        @if($list($brain['open_questions'] ?? []) !== [])
            <div class="rounded-lg p-3 text-sm ring-1 ring-inset {{ $tone['warn'] }}" data-wa-brain-questions>
                <p class="font-medium">Senden istediklerim</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach($list($brain['open_questions']) as $question)<li>{{ $question }}</li>@endforeach
                </ul>
                <p class="mt-1 text-xs">Cevaplarını aşağıdaki talimatlara yaz; sonraki cevaplar onlara göre hazırlanır.</p>
            </div>
        @endif
        <details class="text-sm" wire:ignore.self>
            <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Öğrendikleri</summary>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2"><p class="text-gray-600 dark:text-gray-300">{{ $brain['summary'] ?? '' }}</p></div>
                @foreach(['services' => 'Hizmetler', 'policies' => 'Kurallar ve süreç', 'avoid' => 'Kaçındıkları'] as $key => $label)
                    @if($list($brain[$key] ?? []) !== [])
                        <div><p class="font-medium text-gray-900 dark:text-white">{{ $label }}</p><ul class="mt-1 list-disc space-y-0.5 pl-5 text-gray-600 dark:text-gray-300">@foreach($list($brain[$key]) as $item)<li>{{ $item }}</li>@endforeach</ul></div>
                    @endif
                @endforeach
                @if(filled($brain['tone'] ?? null))
                    <div><p class="font-medium text-gray-900 dark:text-white">Üslup</p><p class="mt-1 text-gray-600 dark:text-gray-300">{{ $brain['tone'] }}</p></div>
                @endif
                @if(! empty($brain['prices']))
                    <div class="md:col-span-2"><p class="font-medium text-gray-900 dark:text-white">Daha önce verilen fiyatlar</p>
                        <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-300">@foreach((array) $brain['prices'] as $price)@if(is_array($price))<li>{{ $price['item'] ?? '' }}: <strong>{{ $price['price'] ?? '' }}</strong> <span class="text-xs text-gray-500">· son {{ $price['last_quoted'] ?? '—' }}</span></li>@endif @endforeach</ul>
                    </div>
                @endif
                @if(! empty($brain['faq']))
                    <div class="md:col-span-2"><p class="font-medium text-gray-900 dark:text-white">Sık sorular</p>
                        <dl class="mt-1 space-y-2 text-gray-600 dark:text-gray-300">@foreach((array) $brain['faq'] as $faq)@if(is_array($faq))<div><dt class="font-medium">{{ $faq['question'] ?? '' }}</dt><dd>{{ $faq['answer'] ?? '' }}</dd></div>@endif @endforeach</dl>
                    </div>
                @endif
            </div>
        </details>
    @endif

    <form wire:submit="saveInstructions" class="space-y-2" data-wa-instructions>
        <label class="block text-sm font-medium text-gray-900 dark:text-white">Talimatlarım
            <textarea wire:model="business_context" rows="5" maxlength="12000" class="mt-1 w-full rounded-lg border border-gray-300 bg-transparent p-2 text-sm font-normal dark:border-gray-700"></textarea>
        </label>
        <p class="text-xs text-gray-500">Güncel fiyatlar, hizmetler, nasıl yazmasını istediğin, söylememesi gerekenler. Burada yazan, beynin öğrendiğinin önüne geçer; burada olmayan fiyatı uydurmaz.</p>
        @error('business_context')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <button type="submit" wire:loading.attr="disabled" wire:target="saveInstructions" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-60">Kaydet</button>
    </form>
</section>
