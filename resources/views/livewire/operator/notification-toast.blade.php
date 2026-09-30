<div wire:poll.60s="check" class="pointer-events-none fixed bottom-4 left-4 z-[100000]" data-notification-toast>
    @if ($toast)
        <div wire:key="toast-{{ $toast['id'] }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show" x-transition
            role="status" class="pointer-events-auto flex max-w-xs items-center gap-3 rounded-lg bg-gray-900 px-4 py-3 text-sm text-white shadow-lg">
            @if ($toast['url'])
                <a href="{{ $toast['url'] }}" wire:navigate class="font-medium hover:underline">{{ $toast['title'] }}</a>
            @else
                <span class="font-medium">{{ $toast['title'] }}</span>
            @endif
            <button type="button" x-on:click="show = false" aria-label="Kapat" class="text-gray-400 hover:text-white">×</button>
        </div>
    @endif
</div>
