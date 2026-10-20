{{-- Teknik › llms.txt (yakup, 2026-10-07): a short map of the site for AI assistants, built by rules, sent with one click. --}}
<section class="min-w-0 text-sm" data-llms-txt>
    <div class="section-head justify-between">
        <div class="flex min-w-0 flex-[1_1_20rem] flex-wrap items-baseline gap-x-3 gap-y-0.5">
            <h2 class="section-title" title="Yapay zekâ asistanları için sitenin kısa haritası. MoxDOP verilerinden kuralla hazırlanır; sitede kendi llms.txt'si açıksa o geçerli kalır.">llms.txt</h2>
            <span class="text-[13px] text-gray-500 dark:text-gray-400">Yapay zekâ asistanları için sitenin kısa haritası.</span>
            <p class="sr-only">Yapay zekâ asistanları için sitenin kısa haritası: işletme, hizmet verilen yerler, iletişim, hizmet sayfaları, uzmanlar ve yazılar. MoxDOP'taki verilerden kuralla hazırlanır; eklenti <span class="font-mono">{{ $url }}</span> adresinde sunar. Sitede llms.txt dosyası ya da SEO eklentisinin kendi llms.txt'si açıksa o geçerli kalır.</p>
        </div>
        <div class="flex w-full shrink-0 items-center gap-1.5 sm:w-auto">
            <button type="button" wire:click="$toggle('preview')" class="btn-quiet">{{ $preview ? 'Gizle' : 'Önizle' }}</button>
            @if ($isAdmin)
                <button type="button" wire:click="send" wire:confirm="llms.txt siteye gönderilsin mi?" wire:loading.attr="disabled" class="btn-dark flex-1 sm:flex-none" data-llms-send>Siteye gönder</button>
                @if ($last?->isUndoable())
                    <button type="button" wire:click="undo({{ $last->id }})" wire:confirm="Önceki llms.txt'ye dönülsün mü?" class="btn-quiet hover:text-error-600!">Geri al</button>
                @endif
            @endif
        </div>
    </div>
    @if ($last)
        <p class="-mt-1 mb-2 text-xs text-gray-500" data-llms-last>Son gönderim {{ $last->created_at?->format('d.m.Y H:i') }}, {{ $last->statusLabel() }}</p>
    @endif
    @error('write')<p class="mb-2 text-xs text-error-600">{{ $message }}</p>@enderror
    @if ($message !== '')<p role="status" class="mb-2 text-xs text-success-700 dark:text-success-300">{{ $message }}</p>@endif
    @if ($preview)
        <pre class="card max-h-96 overflow-auto whitespace-pre-wrap px-4 py-3 font-mono text-xs text-gray-800 dark:text-gray-200" data-llms-preview>{{ $text }}</pre>
    @endif
</section>
