@php
    $when = fn (?string $at): string => $at ? \Illuminate\Support\Carbon::parse($at)->timezone('Europe/Istanbul')->format('d.m H:i') : '';
    $title = 'text-sm font-medium text-gray-900 dark:text-white';
    $line = 'mt-0.5 text-[13px] text-gray-500 dark:text-gray-400';
@endphp
<div class="space-y-10 dark:text-gray-200" data-releases>
    <header>
        <h1 class="page-title" title="Canlıda hangi sürümün çalıştığı, her deploy'la canlıya çıkan değişiklikler ve dala gönderilip henüz canlıya çıkmayanlar.">Sürümler</h1>
        <p class="page-sub" data-live><span class="text-gray-700 dark:text-gray-300">Canlı sürüm:</span> <span class="font-mono text-[13px] text-gray-900 dark:text-white">{{ $release['sha'] ? substr($release['sha'], 0, 8) : 'bilinmiyor' }}</span>@if ($release['deployed_at']), {{ $when($release['deployed_at']) }} deploy edildi @endif</p>
    </header>

    {{-- 1. Does what is pushed reach the live server by itself? --}}
    <section class="-mt-4">
        {{-- Canlı panel (2026-10-20): a missing or failing auto deploy is a tinted warning card, a working one a plain card. --}}
        <div @class(['card' => $autoDeploy !== null && ! $autoDeploy['problem'],
            'rounded-3xl border soft-red' => $autoDeploy !== null && $autoDeploy['problem'],
            'rounded-3xl border soft-amber' => $autoDeploy === null])>
            @if ($autoDeploy !== null)
                <div class="card-row flex-wrap items-start sm:flex-nowrap" data-auto-deploy="{{ $autoDeploy['state'] }}">
                    <span @class(['row-icon' => ! $autoDeploy['problem'], 'soft-icon text-error-600 dark:text-error-400' => $autoDeploy['problem']]) aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p @class([$title, 'text-error-700! dark:text-error-300!' => $autoDeploy['problem']])>Otomatik deploy: {{ $autoDeploy['label'] }}</p>
                        @if (($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle') || $autoDeploy['checked_at'])
                            <p class="{{ $line }}">@if ($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle'){{ $autoDeploy['message'] }}@if ($autoDeploy['checked_at']), @endif @endif @if ($autoDeploy['checked_at'])son kontrol {{ $when($autoDeploy['checked_at']) }}@endif</p>
                        @endif
                        @if ($autoDeploy['state'] === 'tests_failed' && $failure)
                            <details class="mt-2 text-xs" data-auto-deploy-failure>
                                <summary class="cursor-pointer font-medium text-error-700 dark:text-error-300">Geçmeyen testlerin ayrıntısı</summary>
                                <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-gray-700 dark:bg-white/[0.03] dark:text-gray-300">{{ $failure }}</pre>
                            </details>
                        @endif
                    </div>
                </div>
            @else
                <div class="card-row items-start">
                    <span class="soft-icon text-warning-700 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                    <div class="min-w-0 flex-1">
                        <p class="{{ $title }}">Otomatik deploy sunucuda kurulu değil</p>
                        <p class="{{ $line }}">Canlıya almak için sunucuda <code class="rounded bg-white/80 px-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-200">bash deploy/staging/auto-deploy.sh --install</code> bir kez çalıştırılmalı.</p>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <section data-pending>
        <div class="section-head"><h2 class="section-title">Henüz canlıda değil <span class="section-count" data-pending-total="{{ count($pending) }}">{{ count($pending) }}</span></h2></div>
        <ul class="card">
            @forelse ($pending as $commit)
                <li wire:key="p-{{ $commit['sha'] }}" class="card-row flex-wrap items-baseline gap-y-1 sm:flex-nowrap">
                    <span class="h-1.5 w-1.5 shrink-0 self-center rounded-full bg-warning-500" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $commit['subject'] }}</span>
                    <span class="w-full pl-5 text-xs text-gray-500 sm:w-auto sm:pl-0">{{ $when($commit['at']) }}, {{ $commit['branch'] }} <span class="font-mono">{{ substr($commit['sha'], 0, 8) }}</span></span>
                </li>
            @empty
                <li><x-ta.empty-state size="sm" :icon="$autoDeploy !== null ? 'check' : 'none'" :title="$autoDeploy !== null ? 'Dala gönderilen her şey canlıda.' : 'Otomatik deploy kurulunca burada bekleyen değişiklikler görünür.'" /></li>
            @endforelse
        </ul>
    </section>

    <section data-deploys>
        <div class="section-head"><h2 class="section-title">Canlıya çıkanlar <span class="section-count">{{ count($deploys) }}</span></h2></div>
        <div class="card">
            @forelse ($deploys as $deploy)
                <article wire:key="d-{{ $deploy['sha'] }}-{{ $deploy['deployed_at'] }}" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                    <button type="button" x-on:click="open = ! open" class="flex w-full items-center gap-3.5 px-4 py-3.5 text-left hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                        <svg class="h-4 w-4 shrink-0 text-gray-400 transition" x-bind:class="open && 'rotate-90'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        <span class="min-w-0 flex-1">
                            <span class="block {{ $title }} tabular-nums">{{ $when($deploy['deployed_at']) }}</span>
                            <span class="block {{ $line }}">{{ count($deploy['commits']) }} değişiklik</span>
                        </span>
                        <span class="font-mono text-xs text-gray-500">sürüm {{ substr($deploy['sha'], 0, 8) }}</span>
                    </button>
                    <ul x-show="open" x-cloak class="space-y-1.5 border-t border-gray-100 bg-gray-50/60 py-3 pr-4 pl-12 text-[13px] dark:border-gray-800 dark:bg-white/[0.02]">
                        @foreach ($deploy['commits'] as $commit)
                            <li class="flex flex-wrap items-baseline gap-x-3"><span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">{{ $commit['subject'] }}</span><span class="font-mono text-xs text-gray-400">{{ substr($commit['sha'], 0, 8) }}</span></li>
                        @endforeach
                    </ul>
                </article>
            @empty
                <x-ta.empty-state size="sm" icon="none" title="Deploy geçmişi bir sonraki deploy'dan itibaren tutulur." />
            @endforelse
        </div>
    </section>

    <p class="text-[13px]"><a href="{{ route('operator.settings') }}" wire:navigate class="link-neutral">Ayarlar</a></p>
</div>
