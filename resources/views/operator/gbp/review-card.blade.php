<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yorum kartı · {{ $branch }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 landscape; margin: 0; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        :root { --ink: #14171f; --muted: #6b7280; --line: #e7e2d8; --paper: #fbfaf7; --gold: #c9a24a; --tile: #ffffff; }
        .koyu { --ink: #f5f1e8; --muted: #a8a49b; --line: rgba(245, 241, 232, .16); --paper: #12151c; --gold: #d8b45e; --tile: #ffffff; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; background: #e9e7e2; color: #14171f; }
        .bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: center; padding: 14px 16px; font-size: 13px; color: #374151; }
        .bar a, .bar button { padding: 7px 14px; border-radius: 999px; font: 600 13px Inter, sans-serif; text-decoration: none; cursor: pointer; border: 1px solid #d1d5db; background: #fff; color: #111827; }
        .bar .on { background: #111827; color: #fff; border-color: #111827; }
        .bar button { background: #465fff; border-color: #465fff; color: #fff; }
        .sheet { display: flex; flex-wrap: wrap; gap: 14mm; justify-content: center; padding: 6mm 16px 16mm; }
        .card { position: relative; width: 105mm; height: 148mm; padding: 11mm 10mm 9mm; background: var(--paper); color: var(--ink); display: flex; flex-direction: column; align-items: center; text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,.06), 0 12px 32px rgba(0,0,0,.10); overflow: hidden; }
        .koyu .card { background: radial-gradient(120% 70% at 50% 0%, #1f2532 0%, #12151c 60%); }
        .card::before { content: ""; position: absolute; inset: 4mm; border: .3mm solid var(--line); pointer-events: none; }
        .logo { height: 11mm; max-width: 46mm; object-fit: contain; }
        .koyu .logo { padding: 1.6mm 3mm; background: #fff; border-radius: 2mm; height: 12mm; }
        .brand { margin: 0; font-family: Fraunces, Georgia, serif; font-weight: 600; font-size: 19pt; line-height: 1.12; letter-spacing: -.2pt; }
        .logo + .brand { margin-top: 3.5mm; font-size: 14pt; }
        .branch { margin: 1.6mm 0 0; font-size: 6.6pt; font-weight: 600; letter-spacing: 1.4pt; text-transform: uppercase; color: var(--muted); max-width: 80mm; line-height: 1.5; }
        .rule { display: flex; align-items: center; gap: 3mm; width: 100%; margin: 5mm 0 4.2mm; color: var(--gold); font-size: 10.5pt; letter-spacing: 1.6pt; }
        .rule::before, .rule::after { content: ""; flex: 1; height: .3mm; background: linear-gradient(90deg, transparent, var(--gold)); opacity: .55; }
        .rule::after { background: linear-gradient(270deg, transparent, var(--gold)); }
        .ask { margin: 0; font-family: Fraunces, Georgia, serif; font-style: italic; font-weight: 400; font-size: 14pt; line-height: 1.22; }
        .tile { position: relative; margin-top: 5mm; padding: 2.2mm; background: var(--tile); border-radius: 4mm; box-shadow: 0 0 0 .3mm rgba(20,23,31,.08); }
        .koyu .tile { box-shadow: 0 6px 22px rgba(0,0,0,.45); }
        .qr { width: 43mm; height: 43mm; display: block; }
        .qr svg { width: 100%; height: 100%; display: block; }
        .corner { position: absolute; width: 5mm; height: 5mm; border: .55mm solid var(--gold); }
        .c1 { top: -2.2mm; left: -2.2mm; border-right: 0; border-bottom: 0; border-top-left-radius: 2.2mm; }
        .c2 { top: -2.2mm; right: -2.2mm; border-left: 0; border-bottom: 0; border-top-right-radius: 2.2mm; }
        .c3 { bottom: -2.2mm; left: -2.2mm; border-right: 0; border-top: 0; border-bottom-left-radius: 2.2mm; }
        .c4 { bottom: -2.2mm; right: -2.2mm; border-left: 0; border-top: 0; border-bottom-right-radius: 2.2mm; }
        .google { display: inline-flex; align-items: center; gap: 1.6mm; margin-top: 4.2mm; font-size: 7.6pt; font-weight: 600; color: var(--ink); }
        .google svg { width: 3.6mm; height: 3.6mm; }
        .steps { display: flex; gap: 2mm; margin-top: auto; font-size: 6.4pt; color: var(--muted); }
        .steps span { white-space: nowrap; }
        .steps b { display: inline-grid; place-items: center; width: 3.4mm; height: 3.4mm; margin-right: .8mm; border-radius: 50%; border: .25mm solid var(--gold); color: var(--gold); font-size: 5.4pt; font-weight: 600; }
        .thanks { margin: 3mm 0 0; font-family: Fraunces, Georgia, serif; font-style: italic; font-size: 11pt; color: var(--gold); }
        @media print {
            body { background: #fff; }
            .bar { display: none; }
            .sheet { min-height: 100vh; align-items: center; align-content: center; padding: 0; gap: 18mm; }
            .card { box-shadow: none; outline: .2mm dashed #c7c7c7; outline-offset: 3mm; }
        }
    </style>
</head>
<body class="{{ $theme }}">
    <div class="bar">
        <span>İki kart A4 yatay; yazdırıp kesik çizgiden kesin, kasaya, bekleme salonuna ya da masaya koyun.</span>
        <a href="?tema=acik" @class(['on' => $theme === 'acik'])>Açık</a>
        <a href="?tema=koyu" @class(['on' => $theme === 'koyu'])>Koyu</a>
        <button type="button" onclick="window.print()">Yazdır</button>
    </div>
    <div class="sheet">
        @for ($i = 0; $i < 2; $i++)
            <div class="card" data-review-card>
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="{{ $business }}">
                @endif
                <h1 class="brand">{{ $business }}</h1>
                <p class="branch">{{ $branch }}@if ($area !== '' && ! str_contains(mb_strtolower($branch), mb_strtolower($area))) · {{ $area }}@endif</p>
                <div class="rule">★★★★★</div>
                <p class="ask">Memnun kaldıysanız<br>Google’da yorum bırakır mısınız?</p>
                <div class="tile">
                    <span class="corner c1"></span><span class="corner c2"></span><span class="corner c3"></span><span class="corner c4"></span>
                    <div class="qr">
                        @if ($qr)
                            {!! $qr !!}
                        @endif
                    </div>
                </div>
                <div class="google">
                    <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                    Google yorumları
                </div>
                <div class="steps"><span><b>1</b>Kamerayı açın</span><span><b>2</b>QR koda tutun</span><span><b>3</b>Yıldızları seçin</span></div>
                <p class="thanks">Teşekkür ederiz</p>
            </div>
        @endfor
    </div>
</body>
</html>
