@props([
    'label',
    'helper' => null,
    'error' => null,
    'required' => false,
    'for' => null,
])

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    <label @if($for) for="{{ $for }}" @endif class="block text-sm font-medium text-gray-700 dark:text-gray-300">
        {{ $label }}
        @if ($required)
            <span class="text-error-500">*</span>
        @endif
    </label>
    {{ $slot }}
    @if ($helper)
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $helper }}</p>
    @endif
    @if ($error)
        <p class="text-xs text-error-500">{{ $error }}</p>
    @endif
</div>
