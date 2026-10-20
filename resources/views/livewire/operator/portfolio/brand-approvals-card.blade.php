{{-- Bekleyen onaylar (panel önerileri §3, 2026-10-11): the brand's prepared fixes waiting on the Onarım masası, counted in packages, the most expected gain first. --}}
<div>
    @if ($approvals !== null)
        <section aria-labelledby="approvals-heading" data-brand-approvals="{{ $approvals['total'] }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="approvals-heading" class="section-title">Bekleyen onaylar <span class="text-sm font-normal text-gray-500">{{ $approvals['total'] }}</span></h2>
                @if ($approvals['total'] > 0)
                    <a href="{{ $approvals['url'] }}" wire:navigate class="inline-flex h-8 items-center rounded-lg px-3 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-800" data-approvals-open>Onarım masasında aç</a>
                @endif
            </div>
            @if ($approvals['total'] === 0)
                <p class="mt-1 text-xs text-gray-500" data-approvals-empty>Bu markada onay bekleyen düzeltme yok.</p>
            @else
                <ul class="mt-2 divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                    @foreach ($approvals['packages'] as $package)
                        <li wire:key="approval-{{ $package['key'] }}">
                            <a href="{{ $package['url'] }}" wire:navigate class="-mx-2 flex items-center justify-between gap-3 rounded-md px-2 py-2.5 hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-approval-package="{{ $package['key'] }}">
                                <span class="min-w-0 truncate font-medium text-gray-800 dark:text-gray-200">{{ $package['label'] }}</span>
                                <span class="shrink-0 text-xs text-gray-500"><span class="tabular-nums font-semibold text-gray-900 dark:text-white">{{ $package['count'] }}</span> {{ $package['lane'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($approvals['total'] > count($approvals['packages']))
                    <a href="{{ $approvals['url'] }}" wire:navigate class="link-neutral mt-2 inline-block text-xs">+{{ $approvals['total'] - count($approvals['packages']) }} paket daha ({{ $approvals['rows'] }} düzeltme)</a>
                @endif
            @endif
        </section>
    @endif
</div>
