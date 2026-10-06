@if ($message !== '')
    <p role="status" @class(['rounded-lg p-3 text-sm', 'bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-200' => $messageTone !== 'error', 'bg-rose-50 text-rose-800 dark:bg-rose-950 dark:text-rose-200' => $messageTone === 'error'])>{{ $message }}</p>
@endif
