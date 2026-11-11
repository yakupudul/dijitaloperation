<div data-emergency-stop="{{ $mode }}">
    @if ($mode === 'banner')
        @if ($since !== null)
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-300" role="alert" data-emergency-stop-on>
                <p class="min-w-0"><b class="font-semibold">Acil durdurma açık.</b> {{ $since->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} tarihinden beri sitelere, İşletme Profillerine ve sosyal medyaya hiçbir şey gönderilmiyor. Onayladıkların sırada bekliyor.</p>
                @if ($isAdmin)
                    <button type="button" wire:click="resume" wire:loading.attr="disabled" class="btn-primary shrink-0">Devam ettir</button>
                @endif
            </div>
        @endif
    @elseif ($isAdmin)
        @if ($since === null)
            <button type="button" wire:click="stop" wire:confirm="Sitelere, İşletme Profillerine ve sosyal medyaya giden bütün gönderimler durdurulsun mu? Hiçbir şey silinmez; 'Devam ettir' deyince kaldığı yerden sürer." class="btn-quiet text-error-600 dark:text-error-400" data-emergency-stop-button>Acil durdur</button>
        @else
            <button type="button" wire:click="resume" class="btn-primary" data-emergency-resume-button>Gönderimleri devam ettir</button>
        @endif
    @endif
</div>
