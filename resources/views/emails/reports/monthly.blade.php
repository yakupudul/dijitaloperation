<x-mail::message>
# {{ $brand }} · {{ $month }} raporu

Merhaba,

{{ $brand }} için {{ $month }} ayının dijital performans raporu hazır.

@if ($summary !== '')
{{ $summary }}
@endif

<x-mail::button :url="$url">
Raporu aç
</x-mail::button>

Bağlantı {{ $days }} gün geçerlidir.

Saygılarımızla,<br>
{{ config('app.name') }}
</x-mail::message>
