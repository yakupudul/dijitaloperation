<x-mail::message>
@if ($alerts !== [])
# Açık uyarılar

@foreach ($alerts as $alert)
**{{ $alert['title'] }}** ({{ $alert['severity'] }})
{{ $alert['brand'] }} · {{ $alert['asset'] }} — {{ $alert['message'] }}

@endforeach
@endif
# Bu haftanın en önemli işleri

@foreach ($items as $item)
**{{ $loop->iteration }}. {{ $item['title'] }}**
{{ $item['brand'] ?? '—' }} · {{ $item['channel'] }} · {{ $item['severity_label'] }}

@endforeach

<x-mail::button :url="$url">
Panoyu aç
</x-mail::button>

Bu e-posta yalnızca ajans içi kullanım içindir.<br>
{{ config('app.name') }}
</x-mail::message>
