{{-- ⓘ next to an AI action button: opens the prompt window (App\Livewire\Operator\AiPromptInfo) of the operation. --}}
@props(['operation'])
<button type="button" x-on:click.prevent.stop="Livewire.dispatch('ai-prompt-info', { operation: @js($operation) })"
    title="Bu AI işleminin promptu" aria-label="Prompt bilgisi: {{ $operation }}" data-ai-prompt-info="{{ $operation }}"
    {{ $attributes->class(['inline-flex h-6 w-6 shrink-0 items-center justify-center self-center rounded-full text-xs font-semibold text-gray-400 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-white']) }}>ⓘ</button>
