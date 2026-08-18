<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Report verification</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 28rem; margin: 3rem auto; padding: 0 1rem; color: #111; }
        input { width: 100%; padding: .6rem; margin: .5rem 0 1rem; }
        button { padding: .6rem 1rem; }
        .muted { color: #666; font-size: .9rem; }
    </style>
</head>
<body>
    <h1>Verify email access</h1>
    <p class="muted">A verification code will be sent to {{ $maskedEmail }}. You cannot redirect this to another address.</p>
    @if (session('status') === 'verification_sent')
        <p>Verification code sent.</p>
    @endif
    @if ($errors->any())
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <form method="post" action="{{ route('reports.share.verify.request') }}">
        @csrf
        <button type="submit">Send verification code</button>
    </form>
    <form method="post" action="{{ route('reports.share.verify.submit') }}">
        @csrf
        <label>Code
            <input type="text" name="code" maxlength="6" pattern="[0-9]{6}" required autocomplete="one-time-code">
        </label>
        <button type="submit">Verify</button>
    </form>
</body>
</html>
