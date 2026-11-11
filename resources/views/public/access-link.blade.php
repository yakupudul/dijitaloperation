@php
    $open = collect($items)->where('state', 'open')->count();
@endphp
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $link->brand?->name }} · Erişim adımları</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap">
    <style>
        :root { --ink: #14201f; --soft: #5b6665; --line: #e3e7e6; --teal: #0e5a57; --mark: #FDCF3C; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff; color: var(--ink); font: 400 17px/1.65 Inter, system-ui, -apple-system, "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
        main { max-width: 720px; margin: 0 auto; padding: 56px 20px 72px; }
        .over { margin: 0; font-size: 15px; color: var(--soft); }
        h1 { margin: 6px 0 0; font-size: clamp(28px, 6vw, 40px); line-height: 1.18; font-weight: 300; letter-spacing: -0.02em; text-wrap: balance; }
        h1 span { box-shadow: inset 0 -0.38em 0 var(--mark); }
        .note { margin: 20px 0 0; max-width: 60ch; }
        section { margin-top: 44px; padding-top: 22px; border-top: 1px solid var(--ink); }
        h2 { margin: 0; font-size: 21px; font-weight: 500; letter-spacing: -0.01em; display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; }
        h2 small { font-size: 14px; font-weight: 400; color: var(--teal); }
        ol { margin: 14px 0 0; padding-left: 1.3em; }
        ol li { padding: 4px 0 4px 4px; }
        .id { margin: 18px 0 0; padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; }
        .id span { font-size: 14px; color: var(--soft); display: block; }
        .id strong { font-weight: 600; font-size: 18px; word-break: break-all; user-select: all; }
        .row { margin-top: 18px; display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: center; }
        a.go { color: var(--teal); font-weight: 500; text-underline-offset: 3px; }
        button { font: inherit; cursor: pointer; border-radius: 999px; padding: 10px 22px; border: 1px solid var(--ink); background: var(--ink); color: #fff; font-weight: 500; font-size: 16px; }
        button.ghost { background: #fff; color: var(--ink); border-color: var(--line); padding: 8px 16px; font-size: 14px; }
        button:focus-visible, a:focus-visible { outline: 2px solid var(--teal); outline-offset: 3px; }
        .done { color: var(--teal); font-weight: 500; }
        footer { margin-top: 64px; padding-top: 18px; border-top: 1px solid var(--line); font-size: 14px; color: var(--soft); display: flex; flex-wrap: wrap; gap: 6px 24px; justify-content: space-between; }
        @media (max-width: 520px) { main { padding-top: 36px; } }
    </style>
</head>
<body>
<main>
    <p class="over">Erişim adımları</p>
    <h1><span>{{ $link->brand?->name }}</span> hesaplarına erişim</h1>
    <p class="note">@if ($open > 0) Çalışabilmemiz için aşağıdaki {{ count($items) > 1 ? count($items).' hesapta' : 'hesapta' }} bize yetki vermeniz gerekiyor. Her biri üç adım; şifrenizi kimseyle paylaşmazsınız, yetkiyi istediğiniz zaman aynı yerden kaldırabilirsiniz. @else Bütün adımlar tamamlandı, teşekkürler. Bağlantıları biz kontrol edip size haber veririz. @endif</p>

    @foreach ($items as $item)
        <section data-access-item="{{ $item['channel'] }}" data-state="{{ $item['state'] }}">
            <h2>{{ $item['name'] }} @if ($item['state'] === 'connected')<small>Bağlandı</small>@elseif ($item['state'] === 'marked')<small>Yetkiyi verdiniz, kontrol ediyoruz</small>@endif</h2>
            @if ($item['state'] !== 'connected')
                <ol>@foreach ($item['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                <div class="id">
                    <div><span>{{ $item['identity_label'] }} · yetki: {{ $item['role'] }}</span><strong id="id-{{ $item['channel'] }}">{{ $item['identity'] }}</strong></div>
                    <button type="button" class="ghost" data-copy="{{ $item['identity'] }}">Kopyala</button>
                </div>
                <div class="row">
                    <a class="go" href="{{ $item['where'] }}" target="_blank" rel="noopener">{{ $item['name'] }} sayfasını aç</a>
                    @if ($item['state'] === 'open')
                        <form method="post" action="{{ route('access-link.store', ['token' => $link->token]) }}">
                            @csrf
                            <input type="hidden" name="channel" value="{{ $item['channel'] }}">
                            <button type="submit">Yetkiyi verdim</button>
                        </form>
                    @endif
                </div>
            @endif
        </section>
    @endforeach

    <footer><span>{{ $agency }}</span><span>Bu sayfa yalnız size gönderilen bağlantıyla açılır.</span></footer>
</main>
<script>
    document.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var done = function () { b.textContent = 'Kopyalandı'; setTimeout(function () { b.textContent = 'Kopyala'; }, 2000); };
            if (navigator.clipboard) { navigator.clipboard.writeText(b.dataset.copy).then(done); }
        });
    });
</script>
</body>
</html>
