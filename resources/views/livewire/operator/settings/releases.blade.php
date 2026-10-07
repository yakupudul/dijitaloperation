@php
    $when = fn (?string $at): string => $at ? \Illuminate\Support\Carbon::parse($at)->timezone('Europe/Istanbul')->format('d.m H:i') : '';
@endphp
<div class="space-y-5 dark:text-gray-200" data-releases>
    <header>
        <a href="{{ route('operator.settings') }}" wire:navigate class="text-xs text-gray-500">← Ayarlar</a>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Sürümler</h1>
        <p class="mt-1 text-sm text-gray-500">Canlıda hangi sürümün çalıştığı, her deploy'la canlıya çıkan değişiklikler ve dala gönderilip henüz canlıya çıkmayanlar.</p>
    </header>

    <section class="rounded-xl bg-white p-4 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800" data-live>
        <p class="text-gray-900 dark:text-white"><span class="font-semibold">Canlı sürüm:</span> {{ $release['sha'] ? substr($release['sha'], 0, 8) : 'bilinmiyor' }}@if($release['deployed_at']) · {{ $when($release['deployed_at']) }} deploy edildi @endif</p>
        @if($autoDeploy !== null)
            <p @class(['mt-1', 'text-rose-700 dark:text-rose-300' => $autoDeploy['problem'], 'text-gray-600 dark:text-gray-400' => ! $autoDeploy['problem']]) data-auto-deploy="{{ $autoDeploy['state'] }}">
                Otomatik deploy: {{ $autoDeploy['label'] }}@if($autoDeploy['message'] !== '' && $autoDeploy['state'] !== 'idle') · {{ $autoDeploy['message'] }}@endif
                @if($autoDeploy['checked_at'])<span class="text-xs opacity-70"> · son kontrol {{ $when($autoDeploy['checked_at']) }}</span>@endif
            </p>
            @if($autoDeploy['state'] === 'tests_failed' && $failure)
                <details class="mt-2 text-xs" data-auto-deploy-failure>
                    <summary class="cursor-pointer font-semibold text-rose-700 dark:text-rose-300">Geçmeyen testlerin ayrıntısı</summary>
                    <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ $failure }}</pre>
                </details>
            @endif
        @else
            <p class="mt-1 text-amber-700 dark:text-amber-300">Otomatik deploy sunucuda kurulu değil; canlıya almak için sunucuda <code>bash deploy/staging/auto-deploy.sh --install</code> bir kez çalıştırılmalı.</p>
        @endif
    </section>

    <section class="space-y-2" data-pending>
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Henüz canlıda değil ({{ count($pending) }})</h2>
        @forelse($pending as $commit)
            <div wire:key="p-{{ $commit['sha'] }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-lg bg-amber-50 px-3 py-2 text-sm dark:bg-amber-500/10">
                <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $commit['subject'] }}</span>
                <span class="text-xs text-gray-500">{{ substr($commit['sha'], 0, 8) }} · {{ $when($commit['at']) }} · {{ $commit['branch'] }}</span>
            </div>
        @empty
            <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:bg-gray-900">{{ $autoDeploy !== null ? 'Dala gönderilen her şey canlıda.' : 'Otomatik deploy kurulunca burada bekleyen değişiklikler görünür.' }}</p>
        @endforelse
    </section>

    <section class="space-y-3" data-deploys>
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Canlıya çıkanlar</h2>
        @forelse($deploys as $deploy)
            <article wire:key="d-{{ $deploy['sha'] }}-{{ $deploy['deployed_at'] }}" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" class="rounded-xl bg-white p-3 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <button type="button" x-on:click="open = ! open" class="flex w-full flex-wrap items-baseline gap-x-3 text-left">
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $when($deploy['deployed_at']) }}</span>
                    <span class="text-gray-600 dark:text-gray-400">{{ count($deploy['commits']) }} değişiklik</span>
                    <span class="text-xs text-gray-400">sürüm {{ substr($deploy['sha'], 0, 8) }}</span>
                </button>
                <ul x-show="open" x-cloak class="mt-2 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
                    @foreach($deploy['commits'] as $commit)
                        <li class="flex flex-wrap items-baseline gap-x-3"><span class="min-w-0 flex-1">{{ $commit['subject'] }}</span><span class="text-xs text-gray-400">{{ substr($commit['sha'], 0, 8) }}</span></li>
                    @endforeach
                </ul>
            </article>
        @empty
            <p class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:bg-gray-900">Deploy geçmişi bir sonraki deploy'dan itibaren tutulur.</p>
        @endforelse
    </section>
</div>
