@php
    $when = fn (?string $at): string => $at ? \Illuminate\Support\Carbon::parse($at)->timezone('Europe/Istanbul')->format('d.m H:i') : '';
@endphp
<div class="space-y-10 dark:text-gray-200" data-releases>
    <header>
        <a href="{{ route('operator.settings') }}" wire:navigate class="text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white">Ayarlar</a>
        <h1 class="mt-1 text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Canlıda hangi sürümün çalıştığı, her deploy'la canlıya çıkan değişiklikler ve dala gönderilip henüz canlıya çıkmayanlar.">Sürümler</h1>
    </header>

    <section class="text-sm" data-live>
        <p class="text-gray-900 dark:text-white"><span class="font-semibold">Canlı sürüm:</span> {{ $release['sha'] ? substr($release['sha'], 0, 8) : 'bilinmiyor' }}@if($release['deployed_at']) · {{ $when($release['deployed_at']) }} deploy edildi @endif</p>
        @if($autoDeploy !== null)
            <p @class(['mt-1', 'text-error-700 dark:text-error-300' => $autoDeploy['problem'], 'text-gray-600 dark:text-gray-400' => ! $autoDeploy['problem']]) data-auto-deploy="{{ $autoDeploy['state'] }}">
                Otomatik deploy: {{ $autoDeploy['label'] }}@if($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle') · {{ $autoDeploy['message'] }}@endif
                @if($autoDeploy['checked_at'])<span class="text-xs text-gray-500">, son kontrol {{ $when($autoDeploy['checked_at']) }}</span>@endif
            </p>
            @if($autoDeploy['state'] === 'tests_failed' && $failure)
                <details class="mt-2 text-xs" data-auto-deploy-failure>
                    <summary class="cursor-pointer font-semibold text-error-700 dark:text-error-300">Geçmeyen testlerin ayrıntısı</summary>
                    <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $failure }}</pre>
                </details>
            @endif
        @else
            <p class="mt-1 text-warning-700 dark:text-warning-300">Otomatik deploy sunucuda kurulu değil; canlıya almak için sunucuda <code>bash deploy/staging/auto-deploy.sh --install</code> bir kez çalıştırılmalı.</p>
        @endif
    </section>

    <section data-pending>
        <h2 class="section-title">Henüz canlıda değil ({{ count($pending) }})</h2>
        <div class="mt-3 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
        @forelse($pending as $commit)
            <div wire:key="p-{{ $commit['sha'] }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2.5 text-sm">
                <span class="h-1.5 w-1.5 shrink-0 self-center rounded-full bg-warning-500"></span>
                <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $commit['subject'] }}</span>
                <span class="text-xs text-gray-500">{{ substr($commit['sha'], 0, 8) }}, {{ $when($commit['at']) }} · {{ $commit['branch'] }}</span>
            </div>
        @empty
            <p class="py-2.5 text-sm text-gray-500">{{ $autoDeploy !== null ? 'Dala gönderilen her şey canlıda.' : 'Otomatik deploy kurulunca burada bekleyen değişiklikler görünür.' }}</p>
        @endforelse
        </div>
    </section>

    <section data-deploys>
        <h2 class="section-title">Canlıya çıkanlar</h2>
        <div class="mt-3 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
        @forelse($deploys as $deploy)
            <article wire:key="d-{{ $deploy['sha'] }}-{{ $deploy['deployed_at'] }}" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="py-2.5 text-sm">
                <button type="button" x-on:click="open = ! open" class="flex w-full flex-wrap items-baseline gap-x-3 text-left">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $when($deploy['deployed_at']) }}</span>
                    <span class="text-gray-600 dark:text-gray-400">{{ count($deploy['commits']) }} değişiklik</span>
                    <span class="text-xs text-gray-500">sürüm {{ substr($deploy['sha'], 0, 8) }}</span>
                </button>
                <ul x-show="open" x-cloak class="mt-2 space-y-1 pl-4">
                    @foreach($deploy['commits'] as $commit)
                        <li class="flex flex-wrap items-baseline gap-x-3"><span class="min-w-0 flex-1">{{ $commit['subject'] }}</span><span class="text-xs text-gray-500">{{ substr($commit['sha'], 0, 8) }}</span></li>
                    @endforeach
                </ul>
            </article>
        @empty
            <p class="py-2.5 text-sm text-gray-500">Deploy geçmişi bir sonraki deploy'dan itibaren tutulur.</p>
        @endforelse
        </div>
    </section>
</div>
