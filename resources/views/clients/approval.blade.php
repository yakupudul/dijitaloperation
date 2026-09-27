<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $approval->brand?->name }} · İçerik onayı</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
        h1 { font-size: 20px; margin: 0 0 4px; } .muted { color: #6b7280; font-size: 13px; }
        .content { white-space: pre-line; margin: 16px 0; line-height: 1.55; }
        textarea { width: 100%; box-sizing: border-box; min-height: 90px; border: 1px solid #d1d5db; border-radius: 8px; padding: 8px; font: inherit; }
        .row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        button { border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .ok { background: #16a34a; color: #fff; } .change { background: #fff; color: #b45309; border: 1px solid #f59e0b; }
        .note { border-radius: 8px; padding: 10px 12px; font-size: 14px; margin-top: 12px; }
        .done { background: #ecfdf5; color: #065f46; } .warn { background: #fffbeb; color: #92400e; } .err { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <p class="muted">{{ $agency }} · {{ $approval->brand?->name }}</p>
        <h1>{{ $approval->title }}</h1>
        @if ($approval->body)<div class="content">{{ $approval->body }}</div>@endif

        @if ($approval->status === 'approved')
            <p class="note done">Onayınız alındı, teşekkürler. ({{ $approval->responded_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }})</p>
        @elseif ($approval->status === 'changes_requested')
            <p class="note warn">Değişiklik isteğiniz iletildi: “{{ $approval->client_note }}”</p>
        @elseif (! $approval->isOpen())
            <p class="note warn">Bu bağlantının süresi doldu. Lütfen ajansınızla iletişime geçin.</p>
        @else
            @if ($error)<p class="note err">{{ $error }}</p>@endif
            <form method="POST" action="{{ $approval->respondUrl() }}">
                @csrf
                <label class="muted" for="note">Not (değişiklik istiyorsanız zorunlu)</label>
                <textarea id="note" name="note" maxlength="2000">{{ old('note') }}</textarea>
                <div class="row">
                    <button class="ok" type="submit" name="decision" value="approved">Onaylıyorum</button>
                    <button class="change" type="submit" name="decision" value="changes_requested">Değişiklik istiyorum</button>
                </div>
            </form>
            <p class="muted" style="margin-top:12px">Bağlantı {{ $approval->expires_at->timezone('Europe/Istanbul')->format('d.m.Y') }} tarihine kadar geçerlidir.</p>
        @endif
    </div>
</div>
</body>
</html>
