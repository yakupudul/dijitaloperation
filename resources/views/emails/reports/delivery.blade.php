<x-mail::message>
# {{ __('operator.reports.email_ready_heading') }}

**{{ $brandName }}** — {{ $title }}

{{ __('operator.reports.reporting_period') }}: {{ $periodStart }} → {{ $periodEnd }}

{{ __('operator.reports.email_secure_link_help') }}

<x-mail::button :url="$shareUrl">
{{ __('operator.reports.email_open_secure_report') }}
</x-mail::button>

{{ __('operator.reports.email_verification_required') }}

{{ __('operator.reports.email_no_metrics_notice') }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
