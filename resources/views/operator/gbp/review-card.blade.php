<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Google yorum kartı · {{ $branch }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 landscape; margin: 0; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        :root { --ink: #15171a; --grey: #5f6368; --line: #e3e3e3; --accent: {{ $accent }}; --teal: #0f5f5b; }
        html, body { background: #fff; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); -webkit-font-smoothing: antialiased; }

        .bar { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: center; justify-content: space-between; max-width: 248mm; margin: 0 auto; padding: 20px 16px 8px; }
        .bar p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--grey); max-width: 640px; }
        .bar p b { display: block; font-weight: 600; font-size: 16px; color: var(--ink); }
        .bar button { padding: 10px 18px; border: 0; border-radius: 8px; background: var(--teal); color: #fff; font: 600 14px Inter, sans-serif; cursor: pointer; }
        .bar button:hover { background: #0a4744; }
        .bar button:focus-visible { outline: 2px solid var(--teal); outline-offset: 3px; }

        .sheet { display: flex; flex-wrap: wrap; gap: 22mm; justify-content: center; padding: 14mm 16px 20mm; }

        /* A6 kart: 105 × 148 mm, kesim köşe işaretleriyle. Tasarım "Antet" (2026-11-19). */
        .card { position: relative; width: 105mm; height: 148mm; background: #fff; }
        .crop { position: absolute; width: 5mm; height: 5mm; pointer-events: none; }
        .crop::before, .crop::after { content: ""; position: absolute; background: #9aa0a6; }
        .crop::before { width: 4mm; height: .2mm; }
        .crop::after { width: .2mm; height: 4mm; }
        .tl { top: -5mm; left: -5mm; } .tl::before { bottom: 0; left: 0; } .tl::after { right: 0; top: 0; }
        .tr { top: -5mm; right: -5mm; } .tr::before { bottom: 0; right: 0; } .tr::after { left: 0; top: 0; }
        .bl { bottom: -5mm; left: -5mm; } .bl::before { top: 0; left: 0; } .bl::after { right: 0; bottom: 0; }
        .br { bottom: -5mm; right: -5mm; } .br::before { top: 0; right: 0; } .br::after { left: 0; bottom: 0; }

        .strip { height: 2.2mm; background: var(--accent); }
        .in { padding: 9mm 10mm 0; }
        .head { display: flex; align-items: center; gap: 3.5mm; height: 12mm; }
        .logo { flex: none; height: 12mm; max-width: 62mm; object-fit: contain; object-position: left center; }
        .who { min-width: 0; }
        .who[hidden] { display: none; }
        .brand { margin: 0; font-size: 11pt; font-weight: 600; line-height: 1.25; letter-spacing: -.1pt; }
        .where { margin: .8mm 0 0; font-size: 7.4pt; line-height: 1.35; color: var(--grey); }

        .ask { margin: 10mm 0 0; font-size: 20.4pt; font-weight: 300; line-height: 1.15; letter-spacing: -.45pt; max-width: 80mm; }
        .rate { display: flex; align-items: center; gap: 2.6mm; margin-top: 5mm; }
        .rate .g { width: 5.2mm; height: 5.2mm; flex: none; }
        .stars { display: flex; gap: .9mm; }
        .stars svg { width: 5mm; height: 5mm; display: block; }
        .why { margin: 4mm 0 0; font-size: 9pt; line-height: 1.5; color: var(--grey); max-width: 72mm; }

        .scan { display: flex; align-items: flex-end; gap: 5mm; margin-top: 8mm; }
        .qr { flex: none; width: 40mm; height: 40mm; margin-left: -1.6mm; }
        .qr svg { width: 100%; height: 100%; display: block; }
        .how { margin: 0 0 1.6mm; font-size: 7.8pt; line-height: 1.5; color: var(--grey); }

        .foot { position: absolute; left: 10mm; right: 10mm; bottom: 7mm; display: flex; justify-content: space-between; gap: 4mm; padding-top: 3mm; border-top: .2mm solid var(--line); font-size: 7.4pt; color: var(--grey); }

        @media screen { .card { box-shadow: 0 0 0 1px #e8eaed, 0 2px 6px rgba(60, 64, 67, .08), 0 14px 36px rgba(60, 64, 67, .10); } }
        @media print {
            .bar { display: none; }
            .sheet { min-height: 100vh; align-items: center; align-content: center; padding: 0; gap: 24mm; }
        }
    </style>
</head>
<body>
    <div class="bar">
        @if ($public)
            <p><b>{{ $business }} için Google yorum kartı</b>Yazdır’a basın: A4 yatay kâğıda iki kart çıkar. Matbaaya göndermek için yazdırma ekranında “PDF olarak kaydet”i seçin. Köşedeki işaretlerden kesip resepsiyondaki standa takın ya da elden verin.</p>
        @else
            <p><b>Google yorum kartı</b>A4 yatay kâğıda iki kart çıkar. Köşedeki işaretlerden kesip resepsiyondaki standa takın ya da elden verin.</p>
        @endif
        <button type="button" onclick="window.print()">Yazdır</button>
    </div>
    <div class="sheet">
        @for ($i = 0; $i < 2; $i++)
            <div class="card" data-review-card>
                <span class="crop tl"></span><span class="crop tr"></span><span class="crop bl"></span><span class="crop br"></span>
                <div class="strip"></div>
                <div class="in">
                    <div class="head">
                        @if ($logo)
                            <img class="logo" src="{{ $logo }}" alt="{{ $business }}" onload="if (this.naturalWidth / Math.max(1, this.naturalHeight) < 1.8) { this.nextElementSibling.hidden = false; }">
                        @endif
                        <div class="who" @if ($logo) hidden @endif>
                            <h1 class="brand">{{ $business }}</h1>
                            @if ($place !== '')
                                <p class="where">{{ $place }}</p>
                            @endif
                        </div>
                    </div>
                    <p class="ask">Deneyiminizi Google’da paylaşın</p>
                    <div class="rate" aria-hidden="true">
                        <svg class="g" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                        <span class="stars">
                            @for ($s = 0; $s < 5; $s++)
                                <svg viewBox="0 0 24 24"><path fill="#FBBC04" d="M12 2.6l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.4l-5.9 3.3 1.3-6.6-4.9-4.6 6.6-.8z"/></svg>
                            @endfor
                        </span>
                    </div>
                    <p class="why">{{ $why }}</p>
                    <div class="scan">
                        <div class="qr">
                            @if ($qr)
                                {!! $qr !!}
                            @endif
                        </div>
                        <p class="how">Telefonunuzun kamerasını koda tutun, yorum sayfası açılır.</p>
                    </div>
                </div>
                <div class="foot">
                    @if ($area !== '' || $phone !== '')
                        <span>{{ $area }}</span><span>{{ $phone }}</span>
                    @else
                        <span>{{ $business }}</span><span>Teşekkür ederiz</span>
                    @endif
                </div>
            </div>
        @endfor
    </div>
</body>
</html>
