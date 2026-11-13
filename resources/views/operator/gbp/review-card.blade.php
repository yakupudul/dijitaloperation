<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yorum kartı · {{ $branch }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 landscape; margin: 0; }
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        :root { --ink: #202124; --grey: #5f6368; --line: #dadce0; --star: #fbbc04; --teal: #0f5f5b; }
        html, body { background: #fff; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--ink); }

        .bar { display: flex; flex-wrap: wrap; gap: 12px 16px; align-items: center; justify-content: space-between; max-width: 248mm; margin: 0 auto; padding: 20px 16px 8px; }
        .bar p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--grey); }
        .bar p b { display: block; font-weight: 600; font-size: 16px; color: var(--ink); }
        .bar button { padding: 10px 18px; border: 0; border-radius: 8px; background: var(--teal); color: #fff; font: 600 14px Inter, sans-serif; cursor: pointer; }
        .bar button:hover { background: #0a4744; }
        .bar button:focus-visible { outline: 2px solid var(--teal); outline-offset: 3px; }

        .sheet { display: flex; flex-wrap: wrap; gap: 22mm; justify-content: center; padding: 14mm 16px 20mm; }

        /* A6 kart: 105 × 148 mm, kesim köşe işaretleriyle */
        .card { position: relative; width: 105mm; height: 148mm; padding: 11mm 10mm 9mm; background: #fff; display: flex; flex-direction: column; }
        .crop { position: absolute; width: 5mm; height: 5mm; pointer-events: none; }
        .crop::before, .crop::after { content: ""; position: absolute; background: #9aa0a6; }
        .crop::before { width: 4mm; height: .2mm; }
        .crop::after { width: .2mm; height: 4mm; }
        .tl { top: -5mm; left: -5mm; } .tl::before { bottom: 0; left: 0; } .tl::after { right: 0; top: 0; }
        .tr { top: -5mm; right: -5mm; } .tr::before { bottom: 0; right: 0; } .tr::after { left: 0; top: 0; }
        .bl { bottom: -5mm; left: -5mm; } .bl::before { top: 0; left: 0; } .bl::after { right: 0; bottom: 0; }
        .br { bottom: -5mm; right: -5mm; } .br::before { top: 0; right: 0; } .br::after { left: 0; bottom: 0; }

        .head { display: flex; align-items: center; gap: 3.5mm; min-height: 12mm; }
        .logo { flex: none; height: 12mm; max-width: 30mm; object-fit: contain; }
        .who { min-width: 0; }
        .brand { margin: 0; font-size: 11.5pt; font-weight: 600; line-height: 1.25; letter-spacing: -.1pt; }
        .where { margin: .8mm 0 0; font-size: 7.6pt; line-height: 1.35; color: var(--grey); }

        .ask { margin: 9mm 0 0; font-size: 21pt; font-weight: 300; line-height: 1.14; letter-spacing: -.5pt; }
        .stars { display: flex; gap: 2.4mm; margin-top: 6mm; }
        .stars svg { width: 12.8mm; height: 12.8mm; display: block; }

        .scan { display: flex; align-items: flex-end; gap: 5mm; margin-top: auto; }
        .qr { flex: none; width: 46mm; height: 46mm; margin-left: -1.6mm; }
        .qr svg { width: 100%; height: 100%; display: block; }
        .how { margin: 0 0 2mm; font-size: 8.6pt; line-height: 1.5; color: var(--grey); }
        .how strong { display: block; margin-bottom: 1.2mm; font-size: 9.4pt; font-weight: 500; color: var(--ink); }

        .foot { display: flex; align-items: center; justify-content: space-between; margin-top: 6mm; padding-top: 3.6mm; border-top: .25mm solid var(--line); font-size: 7.8pt; color: var(--grey); }
        .g { display: inline-flex; align-items: center; gap: 1.6mm; font-weight: 500; color: var(--ink); }
        .g svg { width: 3.8mm; height: 3.8mm; }

        @media screen { .card { box-shadow: 0 0 0 1px #e8eaed, 0 2px 6px rgba(60, 64, 67, .08), 0 14px 36px rgba(60, 64, 67, .10); } }
        @media print {
            .bar { display: none; }
            .sheet { min-height: 100vh; align-items: center; align-content: center; padding: 0; gap: 24mm; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <p><b>Google yorum kartı</b>A4 yatay kâğıda iki kart çıkar. Köşedeki işaretlerden kesip kasaya, masaya ya da bekleme salonuna koyun.</p>
        <button type="button" onclick="window.print()">Yazdır</button>
    </div>
    <div class="sheet">
        @for ($i = 0; $i < 2; $i++)
            <div class="card" data-review-card>
                <span class="crop tl"></span><span class="crop tr"></span><span class="crop bl"></span><span class="crop br"></span>
                <div class="head">
                    @if ($logo)
                        <img class="logo" src="{{ $logo }}" alt="">
                    @endif
                    <div class="who">
                        <h1 class="brand">{{ $business }}</h1>
                        @if ($place !== '')
                            <p class="where">{{ $place }}</p>
                        @endif
                    </div>
                </div>
                <p class="ask">Memnun kaldıysanız Google’da bizi değerlendirir misiniz?</p>
                <div class="stars" aria-hidden="true">
                    @for ($s = 0; $s < 5; $s++)
                        <svg viewBox="0 0 24 24"><path fill="#fbbc04" d="M12 2.6l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.4l-5.9 3.3 1.3-6.6L2.5 9.5l6.6-.8z"/></svg>
                    @endfor
                </div>
                <div class="scan">
                    <div class="qr">
                        @if ($qr)
                            {!! $qr !!}
                        @endif
                    </div>
                    <p class="how"><strong>Telefon kameranızı kare koda tutun.</strong>Açılan sayfada yıldızları seçip birkaç kelime yazmanız yeter.</p>
                </div>
                <div class="foot">
                    <span class="g"><svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>Google yorumları</span>
                    <span>Teşekkür ederiz</span>
                </div>
            </div>
        @endfor
    </div>
</body>
</html>
