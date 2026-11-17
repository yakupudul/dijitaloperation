@php
    // Sosyal medya (ADR-083; redesign 2026-11-17). The questions, one per tab: what goes out today and what waits for me
    // (Bugün), what does the week look like (Takvim), where is that post (Gönderiler), who wrote to us (Konuşmalar), did
    // it work (Sonuçlar). Nothing here grows with the number of posts: a day, a week, 25 rows, 24 tiles.
    $state = fn ($post): array => match ($post->status) {
        'draft' => ['Onay bekliyor', 'tone-look'],
        'approved' => ['Sırada', 'tone-info'],
        'published' => ['Yayında', 'tone-ok'],
        'partial' => ['Kısmen yayında', 'tone-critical'],
        'failed' => ['Yayınlanamadı', 'tone-critical'],
        'expired' => ['Onaylanmadı', 'tone-look'],
        'skipped' => ['Atlandı', 'tone-info'],
        default => [(string) $post->status, 'tone-info'],
    };
    $kindName = fn ($post): string => match ($post->kind) {
        'image' => 'Görsel', 'carousel' => 'Kaydırmalı', 'reels' => 'Video', 'story' => 'Hikâye', 'text' => 'Metin', default => (string) $post->kind,
    };
    $kindMark = fn ($post): string => match ($post->kind) { 'reels' => '▶', 'story' => '○', 'carousel' => '❐', 'text' => '≡', default => '□' };
    $place = function ($post) use ($line, $storyLine, $reelLine, $textLine): ?string {
        $inReels = $post->kind === 'reels' && $reelLine !== null;
        $at = array_search($post->id, match (true) { $post->kind === 'story' => $storyLine, $post->kind === 'text' => $textLine, $inReels => $reelLine, default => $line }, true);

        return $at === false ? null : match (true) { $post->kind === 'story' => 'Story sırasında ', $post->kind === 'text' => 'Metin sırasında ', $inReels => 'Video sırasında ', default => 'Sırada ' }.($at + 1).'.';
    };
    $meta = $accounts->whereIn('platform', ['instagram', 'facebook']);
    $dot = ['on' => 'bg-success-500', 'manual' => 'bg-warning-500', 'off' => 'bg-gray-300 dark:bg-gray-600'];
    $dotTitle = ['on' => 'bağlı, kendiliğinden paylaşır', 'manual' => 'bağlı ama elle paylaşım bekler', 'off' => 'bağlı değil'];
@endphp
<div class="dark:text-gray-200" data-social-page>
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-[220px] flex-1">
                <p class="mb-1 text-xs text-gray-500"><a href="{{ route('operator.marketing', ['kanal' => 'social']) }}" wire:navigate class="link-neutral" data-social-marketing-link>Pazarlama</a> › Sosyal medya</p>
                <h1 class="page-title">Sosyal medya</h1>
                <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[13px] text-gray-600 dark:text-gray-400" data-social-channels>
                    @foreach ($channels as $c)
                        <span class="inline-flex items-center gap-1.5" title="{{ $c['label'] }}: {{ $dotTitle[$c['state']] }}"><span class="h-1.5 w-1.5 rounded-full {{ $dot[$c['state']] }}"></span>{{ $c['label'] }}@if ($c['state'] === 'off') <span class="text-gray-400">(bağlı değil)</span>@endif</span>
                    @endforeach
                </p>
            </div>
            <div class="flex shrink-0 gap-2">
                <button type="button" wire:click="setTab('ayarlar')" @class(['btn h-9 px-3.5', 'border-gray-900 dark:border-white' => $tab === 'ayarlar']) data-social-settings>Ayarlar</button>
                <button type="button" wire:click="setTab('yeni')" class="btn-primary h-9 px-3.5" data-social-new>Yeni gönderi</button>
            </div>
        </header>

        <nav class="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0" role="tablist" aria-label="Sekmeler">
            @foreach ($tabs as $key => $label)
                @php $n = $key === 'bugun' ? $waitingTotal : ($key === 'konusmalar' ? $talkCount : 0); @endphp
                <button type="button" wire:click="setTab('{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @if ($tab === $key) aria-current="page" @endif data-social-tab="{{ $key }}">{{ $label }}@if ($n > 0) <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1 text-[11px] font-semibold text-accent-ink tabular-nums">{{ $n }}</span>@endif</button>
            @endforeach
        </nav>

        @if ($message !== '')
            <p role="status" @class(['rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800', 'tone-ok' => $messageTone === 'success', 'tone-look' => $messageTone === 'error', 'tone-info' => $messageTone === 'info']) data-social-message>{{ $message }}</p>
        @endif

        @if ($meta->isEmpty() && $tab !== 'ayarlar')
            <p class="tone-look rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800">Instagram ve Facebook henüz bağlı değil; gönderiler hazırlanır ama yayınlanamaz. <button type="button" wire:click="jump('ayarlar', '', 0, 'hesaplar')" class="underline">Hesapları bağlayın</button>.</p>
        @endif

        @if (in_array($tab, ['takvim', 'gonderiler', 'konusmalar'], true) && ! ($tab === 'gonderiler' && $mode === 'izgara'))
            <div class="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0" data-social-channel-filter>
                <button type="button" wire:click="setChannel('')" @class(['btn h-8', 'border-second bg-second-soft text-second' => $channel === ''])>Tüm kanallar</button>
                @foreach ($channelNames as $key => $label)
                    <button type="button" wire:click="setChannel('{{ $key }}')" @class(['btn h-8', 'border-second bg-second-soft text-second' => $channel === $key])>{{ $label }}</button>
                @endforeach
            </div>
        @endif

        <div wire:loading.class="opacity-60" wire:target="setTab,setChannel,shift,pickDay,turn,jump" class="space-y-6 transition-opacity">
            @if ($tab === 'bugun')
                @include('livewire.operator.social.partials.today')
            @elseif ($tab === 'takvim')
                @include('livewire.operator.social.partials.calendar')
            @elseif ($tab === 'gonderiler' && $mode === 'izgara')
                @include('livewire.operator.social.partials.posts-head')
                @include('livewire.operator.social.partials.grid')
            @elseif ($tab === 'gonderiler')
                @include('livewire.operator.social.partials.posts-head')
                @include('livewire.operator.social.partials.posts')
            @elseif ($tab === 'konusmalar')
                @include('livewire.operator.social.partials.talks')
            @elseif ($tab === 'sonuclar')
                @include('livewire.operator.social.partials.results')
            @elseif ($tab === 'yeni')
                @include('livewire.operator.social.partials.new')
            @else
                @include('livewire.operator.social.partials.settings')
            @endif
        </div>
    </div>

    @if ($opened !== null)
        @include('livewire.operator.social.partials.drawer')
    @endif
</div>
