@if ($message !== '')
    <p role="status" @class(['rounded-lg px-3 py-2 text-sm', 'tone-info' => $messageTone !== 'error', 'tone-critical' => $messageTone === 'error'])>{{ $message }}</p>
@endif
