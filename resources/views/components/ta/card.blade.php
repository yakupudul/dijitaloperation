@props(['padding' => 'p-5 md:p-6'])

<div {{ $attributes->merge(['class' => "rounded-2xl border border-gray-200 bg-white $padding dark:border-gray-800 dark:bg-white/[0.03]"]) }}>
    {{ $slot }}
</div>
