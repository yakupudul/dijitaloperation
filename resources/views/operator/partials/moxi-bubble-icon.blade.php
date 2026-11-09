{{-- Moxi's chat-bubble icon (yakup 2026-11-09): the Moximu mark (four teal cones on yellow, thin yellow M lines) shaped
     as a speech bubble, the tail at the bottom left. With class "is-typing" the four cones bounce in turn like typing
     dots. Gradient ids are unique per include ($mid). --}}
@php($mid = 'mxb'.\Illuminate\Support\Str::random(6))
<svg class="mx-mark {{ $class ?? '' }}" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="{{ $mid }}" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3a9a94"/><stop offset=".45" stop-color="#1f6461"/><stop offset="1" stop-color="#0d3533"/></linearGradient>
    </defs>
    <path d="M14 0 H86 Q100 0 100 14 V72 Q100 86 86 86 H34 L10 100 L16 86 H14 Q0 86 0 72 V14 Q0 0 14 0Z" fill="#FDCF3C"/>
    <g class="mx-cones">
        <circle class="c1" cx="30" cy="25.5" r="16.5" fill="url(#{{ $mid }})"/>
        <circle class="c2" cx="70" cy="25.5" r="16.5" fill="url(#{{ $mid }})"/>
        <circle class="c3" cx="30" cy="60.5" r="16.5" fill="url(#{{ $mid }})"/>
        <circle class="c4" cx="70" cy="60.5" r="16.5" fill="url(#{{ $mid }})"/>
    </g>
    <path d="M30 25.5 V60.5 M70 25.5 V60.5 M30 25.5 L42 37.5 M70 25.5 L58 37.5" stroke="#FDCF3C" stroke-width="1.6" stroke-linecap="round" fill="none"/>
</svg>
