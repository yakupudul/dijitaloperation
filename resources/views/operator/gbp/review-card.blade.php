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
        .bar p { margin: 0; font-size: 14px; line-height: 1.5; color: var(--grey); max-width: 680px; }
        .bar p b { display: block; font-weight: 600; font-size: 16px; color: var(--ink); }
        .bar .acts { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
        .bar a { font-size: 14px; color: var(--teal); font-weight: 500; }
        .btn { padding: 10px 18px; border: 0; border-radius: 8px; background: var(--teal); color: #fff; font: 600 14px Inter, sans-serif; cursor: pointer; }
        .btn:hover { background: #0a4744; }
        .btn:focus-visible, .bar a:focus-visible { outline: 2px solid var(--teal); outline-offset: 3px; }

        .choose { display: grid; grid-template-columns: repeat(auto-fit, minmax(110mm, 1fr)); gap: 16mm 10mm; max-width: 248mm; margin: 0 auto; padding: 12mm 16px 20mm; justify-items: center; }
        .pick { display: flex; flex-direction: column; align-items: center; gap: 5mm; }
        .pick-row { display: flex; align-items: center; gap: 16px; }
        .btn[disabled] { opacity: .6; cursor: progress; }
        .pick b { font-size: 15px; font-weight: 600; margin-right: 8px; }

        /* A6 kart: 105 × 148 mm. Dört tasarım (2026-11-19): sakin, antet, renk, cerceve. */
        .card { position: relative; width: 105mm; height: 148mm; background: #fff; overflow: hidden; }

        .head { display: flex; align-items: center; gap: 3.5mm; height: 12mm; }
        .logo { flex: none; height: 12mm; max-width: 62mm; object-fit: contain; object-position: left center; }
        .who { min-width: 0; }
        .who[hidden] { display: none; }
        .brand { margin: 0; font-size: 11pt; font-weight: 600; line-height: 1.25; letter-spacing: -.1pt; }
        .where { margin: .8mm 0 0; font-size: 7.4pt; line-height: 1.35; color: var(--grey); }
        .ask { margin: 0; font-size: 20.4pt; font-weight: 300; line-height: 1.15; letter-spacing: -.45pt; }
        .rate { display: flex; align-items: center; gap: 2.6mm; }
        .rate .g { width: 5.2mm; height: 5.2mm; flex: none; }
        .stars { display: flex; gap: .9mm; }
        .stars svg { width: 5mm; height: 5mm; display: block; }
        .why { margin: 0; font-size: 9pt; line-height: 1.5; color: var(--grey); }
        .how { margin: 0; font-size: 7.8pt; line-height: 1.5; color: var(--grey); }
        .qr svg { width: 100%; height: 100%; display: block; }
        .foot { position: absolute; left: 10mm; right: 10mm; bottom: 7mm; display: flex; justify-content: space-between; gap: 4mm; padding-top: 3mm; border-top: .2mm solid var(--line); font-size: 7.4pt; color: var(--grey); }

        /* Sakin: her şey ortada, logonun altında kısa renk çizgisi. */
        .d-sakin .in { padding: 10mm 12mm 0; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .d-sakin .head { justify-content: center; }
        .d-sakin .logo { object-position: center; }
        .d-sakin .line { width: 10mm; height: .35mm; background: var(--accent); margin: 5mm 0 4.5mm; }
        .d-sakin .ask { font-size: 18.6pt; max-width: 72mm; }
        .d-sakin .rate { margin-top: 4.5mm; }
        .d-sakin .why { margin-top: 3.5mm; max-width: 68mm; }
        .d-sakin .qr { width: 35mm; height: 35mm; margin-top: 5mm; }
        .d-sakin .how { margin-top: 3.5mm; max-width: 62mm; }
        .d-sakin .foot { left: 12mm; right: 12mm; }

        /* Antet: üstte ince renk şeridi, sola hizalı. */
        .d-antet .strip { height: 2.2mm; background: var(--accent); }
        .d-antet .in { padding: 9mm 10mm 0; }
        .d-antet .ask { margin-top: 10mm; max-width: 80mm; }
        .d-antet .rate { margin-top: 5mm; }
        .d-antet .why { margin-top: 4mm; max-width: 72mm; }
        .d-antet .scan { display: flex; align-items: flex-end; gap: 5mm; margin-top: 8mm; }
        .d-antet .qr { flex: none; width: 40mm; height: 40mm; margin-left: -1.6mm; }
        .d-antet .how { margin-bottom: 1.6mm; }

        /* Renk alanı: üst bölüm marka renginde, logo beyaz. */
        .d-renk .top { background: var(--accent); padding: 10mm 10mm 0; height: 58mm; }
        .d-renk .logo { visibility: hidden; }
        .d-renk .logo.white { visibility: visible; }
        .d-renk .brand, .d-renk .where { color: #fff; }
        .d-renk .ask { color: #fff; margin-top: 11mm; max-width: 80mm; }
        .d-renk .rate { padding: 7mm 10mm 0; }
        .d-renk .scan { display: flex; gap: 6mm; padding: 6mm 10mm 0; align-items: flex-start; }
        .d-renk .qr { flex: none; width: 38mm; height: 38mm; margin-left: -1.5mm; margin-top: -1.5mm; }
        .d-renk .why { color: var(--ink); }
        .d-renk .how { margin-top: 3mm; }

        /* Çerçeveli kod: kod marka renginde çerçevede, altında Google etiketi. */
        .d-cerceve .in { padding: 10mm 12mm 0; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .d-cerceve .head { justify-content: center; }
        .d-cerceve .logo { object-position: center; }
        .d-cerceve .stars { margin-top: 6mm; }
        .d-cerceve .frame { margin-top: 5mm; width: 50mm; border: 1mm solid var(--accent); border-radius: 4mm; padding: 4mm 4mm 0; overflow: hidden; }
        .d-cerceve .qr { width: 100%; height: auto; aspect-ratio: 1; }
        .d-cerceve .tab { display: flex; align-items: center; justify-content: center; gap: 2mm; margin: 3.5mm -4mm 0; padding: 3mm 0 3.4mm; background: var(--accent); color: #fff; font-size: 10pt; font-weight: 500; }
        .d-cerceve .tab i { display: flex; width: 5.4mm; height: 5.4mm; border-radius: 50%; background: #fff; align-items: center; justify-content: center; }
        .d-cerceve .tab .g { width: 3.6mm; height: 3.6mm; }
        .d-cerceve .why { margin-top: 5mm; max-width: 70mm; color: var(--ink); }
        .d-cerceve .how { margin-top: 2.5mm; max-width: 66mm; }
        .d-cerceve .foot { left: 12mm; right: 12mm; }

        @media screen { .card { box-shadow: 0 0 0 1px #e8eaed, 0 2px 6px rgba(60, 64, 67, .08), 0 14px 36px rgba(60, 64, 67, .10); } }
        @media screen and (max-width: 460px) { .card { zoom: .82; } .choose { grid-template-columns: 1fr; } }
        @media print {
            .bar, .pick-row { display: none; }
            .choose { grid-template-columns: repeat(2, 105mm); gap: 10mm 24mm; justify-content: center; padding: 8mm 0; }
            .pick { break-inside: avoid; }
        }
    </style>
</head>
<body>
    @php
        $gIcon = '<svg class="g" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';
        $stars = '<span class="stars" aria-hidden="true">'.str_repeat('<svg viewBox="0 0 24 24"><path fill="#FBBC04" d="M12 2.6l2.9 6.1 6.6.8-4.9 4.6 1.3 6.6L12 17.4l-5.9 3.3 1.3-6.6-4.9-4.6 6.6-.8z"/></svg>', 5).'</span>';
        $ask = 'Deneyiminizi Google’da paylaşın';
        $how = 'Telefonunuzun kamerasını koda tutun, yorum sayfası açılır.';
    @endphp

    <div class="bar">
        <p><b>{{ $public ? $business.' için Google yorum kartı' : 'Google yorum kartı' }}</b>Dört tasarım, A6 (105 × 148 mm). Beğendiğiniz tasarımın altındaki “PDF indir” ile dosyayı alın; matbaaya doğrudan gönderebilir ya da kendiniz yazdırabilirsiniz. Kart resepsiyondaki standa takılır ya da elden verilir.</p>
    </div>

    <div class="choose">
        @foreach (array_keys($designs) as $d)
            <div class="pick">
            <div class="card d-{{ $d }}" data-review-card data-design="{{ $d }}">
                @php
                    $head = '<div class="head">'
                        .($logo ? '<img class="logo" src="'.e($logoData ?? $logo).'" alt="'.e($business).'" onload="if (this.naturalWidth / Math.max(1, this.naturalHeight) < 1.8) { this.nextElementSibling.hidden = false; }">' : '')
                        .'<div class="who"'.($logo ? ' hidden' : '').'><p class="brand">'.e($business).'</p>'.($place !== '' ? '<p class="where">'.e($place).'</p>' : '').'</div></div>';
                @endphp
                @switch($d)
                    @case('sakin')
                        <div class="in">
                            {!! $head !!}
                            <span class="line"></span>
                            <p class="ask">{{ $ask }}</p>
                            <div class="rate">{!! $gIcon !!}{!! $stars !!}</div>
                            <p class="why">{{ $why }}</p>
                            <div class="qr">{!! $qr !!}</div>
                            <p class="how">{{ $how }}</p>
                        </div>
                        @break
                    @case('renk')
                        <div class="top">{!! $head !!}<p class="ask">{{ $ask }}</p></div>
                        <div class="rate">{!! $gIcon !!}{!! $stars !!}</div>
                        <div class="scan"><div class="qr">{!! $qr !!}</div><div><p class="why">{{ $why }}</p><p class="how">{{ $how }}</p></div></div>
                        @break
                    @case('cerceve')
                        <div class="in">
                            {!! $head !!}
                            {!! $stars !!}
                            <div class="frame"><div class="qr">{!! $qr !!}</div><div class="tab"><i>{!! $gIcon !!}</i>Google’da yorum yazın</div></div>
                            <p class="why">{{ $why }}</p>
                            <p class="how">{{ $how }}</p>
                        </div>
                        @break
                    @default
                        <div class="strip"></div>
                        <div class="in">
                            {!! $head !!}
                            <p class="ask">{{ $ask }}</p>
                            <div class="rate">{!! $gIcon !!}{!! $stars !!}</div>
                            <p class="why">{{ $why }}</p>
                            <div class="scan"><div class="qr">{!! $qr !!}</div><p class="how">{{ $how }}</p></div>
                        </div>
                @endswitch
                <div class="foot">
                    @if ($area !== '' || $phone !== '')
                        <span>{{ $area }}</span><span>{{ $phone }}</span>
                    @else
                        <span>{{ $business }}</span><span>Teşekkür ederiz</span>
                    @endif
                </div>
            </div>
                <div class="pick-row">
                    <b>{{ $designs[$d] }}</b>
                    <button type="button" class="btn" data-download="{{ $d }}" data-file="google-yorum-karti-{{ $slug }}-{{ $d }}.pdf">PDF indir</button>
                </div>
            </div>
        @endforeach
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        // Logos are redrawn as PNG at their own proportions (the PDF step cannot draw an SVG with object-fit). On the
        // Renk alanı design every visible pixel turns white, transparency stays (a CSS filter would not survive the PDF).
        document.querySelectorAll('.card .logo').forEach(function (img) {
            const white = !!img.closest('.d-renk');
            const redraw = function () {
                const w = img.naturalWidth || 600, h = img.naturalHeight || 200, ratio = w / Math.max(h, 1);
                const height = Math.min(12, 62 / ratio);
                img.style.height = height + 'mm';
                img.style.width = (height * ratio) + 'mm';
                img.style.maxWidth = 'none';
                img.style.objectFit = 'fill';
                try {
                    const scale = 1400 / Math.max(w, 1);
                    const c = document.createElement('canvas');
                    c.width = Math.round(w * scale); c.height = Math.round(h * scale);
                    const x = c.getContext('2d');
                    x.drawImage(img, 0, 0, c.width, c.height);
                    if (white) {
                        x.globalCompositeOperation = 'source-in';
                        x.fillStyle = '#ffffff';
                        x.fillRect(0, 0, c.width, c.height);
                    }
                    img.removeEventListener('load', redraw);
                    img.src = c.toDataURL('image/png');
                } catch (e) { /* another site's logo without CORS: keep it as it is */ }
                img.classList.add('white');
            };
            img.complete && img.naturalWidth ? redraw() : img.addEventListener('load', redraw);
        });

        // "PDF indir": the card is copied off screen at its real size (no phone zoom), drawn at ~400 dpi and saved as an A6 PDF.
        document.querySelectorAll('[data-download]').forEach(function (button) {
            button.addEventListener('click', async function () {
                const card = document.querySelector('[data-review-card][data-design="' + button.dataset.download + '"]');
                const label = button.textContent;
                button.disabled = true;
                button.textContent = 'Hazırlanıyor…';
                const holder = document.createElement('div');
                holder.style.cssText = 'position:fixed;left:-10000px;top:0;zoom:1';
                const copy = card.cloneNode(true);
                copy.style.cssText = 'zoom:1;box-shadow:none';
                holder.appendChild(copy);
                document.body.appendChild(holder);
                try {
                    await document.fonts.ready;
                    const canvas = await html2canvas(copy, { scale: 4, backgroundColor: '#ffffff', useCORS: true, logging: false });
                    const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: [105, 148], orientation: 'portrait' });
                    pdf.addImage(canvas.toDataURL('image/jpeg', 0.95), 'JPEG', 0, 0, 105, 148);
                    pdf.save(button.dataset.file);
                    button.textContent = 'İndirildi';
                } catch (e) {
                    button.textContent = 'İndirilemedi, tekrar deneyin';
                } finally {
                    holder.remove();
                    button.disabled = false;
                    setTimeout(function () { button.textContent = label; }, 2500);
                }
            });
        });
    </script>
</body>
</html>
