{{-- Panel colors from Ayarlar › Görünüm (Moximu logo colors by default); every accent utility reads these. --}}
@php $operatorTheme ??= app(\App\Services\Operator\AgencySettingService::class)->theme(); @endphp
<style>:root{--moxi-accent:{{ $operatorTheme['accent'] }};--moxi-accent-ink:{{ $operatorTheme['accent_ink'] }};--moxi-second:{{ $operatorTheme['second'] }}}</style>
