@php
    $raw = (string) ($error['message'] ?? '');
    $plain = \App\Services\WhatsApp\WhatsAppErrorText::explain($error);
    $codes = array_filter([
        isset($error['http_status']) ? 'HTTP '.$error['http_status'] : null,
        isset($error['code']) ? 'Meta kodu '.$error['code'] : null,
        isset($error['subcode']) ? 'alt kod '.$error['subcode'] : null,
        ! empty($error['meta_step']) ? 'adım '.$error['meta_step'] : null,
        ! empty($error['error_id']) ? 'hata no '.$error['error_id'] : null,
        ! empty($error['trace_id']) ? 'takip '.$error['trace_id'] : null,
        ! empty($error['session_id']) ? 'oturum '.$error['session_id'] : null,
    ]);
@endphp
<div class="text-sm">
    @if(!empty($label))<p class="font-medium">{{ $label }}</p>@endif
    <p class="break-words">{{ $plain }}</p>
    @if(($raw !== '' && $raw !== $plain) || $codes !== [])
        <details class="mt-1 text-xs text-gray-500">
            <summary class="cursor-pointer select-none">Meta'nın teknik mesajı</summary>
            @if($raw !== '' && $raw !== $plain)<p class="mt-1 break-words">{{ $raw }}</p>@endif
            @if($codes !== [])<p class="mt-1">{{ implode(' · ', $codes) }}</p>@endif
        </details>
    @endif
</div>
