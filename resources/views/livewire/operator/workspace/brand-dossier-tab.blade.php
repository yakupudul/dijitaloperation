{{-- Epikriz › the brand file: what blocks the AI (Eksikler), what the chief's audit found, the care agent's weekly review, the file itself and the goals / constraints. --}}
<section class="space-y-10" data-brand-dossier>
    @if ($message !== '')
        <p class="card px-4 py-3 text-[13px] text-gray-700 dark:text-gray-200" role="status">{{ $message }}</p>
    @endif

    @if ($gaps->isNotEmpty())
        <div data-brand-gaps>
            <div class="section-head">
                <h2 class="section-title" title="AI işlerini tıkayan şeyler. &quot;Onayla ve yap&quot; yalnız MoxDOP içinde düzeltir; dışarıya hiçbir şey yazmaz.">Eksikler <span class="section-count text-warning-700! dark:text-warning-400!">{{ $gaps->count() }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($gaps as $gap)
                    <li class="card-row flex-wrap sm:flex-nowrap" data-gap="{{ $gap->id }}">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $gap->title }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $gap->reason }}</p>
                        </div>
                        @if (! empty($gap->action['fix']))
                            <button type="button" wire:click="applyGap({{ $gap->id }})" wire:loading.attr="disabled" wire:confirm="Bu eksik şimdi düzeltilsin mi?" class="btn ml-5 sm:ml-0">Onayla ve yap</button>
                        @elseif (! empty($gap->action['url']))
                            <a href="{{ $gap->action['url'] }}" wire:navigate class="btn ml-5 sm:ml-0">Düzelt</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div data-brand-audit>
        <div class="section-head">
            <h2 class="section-title" title="AI'ın bu marka için yaptıklarını markanın kendi verisiyle karşılaştırır; yalnız hata arar, yeni iş üretmez. AI kullanmaz. &quot;Düzelt&quot; yalnız yanlış AI kararını geri alır. Her pazartesi Şef planından önce çalışır.">Şef denetimi · {{ $audit->count() }} hata</h2>
            @if ($auditedAt)<span class="text-[13px] text-gray-500">Son denetim: {{ \Illuminate\Support\Carbon::parse($auditedAt)->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>@endif
            <button type="button" wire:click="auditNow" wire:loading.attr="disabled" class="btn ml-auto" data-audit-now>Şimdi denetle</button>
        </div>
        @if ($audit->isNotEmpty())
            <ul class="card">
                @foreach ($audit as $finding)
                    <li class="card-row flex-wrap items-start sm:flex-nowrap" data-audit-finding="{{ $finding->id }}">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-error-500" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $finding->title }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $finding->reason }}</p>
                            <ul class="mt-1 list-disc pl-4 text-xs text-gray-500">@foreach ((array) data_get($finding->action, 'examples', []) as $example)<li>{{ $example }}</li>@endforeach</ul>
                        </div>
                        <div class="ml-5 flex shrink-0 gap-2 sm:ml-0">
                            <button type="button" wire:click="fixAudit({{ $finding->id }})" wire:loading.attr="disabled" wire:confirm="Yanlış AI kararları geri alınsın mı?" class="btn" data-fix-audit>Düzelt</button>
                            <button type="button" wire:click="acceptAudit({{ $finding->id }})" class="btn-quiet" data-accept-audit>Doğru, bırak</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="card px-4 py-3.5 text-[13px] text-gray-500">{{ $auditedAt ? 'Son denetimde AI kararlarında hata bulunmadı.' : 'Henüz denetim yok; her pazartesi ya da "Şimdi denetle" ile çalışır.' }}</p>
        @endif
    </div>

    <div data-brand-care>
        <div class="section-head">
            <h2 class="section-title" title="Her pazar gecesi bu dosyayı okur; dosyada değişiklik yoksa çalışmaz (maliyet yok). İşleri iş listesine ekler, dışarıya hiçbir şey yazmaz.">Bakım ajanı</h2>
            @if (! empty($care['reviewed_at']))
                <span class="text-[13px] text-gray-500">Son inceleme: {{ \Illuminate\Support\Carbon::parse($care['reviewed_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
            @endif
            @if ($operational)
                <button type="button" wire:click="reviewNow" wire:loading.attr="disabled" class="btn ml-auto">Şimdi incele</button>
            @endif
        </div>
        <div class="card">
            @if (! $operational)
                <p class="px-4 py-3.5 text-[13px] text-gray-500">Marka aktif değil; bakım ajanı yalnız aktif markalarda çalışır.</p>
            @elseif ($care === null || empty($care['reviewed_at']))
                <p class="px-4 py-3.5 text-[13px] text-gray-500">Henüz inceleme yok; ilk inceleme pazar gecesi ya da "Şimdi incele" ile.</p>
            @else
                @if (! empty($care['summary']))
                    <p class="px-4 py-3.5 text-sm text-gray-900 dark:text-white">{{ $care['summary'] }}</p>
                @endif
                @if ($careTasks->isNotEmpty())
                    <ul>
                        @foreach ($careTasks as $task)
                            <li class="border-t border-gray-200 px-4 py-3 first:border-t-0 dark:border-gray-800" data-care-task="{{ $task->id }}">
                                <p class="text-sm text-gray-900 dark:text-white">{{ $task->title }} <span class="ml-1 text-xs text-gray-500">{{ $task->channelLabel() }}</span></p>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ $task->reason }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (! empty($care['questions']))
                    <div class="border-t border-gray-200 bg-warning-50/60 px-4 py-3 text-[13px] text-warning-900 dark:border-gray-800 dark:bg-warning-500/10 dark:text-warning-300">
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
                <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-warning-700 first:border-t-0 dark:border-gray-800 dark:text-warning-400" data-care-blocked>{{ $care['blocked'] }}</p>
            @endif
            @if (! empty($care['failed_at']) && (empty($care['reviewed_at']) || $care['failed_at'] > $care['reviewed_at']))
                <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-error-700 first:border-t-0 dark:border-gray-800 dark:text-error-400">Son inceleme tamamlanamadı: {{ $care['error'] ?? '' }}</p>
            @endif
        </div>
    </div>

    <div>
        <div class="section-head">
            <h2 class="section-title" title="AI ajanlarının her işte ilk okuduğu kısa özet. Sistem verilerden kendisi derler (AI kullanmaz); her gece ve Otomatik kur sonrası yenilenir.">Bilgi dosyası</h2>
            @if ($dossier['built_at'])
                <span class="text-[13px] text-gray-500">Son derleme: {{ \Illuminate\Support\Carbon::parse($dossier['built_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
            @endif
            <button type="button" wire:click="rebuild" wire:loading.attr="disabled" class="btn ml-auto">Yenile</button>
        </div>

        <div class="card">
            @foreach ($dossier['sections'] as $key => $section)
                <div class="border-t border-gray-200 px-5 py-4 first:border-t-0 dark:border-gray-800" data-dossier-section="{{ $key }}">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $section['title'] }}</h3>
                    <div class="prose prose-sm mt-1 max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">{!! \Illuminate\Support\Str::markdown($section['markdown'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div data-dossier-notes>
        <div class="section-head">
            <h2 class="section-title" title="Dosyada elle yazılan kısım. Ajanlar hedefe göre öncelik verir, kısıtlardaki şeyleri asla önermez.">Hedefler ve kısıtlar</h2>
            <a href="{{ route('operator.brand', ['brand' => $brandId, 'tab' => 'ayarlar']) }}" wire:navigate class="btn-quiet ml-auto h-7! px-2!">Düzenle</a>
        </div>
        <dl class="card grid text-sm md:grid-cols-2">
            <div class="px-5 py-4"><dt class="text-[13px] text-gray-500">Hedefler</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900 dark:text-white">{{ $notes['goals'] !== '' ? $notes['goals'] : '—' }}</dd></div>
            <div class="border-t border-gray-200 px-5 py-4 md:border-t-0 md:border-l dark:border-gray-800"><dt class="text-[13px] text-gray-500">Kısıtlar</dt><dd class="mt-0.5 whitespace-pre-line text-gray-900 dark:text-white">{{ $notes['constraints'] !== '' ? $notes['constraints'] : '—' }}</dd></div>
        </dl>
    </div>
</section>
