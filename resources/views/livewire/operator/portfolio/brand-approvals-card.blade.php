{{-- Bekleyen onaylar (panel önerileri §3, 2026-10-11): the brand's prepared fixes waiting on the Onarım masası, counted in packages, the most expected gain first.
     Canlı panel (2026-10-20): one tinted card per package (the first one, the most gain, in the accent tone), the count big. --}}
<div>
    @if ($approvals !== null)
        <section aria-labelledby="approvals-heading" data-brand-approvals="{{ $approvals['total'] }}">
            <div class="section-head">
                {{-- The same count as the menu and Onarım masası: fixes, not packages (yakup 2026-10-20). --}}
                <h2 id="approvals-heading" class="section-title">Karar bekleyen <span class="section-count">{{ number_format($approvals['rows'], 0, ',', '.') }} iş</span></h2>
                @if ($approvals['total'] > 0)
                    <a href="{{ $approvals['url'] }}" wire:navigate class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white" data-approvals-open>Onarım masasında aç</a>
                @endif
            </div>
            @if ($approvals['packages'] === [])
                <div class="card" data-approvals-empty><x-ta.empty-state size="sm" title="Onay bekleyen iş yok" message="Bu markada onay bekleyen düzeltme yok." /></div>
            @else
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 wide:grid-cols-4">
                    @foreach ($approvals['packages'] as $package)
                        <li wire:key="approval-{{ $package['key'] }}" class="flex">
                            <a href="{{ $package['url'] }}" wire:navigate @class(['soft-card w-full', 'soft-accent' => $loop->first]) data-approval-package="{{ $package['key'] }}">
                                <span class="soft-icon max-sm:hidden" aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>
                                <span class="soft-number"><span class="tabular-nums">{{ $package['count'] }}</span> iş</span>
                                <span class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">{{ $package['label'] }}</span>
                                <span class="text-[13px] text-gray-600 dark:text-gray-300">{{ $package['lane'] }}</span>
                                <span class="mt-auto pt-1 max-sm:absolute max-sm:top-[18px] max-sm:right-[18px] max-sm:pt-0"><span @class(['btn-dark' => $loop->first, 'btn' => ! $loop->first])>Aç</span></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
