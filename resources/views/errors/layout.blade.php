{{-- Türkçe hata sayfaları (Aşama 11, 2026-10-22): one plain page, no app assets, so it renders even when the app is broken. --}}
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · MoxDOP</title>
    <style>
        :root { --bg: #f7f6f1; --fg: #1d1c18; --muted: #6b685e; --accent: #f5c400; --card: #ffffff; --line: #e6e3d8; }
        @media (prefers-color-scheme: dark) { :root { --bg: #15140f; --fg: #f2f0e8; --muted: #a29f93; --card: #1f1e18; --line: #2f2d25; color-scheme: dark; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; padding: 24px 16px; }
        .card { max-width: 440px; width: 100%; background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 32px 28px; }
        .code { font-size: 13px; font-weight: 600; letter-spacing: .08em; color: var(--muted); }
        h1 { margin: 8px 0 10px; font-size: 24px; line-height: 1.25; text-wrap: balance; }
        h1 mark { background: linear-gradient(transparent 55%, var(--accent) 55%); color: inherit; padding: 0 2px; }
        p { margin: 0 0 22px; color: var(--muted); }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        a { display: inline-flex; align-items: center; height: 38px; padding: 0 16px; border-radius: 999px; font-weight: 600; text-decoration: none; }
        .main { background: var(--fg); color: var(--bg); }
        .quiet { color: var(--fg); border: 1px solid var(--line); }
    </style>
</head>
<body>
    <main class="card">
        <div class="code">@yield('code')</div>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <a class="main" href="{{ url('/') }}">Komuta'ya dön</a>
            <a class="quiet" href="javascript:history.back()">Geri git</a>
        </div>
    </main>
</body>
</html>
