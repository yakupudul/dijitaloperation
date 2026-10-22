<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>MoxDOP bağlantı onayı</title>
    <style>
        :root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f9fafb; --card: #fff; --brand: #111827; --bad: #e11d48; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        main { max-width: 480px; margin: 48px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 22px; }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p { color: var(--muted); font-size: 14px; margin: 0 0 16px; }
        .err { color: var(--bad); }
        label { display: flex; gap: 10px; align-items: flex-start; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; margin-bottom: 8px; font-size: 14px; cursor: pointer; }
        label:has(input:checked) { border-color: var(--ink); }
        label small { display: block; color: var(--muted); }
        .actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
        button { border-radius: 8px; padding: 10px 18px; font-size: 15px; font-weight: 600; cursor: pointer; border: 1px solid var(--ink); }
        .primary { background: var(--brand); color: #fff; }
        .ghost { background: #fff; color: var(--ink); border-color: var(--line); }
    </style>
</head>
<body>
<main>
    <div class="card">
        @isset($error)
            <h1>Bağlantı kurulamadı</h1>
            <p class="err">{{ $error }}</p>
        @else
            <h1>{{ $client->name }} MoxDOP'a bağlanmak istiyor</h1>
            <p>Onaylarsan bu bağlantı için bir MCP anahtarı üretilir. Anahtarı istediğin an Ayarlar › MCP anahtarları'ndan iptal edebilirsin.</p>
            <form method="post" action="{{ route('mcp.oauth.approve') }}">
                @csrf
                @foreach ($params as $name => $value)
                    @if ($name !== 'scope')
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <label><input type="radio" name="scope" value="write" @checked($scope === 'write')><span>Okuma ve yazma<small>AI iş kuyruğunu çalıştırır, sonuçları MoxDOP'a yazar.</small></span></label>
                <label><input type="radio" name="scope" value="read" @checked($scope === 'read')><span>Yalnız okuma<small>Verileri okur, hiçbir şeyi değiştiremez.</small></span></label>
                <div class="actions">
                    <button type="submit" name="decision" value="approve" class="primary">Onayla</button>
                    <button type="submit" name="decision" value="deny" class="ghost">Vazgeç</button>
                </div>
            </form>
        @endisset
    </div>
</main>
</body>
</html>
