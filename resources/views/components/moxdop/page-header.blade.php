@props([
    'title',
    'subtitle' => null,
])

{{--
    Generic page-level header (title + subtitle + actions).
    For per-asset workspace identity (brand/account/business/sync), use
    <x-moxdop.workspace-header> instead.
--}}
<div {{ $attributes->class(['mox-page-header']) }}>
    <div>
        <h2 class="mox-page-header__title">{{ $title }}</h2>

        @if (filled($subtitle))
            <p class="mox-page-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="mox-page-header__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
