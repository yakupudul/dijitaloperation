<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Bağlantı kaynakları · {{ $survey->brand?->name }}</title>
    <style>
        :root { --ink: #111827; --muted: #6b7280; --line: #e5e7eb; --bg: #f9fafb; --card: #fff; --brand: #0f766e; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); line-height: 1.5; }
        main { max-width: 720px; margin: 0 auto; padding: 24px 16px 80px; }
        h1 { font-size: 22px; margin: 0 0 6px; }
        .intro { color: var(--muted); font-size: 15px; margin: 0 0 20px; }
        .notice { border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 14px; background: #ecfdf5; color: #065f46; }
        .err { background: #fff1f2; color: #9f1239; }
        .item { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 14px; margin-bottom: 12px; }
        label { font-weight: 600; font-size: 15px; display: block; }
        .help { color: var(--muted); font-size: 13px; margin: 2px 0 6px; }
        textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 9px 10px; font: inherit; font-size: 15px; }
        button { background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 11px 20px; font-size: 15px; font-weight: 600; cursor: pointer; }
        footer { color: var(--muted); font-size: 12px; margin-top: 18px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $survey->brand?->name }} · Bağlantı kaynakları</h1>
    <p class="intro">Web sitenizin Google'da güçlenmesi için en değerli bağlantılar gerçek ilişkilerinizden gelir: üyelikleriniz, çalıştığınız markalar, eğitim aldığınız ya da ders verdiğiniz kurumlar. Bildiklerinizi her satıra bir tane gelecek şekilde yazmanız yeterli; boş bıraktıklarınız sorun değil. Hiçbir kuruma sizin onayınız olmadan yazılmaz.</p>
    @if ($saved)
        <div class="notice">Teşekkürler, bilgileriniz kaydedildi. İstediğiniz zaman bu sayfaya dönüp ekleme yapabilirsiniz.</div>
    @endif
    @if ($errors->any())
        <div class="notice err">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('link-survey.store', ['token' => $survey->token]) }}">
        @csrf
        @foreach (\App\Models\LinkSurvey::QUESTIONS as $key => [$question, $help])
            <div class="item">
                <label for="q-{{ $key }}">{{ $question }}</label>
                <p class="help">{{ $help }}</p>
                <textarea id="q-{{ $key }}" name="answers[{{ $key }}]" rows="3">{{ old('answers.'.$key, $survey->answers[$key] ?? '') }}</textarea>
            </div>
        @endforeach
        <div class="item">
            <label for="note">Eklemek istedikleriniz</label>
            <textarea id="note" name="note" rows="2">{{ old('note', $survey->note) }}</textarea>
        </div>
        <button type="submit">Gönder</button>
    </form>
    <footer>Moximu · Bu sayfa yalnız size gönderilen bağlantıyla açılır.</footer>
</main>
</body>
</html>
