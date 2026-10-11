{{-- Bekleyen onaylar (panel önerileri §3, 2026-10-11): the brand's prepared fixes waiting on the Onarım masası, counted in packages, the most expected gain first. --}}
<div>
    @if ($approvals !== null)
        <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" aria-labelledby="approvals-heading" data-brand-approvals="{{ $approvals['total'] }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="approvals-heading" class="text-sm font-semibold text-gray-900 dark:text-white">Bekleyen onaylar <span class="font-normal text-gray-400">{{ $approvals['total'] }}</span></h2>
                @if ($approvals['total'] > 0)
                    <a href="{{ $approvals['url'] }}" wire:navigate class="inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-800" data-approvals-open>Onarım masasında aç</a>
                @endif
            </div>
            @if ($approvals['total'] === 0)
                <p class="mt-1 text-xs text-gray-500" data-approvals-empty>Bu markada onay bekleyen düzeltme yok.</p>
            @else
                <ul class="mt-2 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                    @foreach ($approvals['packages'] as $package)
                        <li wire:key="approval-{{ $package['key'] }}">
                            <a href="{{ $package['url'] }}" wire:navigate class="flex items-center justify-between gap-3 py-2 hover:text-gray-900 dark:hover:text-white" data-approval-package="{{ $package['key'] }}">
                                <span class="min-w-0 truncate font-medium text-gray-800 dark:text-gray-200">{{ $package['label'] }}</span>
                                <span class="shrink-0 text-gray-500"><span class="tabular-nums font-semibold text-gray-900 dark:text-white">{{ $package['count'] }}</span> · {{ $package['lane'] }} →</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($approvals['total'] > count($approvals['packages']))
                    <a href="{{ $approvals['url'] }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-gray-600 hover:underline dark:text-gray-300">+{{ $approvals['total'] - count($approvals['packages']) }} paket daha ({{ $approvals['rows'] }} düzeltme) →</a>
                @endif
            @endif
        </section>
    @endif
</div>
