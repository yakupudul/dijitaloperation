{{-- Bekleyen onaylar (panel önerileri §3, 2026-10-11): the brand's prepared fixes waiting on the Onarım masası, counted in packages, the most expected gain first. --}}
<div>
    @if ($approvals !== null)
        <section aria-labelledby="approvals-heading" data-brand-approvals="{{ $approvals['total'] }}">
            <div class="section-head">
                <h2 id="approvals-heading" class="section-title">Bekleyen onaylar <span class="section-count">{{ $approvals['total'] }}</span></h2>
                @if ($approvals['total'] > 0)
                    <a href="{{ $approvals['url'] }}" wire:navigate class="ml-auto text-[13px] font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white" data-approvals-open>Onarım masasında aç</a>
                @endif
            </div>
            <ul class="card">
                @forelse ($approvals['packages'] as $package)
                    <li wire:key="approval-{{ $package['key'] }}">
                        <a href="{{ $package['url'] }}" wire:navigate class="card-row hover:bg-gray-50 dark:hover:bg-white/[0.02]" data-approval-package="{{ $package['key'] }}">
                            <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $package['label'] }}</span>
                                <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400"><span class="tabular-nums">{{ $package['count'] }}</span> iş, {{ $package['lane'] }}</span>
                            </span>
                            <span class="btn">Aç</span>
                        </a>
                    </li>
                @empty
                    <li data-approvals-empty><x-ta.empty-state size="sm" title="Onay bekleyen iş yok" message="Bu markada onay bekleyen düzeltme yok." /></li>
                @endforelse
                @if ($approvals['total'] > count($approvals['packages']))
                    <li class="border-t border-gray-200 px-4 py-2.5 dark:border-gray-800"><a href="{{ $approvals['url'] }}" wire:navigate class="text-[13px] font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">+{{ $approvals['total'] - count($approvals['packages']) }} paket daha ({{ $approvals['rows'] }} düzeltme)</a></li>
                @endif
            </ul>
        </section>
    @endif
</div>
