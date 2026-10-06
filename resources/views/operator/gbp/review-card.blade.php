<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yorum kartı · {{ $branch }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; background: #f3f4f6; }
        .bar { display: flex; gap: 12px; align-items: center; justify-content: center; padding: 12px; font-size: 14px; }
        .bar button { padding: 8px 16px; border: 0; border-radius: 8px; background: #465fff; color: #fff; font-weight: 600; cursor: pointer; }
        .sheet { display: flex; gap: 10mm; justify-content: center; padding: 10mm; }
        .card { width: 105mm; height: 148mm; background: #fff; border: 1px dashed #d1d5db; border-radius: 6mm; padding: 10mm 8mm; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .stars { color: #f59e0b; font-size: 22pt; letter-spacing: 2pt; }
        h1 { margin: 3mm 0 1mm; font-size: 17pt; line-height: 1.2; }
        .branch { margin: 0; color: #4b5563; font-size: 10.5pt; }
        .ask { margin: 5mm 0 3mm; font-size: 12.5pt; font-weight: 600; }
        .qr { width: 56mm; height: 56mm; }
        .qr svg { width: 100%; height: 100%; }
        .how { margin: 3mm 0 0; color: #4b5563; font-size: 9.5pt; }
        .thanks { margin-top: auto; font-size: 11pt; font-weight: 600; }
        .link { margin-top: 2mm; font-size: 6.5pt; color: #9ca3af; word-break: break-all; }
        @media print { body { background: #fff; } .bar { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
    <div class="bar"><span>Kartı yazdırıp kesin; kasa, bekleme salonu veya masaya koyun.</span><button type="button" onclick="window.print()">Yazdır</button></div>
    <div class="sheet">
        @for ($i = 0; $i < 2; $i++)
            <div class="card">
                <div class="stars">★★★★★</div>
                <h1>{{ $business }}</h1>
                <p class="branch">{{ $branch }}@if ($area !== '' && ! str_contains(mb_strtolower($branch), mb_strtolower($area))) · {{ $area }}@endif</p>
                <p class="ask">Memnun kaldıysanız Google’da<br>yorum bırakır mısınız?</p>
                <div class="qr">@if ($qr){!! $qr !!}@endif</div>
                <p class="how">Telefonunuzun kamerasını QR koda tutun,<br>açılan sayfada yıldızları seçip yazın.</p>
                <p class="thanks">Teşekkür ederiz!</p>
                <p class="link">{{ $link }}</p>
            </div>
        @endfor
    </div>
</body>
</html>
