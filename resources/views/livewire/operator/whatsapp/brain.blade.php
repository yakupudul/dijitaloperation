@php
    $brain = is_array($config['brain'] ?? null) ? $config['brain'] : null;
    $brainStatus = $config['brain_status'] ?? null;
    $brainBusy = in_array($brainStatus, ['queued', 'running'], true);
    $list = fn ($items): array => array_values(array_filter((array) $items, fn ($item) => is_string($item) && trim($item) !== ''));
@endphp
<section class="space-y-6" data-wa-brain>
    <div>
        <div class="section-head mb-1! items-center!">
            <h2 class="section-title">Beyin</h2>
            <button type="button" wire:click="learnBrain" wire:loading.attr="disabled" wire:target="learnBrain" @disabled($brainBusy) class="btn ml-auto">{{ $brain ? 'Yeniden öğren' : 'Öğren' }}</button>
        </div>
        <p class="text-[13px] text-gray-500 dark:text-gray-400">
            @if($brainBusy)
                Görüşmeleri okuyor; birkaç dakika içinde burada görünür.
            @elseif($brain)
                {{ $when($brain['learned_at'] ?? null) }} tarihinde {{ $brain['conversations'] ?? 0 }} görüşmeden öğrendi. Cevaplar bunu ve aşağıdaki talimatlarınızı kullanır; talimatlarınız her zaman önce gelir.
            @else
                İlk yedek çıkarılınca görüşmelerden hizmetlerinizi, verdiğiniz fiyatları, sık soruları ve yazış üslubunuzu öğrenir.
            @endif
        </p>
    </div>
    @if($brainStatus === 'failed')
        <p role="alert" class="rounded-xl px-4 py-3 text-sm tone-critical">{{ \App\Services\WhatsApp\WhatsAppBrain::ERROR_LABELS[$config['brain_error'] ?? ''] ?? 'Öğrenme tamamlanamadı.' }}</p>
    @endif
    @error('brain')<p role="alert" class="text-sm text-error-600">{{ $message }}</p>@enderror

    @if($brain && $list($brain['open_questions'] ?? []) !== [])
        <form wire:submit="saveAnswers" class="card" data-wa-brain-questions>
            <div class="card-row items-start">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Senden istediklerim</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Her sorunun altına cevabını yaz ve kaydet. Cevaplar talimatlarına eklenir, soru listeden kalkar; boş bıraktığın soru kalır.</p>
                </div>
            </div>
            @foreach((array) $brain['open_questions'] as $index => $question)
                @if(is_string($question) && trim($question) !== '')
                    <label class="card-row block" wire:key="wa-question-{{ $index }}-{{ md5($question) }}">
                        <span class="block text-sm text-gray-900 dark:text-white">{{ $question }}</span>
                        <textarea wire:model="answers.{{ $index }}" rows="2" maxlength="2000" placeholder="Cevabın" class="mt-2 w-full rounded-lg border border-gray-200 bg-white p-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white"></textarea>
                    </label>
                @endif
            @endforeach
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
                @error('answers')<p class="mr-auto text-xs text-error-600">{{ $message }}</p>@enderror
                <button type="submit" wire:loading.attr="disabled" wire:target="saveAnswers" class="btn-primary">Cevapları kaydet</button>
            </div>
        </form>
    @endif

    <form wire:submit="saveInstructions" class="card" data-wa-instructions>
        <label class="block px-4 pt-3.5">
            <span class="block text-sm font-medium text-gray-900 dark:text-white">Talimatlarım</span>
            <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Güncel fiyatlar, hizmetler, nasıl yazmasını istediğin, söylememesi gerekenler. Burada yazan, beynin öğrendiğinin önüne geçer; burada olmayan fiyatı uydurmaz.</span>
            <textarea wire:model="business_context" rows="6" maxlength="12000" class="mt-3 w-full rounded-lg border border-gray-200 bg-white p-2.5 text-sm text-gray-900 focus:border-gray-400 focus:outline-none dark:border-gray-700 dark:bg-transparent dark:text-white"></textarea>
        </label>
        @error('business_context')<p class="px-4 pt-1 text-xs text-error-600">{{ $message }}</p>@enderror
        <div class="mt-3.5 flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-gray-50/60 px-4 py-3 dark:border-gray-800 dark:bg-white/[0.02]">
            <button type="submit" wire:loading.attr="disabled" wire:target="saveInstructions" class="btn-primary">Kaydet</button>
        </div>
    </form>

    @if($brain)
        <details class="group/learned" wire:ignore.self>
            <summary class="card flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.03] [&::-webkit-details-marker]:hidden">
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-gray-900 dark:text-white">Öğrendikleri</span>
                    <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">Hizmetler, kurallar, üslup, verilen fiyatlar ve sık sorular.</span>
                </span>
                <span class="btn-quiet"><span class="group-open/learned:hidden">Aç</span><span class="hidden group-open/learned:inline">Kapat</span></span>
            </summary>
            <div class="card mt-3 grid gap-5 p-5 text-sm md:grid-cols-2">
                @if(filled($brain['summary'] ?? null))
                    <p class="text-gray-600 md:col-span-2 dark:text-gray-300">{{ $brain['summary'] }}</p>
                @endif
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
                        <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-300">@foreach((array) $brain['prices'] as $price)@if(is_array($price))<li>{{ $price['item'] ?? '' }}: <span class="font-medium text-gray-900 dark:text-white">{{ $price['price'] ?? '' }}</span> <span class="text-xs text-gray-500">son {{ $price['last_quoted'] ?? '—' }}</span></li>@endif @endforeach</ul>
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
</section>
