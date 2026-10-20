<section class="space-y-10" data-brand-dossier>
    @if ($message !== '')
        <p class="tone-ok rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif

    @if ($gaps->isNotEmpty())
        <div data-brand-gaps>
            <h2 class="section-title text-gray-900 dark:text-white" title="AI işlerini tıkayan şeyler. &quot;Onayla ve yap&quot; yalnız MoxDOP içinde düzeltir; dışarıya hiçbir şey yazmaz.">Eksikler <span class="text-sm font-normal text-warning-700 dark:text-warning-400">{{ $gaps->count() }}</span></h2>
            <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @foreach ($gaps as $gap)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-2.5" data-gap="{{ $gap->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $gap->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $gap->reason }}</p>
                        </div>
                        @if (! empty($gap->action['fix']))
                            <button type="button" wire:click="applyGap({{ $gap->id }})" wire:loading.attr="disabled" wire:confirm="Bu eksik şimdi düzeltilsin mi?" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5">Onayla ve yap</button>
                        @elseif (! empty($gap->action['url']))
                            <a href="{{ $gap->action['url'] }}" wire:navigate class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5">Düzelt</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div data-brand-audit>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div title="AI'ın bu marka için yaptıklarını markanın kendi verisiyle karşılaştırır; yalnız hata arar, yeni iş üretmez. AI kullanmaz. &quot;Düzelt&quot; yalnız yanlış AI kararını geri alır. Her pazartesi Şef planından önce çalışır.">
                <h2 class="section-title text-gray-900 dark:text-white">Şef denetimi · {{ $audit->count() }} hata</h2>
                @if ($auditedAt)<p class="mt-1 text-sm text-gray-500">Son denetim: {{ \Illuminate\Support\Carbon::parse($auditedAt)->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>@endif
            </div>
            <button type="button" wire:click="auditNow" wire:loading.attr="disabled" class="shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5" data-audit-now>Şimdi denetle</button>
        </div>
        @if ($audit->isNotEmpty())
            <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                @foreach ($audit as $finding)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-2.5" data-audit-finding="{{ $finding->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-error-700 dark:text-error-400">{{ $finding->title }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $finding->reason }}</p>
                            <ul class="mt-1 list-disc pl-4 text-xs text-gray-500">@foreach ((array) data_get($finding->action, 'examples', []) as $example)<li>{{ $example }}</li>@endforeach</ul>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button type="button" wire:click="fixAudit({{ $finding->id }})" wire:loading.attr="disabled" wire:confirm="Yanlış AI kararları geri alınsın mı?" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5" data-fix-audit>Düzelt</button>
                            <button type="button" wire:click="acceptAudit({{ $finding->id }})" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5" data-accept-audit>Doğru, bırak</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div data-brand-care>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div title="Her pazar gecesi bu dosyayı okur; dosyada değişiklik yoksa çalışmaz (maliyet yok). İşleri iş listesine ekler, dışarıya hiçbir şey yazmaz.">
                <h2 class="section-title text-gray-900 dark:text-white">Bakım ajanı</h2>
                @if (! empty($care['reviewed_at']))
                    <p class="mt-1 text-sm text-gray-500">Son inceleme: {{ \Illuminate\Support\Carbon::parse($care['reviewed_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                @endif
            </div>
            @if ($operational)
                <button type="button" wire:click="reviewNow" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">Şimdi incele</button>
            @endif
        </div>
        @if (! $operational)
            <p class="mt-3 text-sm text-gray-500">Marka aktif değil; bakım ajanı yalnız aktif markalarda çalışır.</p>
        @elseif ($care === null || empty($care['reviewed_at']))
            <p class="mt-3 text-sm text-gray-500">Henüz inceleme yok; ilk inceleme pazar gecesi ya da "Şimdi incele" ile.</p>
        @else
            @if (! empty($care['summary']))
                <p class="mt-3 text-sm text-gray-900 dark:text-white">{{ $care['summary'] }}</p>
            @endif
            @if ($careTasks->isNotEmpty())
                <ul class="mt-3 divide-y divide-gray-200 border-y border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($careTasks as $task)
                        <li class="py-2.5" data-care-task="{{ $task->id }}"><span class="text-gray-900 dark:text-white">{{ $task->title }}</span> <span class="ml-1 text-xs text-gray-500">{{ $task->channelLabel() }}</span>
                            <span class="block text-xs text-gray-500">{{ $task->reason }}</span></li>
                    @endforeach
                </ul>
            @endif
            @if (! empty($care['questions']))
                <div class="tone-look mt-3 rounded-lg px-3 py-2 text-sm">
                    <p class="font-medium">Ajanın soruları (cevabı Ayarlar › İş bağlamı içindeki hedefler / kısıtlara ya da ilgili ayara yaz):</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($care['questions'] as $question)
                            <li>{{ $question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
        @if (! empty($care['blocked']))
            <p class="mt-3 text-sm text-warning-700 dark:text-warning-400" data-care-blocked>{{ $care['blocked'] }}</p>
        @endif
        @if (! empty($care['failed_at']) && (empty($care['reviewed_at']) || $care['failed_at'] > $care['reviewed_at']))
            <p class="mt-3 text-sm text-error-700 dark:text-error-400">Son inceleme tamamlanamadı: {{ $care['error'] ?? '' }}</p>
        @endif
    </div>

    <div>
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div title="AI ajanlarının her işte ilk okuduğu kısa özet. Sistem verilerden kendisi derler (AI kullanmaz); her gece ve Otomatik kur sonrası yenilenir.">
                <h2 class="section-title text-gray-900 dark:text-white">Bilgi dosyası</h2>
                @if ($dossier['built_at'])
                    <p class="mt-1 text-sm text-gray-500">Son derleme: {{ \Illuminate\Support\Carbon::parse($dossier['built_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                @endif
            </div>
            <button type="button" wire:click="rebuild" wire:loading.attr="disabled" class="rounded-lg px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">Yenile</button>
        </div>

        <div class="mt-4 space-y-4">
            @foreach ($dossier['sections'] as $key => $section)
                <div data-dossier-section="{{ $key }}">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $section['title'] }}</h3>
                    <div class="prose prose-sm mt-1 max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">{!! \Illuminate\Support\Str::markdown($section['markdown'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div data-dossier-notes>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="section-title text-gray-900 dark:text-white" title="Dosyada elle yazılan kısım. Ajanlar hedefe göre öncelik verir, kısıtlardaki şeyleri asla önermez.">Hedefler ve kısıtlar</h2>
            <a href="{{ route('operator.brand', ['brand' => $brandId, 'tab' => 'ayarlar']) }}" wire:navigate class="link-neutral shrink-0 text-sm">Düzenle</a>
        </div>
        <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-xs text-gray-500">Hedefler</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900 dark:text-white">{{ $notes['goals'] !== '' ? $notes['goals'] : '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Kısıtlar</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900 dark:text-white">{{ $notes['constraints'] !== '' ? $notes['constraints'] : '—' }}</dd></div>
        </dl>
    </div>
</section>
