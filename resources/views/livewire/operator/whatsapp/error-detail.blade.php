<div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
    <p class="font-medium">{{ $label }}</p>
    <p class="mt-1 break-words">{{ $error['message'] ?? 'Ayrıntı alınamadı.' }}</p>
    <p class="mt-1 text-xs text-gray-500">
        @if(isset($error['http_status'])) HTTP {{ $error['http_status'] }} @endif
        @if(isset($error['code'])) · Meta kodu {{ $error['code'] }} @endif
        @if(isset($error['subcode'])) · Alt kod {{ $error['subcode'] }} @endif
        @if(!empty($error['trace_id'])) · Takip: {{ $error['trace_id'] }} @endif
    </p>
</div>
