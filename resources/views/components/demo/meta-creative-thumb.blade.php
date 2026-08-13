@props([
    'gradient' => 'slate',
    'name' => 'Creative',
])

@php
    $gradientClass = match ($gradient) {
        'trust', 'emerald' => 'from-emerald-500/50 via-teal-400/25 to-slate-200/80 dark:to-slate-900/80',
        'price', 'amber', 'orange' => 'from-amber-500/50 via-orange-400/25 to-stone-200/80 dark:to-stone-900/80',
        'transform', 'rose' => 'from-rose-500/50 via-pink-400/25 to-slate-200/80 dark:to-slate-900/80',
        'expert', 'blue' => 'from-blue-500/50 via-sky-400/25 to-slate-200/80 dark:to-slate-900/80',
        'violet' => 'from-violet-500/50 via-fuchsia-400/20 to-slate-200/80 dark:to-slate-900/80',
        'cyan' => 'from-cyan-500/45 via-teal-400/20 to-slate-200/80 dark:to-slate-900/80',
        default => 'from-slate-500/45 via-slate-400/20 to-slate-200/80 dark:to-slate-900/80',
    };
@endphp

<div
    {{ $attributes->merge([
        'class' => 'aspect-[4/5] w-full bg-gradient-to-br '.$gradientClass,
        'role' => 'img',
        'aria-label' => $name,
    ]) }}
></div>
