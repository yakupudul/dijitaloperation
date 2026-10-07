{{-- Teknik › llms.txt (yakup, 2026-10-07): a short map of the site for AI assistants, built by rules, sent with one click. --}}
<section class="rounded-xl bg-white p-4 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-llms-txt>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="font-semibold text-gray-900 dark:text-white">llms.txt</h2>
            <p class="text-xs text-gray-500">Yapay zekâ asistanları için sitenin kısa haritası: işletme, hizmet verilen yerler, iletişim, hizmet sayfaları, uzmanlar ve yazılar. MoxDOP'taki verilerden kuralla hazırlanır; eklenti <span class="font-mono">{{ $url }}</span> adresinde sunar. Sitede llms.txt dosyası ya da SEO eklentisinin kendi llms.txt'si açıksa o geçerli kalır.</p>
            @if ($last)
                <p class="mt-1 text-xs text-gray-500" data-llms-last>Son gönderim {{ $last->created_at?->format('d.m.Y H:i') }} · {{ $last->statusLabel() }}</p>
            @endif
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button type="button" wire:click="$toggle('preview')" class="h-8 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">{{ $preview ? 'Gizle' : 'Önizle' }}</button>
            @if ($isAdmin)
                <button type="button" wire:click="send" wire:confirm="llms.txt siteye gönderilsin mi?" wire:loading.attr="disabled" class="h-8 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600" data-llms-send>Siteye gönder</button>
                @if ($last?->isUndoable())
                    <button type="button" wire:click="undo({{ $last->id }})" wire:confirm="Önceki llms.txt'ye dönülsün mü?" class="h-8 px-2 text-xs text-gray-500 hover:text-red-600">Geri al</button>
                @endif
            @endif
        </div>
    </div>
    @error('write')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
    @if ($message !== '')<p role="status" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($preview)
        <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-white/[0.03] dark:text-gray-200" data-llms-preview>{{ $text }}</pre>
    @endif
</section>
