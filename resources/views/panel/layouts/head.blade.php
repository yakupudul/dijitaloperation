<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? 'Panel' }} | {{ config('panel.name') }}</title>
<link rel="icon" type="image/png" href="/images/app/favicon-64.png?v=2" />
@vite(['resources/css/operator.css', 'resources/js/operator.js'])
@include('operator.partials.theme-colors')
