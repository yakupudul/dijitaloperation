@component('operator.layouts.app', ['title' => 'WhatsApp numarasını bağla'])
@php
    $coexistence = $attempt['mode'] === 'coexistence';
    $lastReason = in_array($attempt['status'], ['cancelled'], true) ? ($attempt['details']['message'] ?? null) : null;
@endphp
<div class="mx-auto max-w-2xl space-y-4">
    <a href="{{ route('operator.whatsapp') }}" class="text-sm text-gray-500 hover:text-brand-600">← WhatsApp</a>
    <section class="space-y-5 rounded-xl bg-white p-6 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-800">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">WhatsApp numarasını bağla</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $coexistence ? 'Telefonunuzdaki WhatsApp Business uygulaması çalışmaya devam eder; MoxDOP yalnız mesajları okur.' : 'WhatsApp Cloud API hesabınızı Meta penceresinden seçin.' }}</p>
        </div>
        @if($attempt['expires_at']->isPast())
            <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">Bu bağlantı oturumunun süresi doldu. WhatsApp ekranına dönüp yeniden başlatın.</p>
        @elseif($attempt['status'] === 'choose_phone')
            <p class="text-sm">{{ $attempt['details']['message'] ?? 'Meta birden fazla numara paylaştı. Bağlamak istediğiniz işletme numarasını seçin.' }}</p>
            <label for="wa-selected-phone" class="block text-sm font-medium">İşletme numarası</label>
            <select id="wa-selected-phone" class="w-full rounded-lg border border-gray-300 bg-transparent p-3 dark:border-gray-700">
                <option value="">Numara seçin</option>
                @foreach($phones as $phone)
                    <option value="{{ $phone['id'] }}">{{ $phone['display_phone_number'] }} · {{ $phone['verified_name'] }}@if(filled($phone['waba_id'] ?? null)) · WhatsApp hesabı {{ $phone['waba_id'] }}@endif</option>
                @endforeach
            </select>
            <button id="wa-select-phone" type="button" class="rounded-lg bg-brand-500 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Bu numarayı bağla</button>
        @elseif(in_array($attempt['status'], ['prepared', 'cancelled'], true))
            @if($lastReason)
                <div role="alert" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20">
                    <p class="font-semibold">Önceki deneme tamamlanmadı</p>
                    <p class="mt-1">{{ $lastReason }}</p>
                </div>
            @endif
            <ol class="space-y-3 text-sm">
                <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-gray-800">1</span><span><strong>İşletme portföyünü seçin.</strong> Meta uygulamasının sahibi olan portföy (gri görünen) bu pencerede seçilemez; bu Meta kuralıdır.</span></li>
                @if($coexistence)
                    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-gray-800">2</span><span><strong>"Mevcut WhatsApp Business uygulamanızı bağlayın" seçeneğini seçin.</strong> Yalnız "yeni telefon numarası girin" çıkıyorsa numarayı yeni numara olarak eklemeyin; "bu numara zaten kayıtlı" hatası alırsınız. Pencereyi kapatın; sebebi bu ekranda görünür.</span></li>
                    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-gray-800">3</span><span><strong>Telefonda WhatsApp Business'ı açın.</strong> Gelen Meta mesajındaki QR kodu okutun ve sohbet geçmişini paylaşmayı onaylayın.</span></li>
                    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-gray-800">4</span><span><strong>Son ekranda "Bitti" deyin.</strong> Gerisini MoxDOP arka planda tamamlar.</span></li>
                @else
                    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-gray-800">2</span><span><strong>WhatsApp hesabını ve numarayı seçin</strong>, doğrulama adımını bitirip son ekranda "Bitti" deyin.</span></li>
                @endif
            </ol>
            <div class="flex flex-wrap items-center gap-3">
                <button id="wa-facebook-login" type="button" disabled class="rounded-lg bg-brand-500 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Facebook yükleniyor…</button>
                <button id="wa-retry-handoff" type="button" hidden class="rounded-lg px-5 py-3 text-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Sonucu tekrar gönder</button>
            </div>
        @else
            <p class="text-sm">Bağlantı sonucu WhatsApp ekranında gösteriliyor.</p>
        @endif
        <p id="wa-signup-status" role="status" class="text-sm text-gray-600 dark:text-gray-300"></p>
    </section>
</div>
<script>
(() => {
    const settings = {{ Illuminate\Support\Js::from([
        'appId' => $appId, 'configId' => $configId, 'version' => $graphVersion, 'mode' => $attempt['mode'],
        'completeUrl' => route('operator.whatsapp.complete', ['attempt' => $attempt['id']]),
        'reportUrl' => route('operator.whatsapp.report', ['attempt' => $attempt['id']]),
        'phoneUrl' => route('operator.whatsapp.select-phone', ['attempt' => $attempt['id']]),
    ]) }};
    const status = document.getElementById('wa-signup-status');
    const login = document.getElementById('wa-facebook-login');
    const retry = document.getElementById('wa-retry-handoff');
    let code = null, session = null, finished = null, saving = false, launched = false, reported = false, timer = null, noCode = null, launchedAt = 0;
    const fromFacebook = origin => { try { const url = new URL(origin); return url.protocol === 'https:' && (url.hostname === 'facebook.com' || url.hostname.endsWith('.facebook.com')); } catch (_) { return false; } };
    const say = message => { status.textContent = message; };
    async function post(url, payload) {
        const controller = new AbortController();
        const deadline = setTimeout(() => controller.abort(), 25000);
        let response;
        try {
            response = await fetch(url, {
                signal: controller.signal,
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(payload),
            });
        } catch (_) {
            throw new Error('Sunucudan yanıt alınamadı. Sonucu tekrar gönderebilirsiniz.');
        } finally { clearTimeout(deadline); }
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const fieldError = data.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(fieldError || data.message || 'Sonuç kaydedilemedi. Oturumunuzun açık olduğundan emin olup tekrar deneyin.');
        }
        return data;
    }
    // Meta's code expires ~30 seconds after the popup closes, so it goes to the server at once; without an
    // account selection the server finds the shared WhatsApp account from the token itself.
    async function handoff(force = false) {
        if (!code || saving || (!session && !force)) return;
        saving = true;
        clearTimeout(timer);
        if (retry) retry.hidden = true;
        say('Meta onayı alındı. Numara bağlanıyor…');
        try {
            const data = await post(settings.completeUrl, session
                ? { code, waba_id: session.waba_id, phone_number_id: session.phone_number_id, event: session.event }
                : { code, event: 'CODE_ONLY', finish_event: finished });
            code = null;
            session = null;
            window.location.assign(data.redirect);
        } catch (error) {
            say(error.message);
            if (retry) retry.hidden = false;
        } finally { saving = false; }
    }
    function report(payload, message) {
        reported = true;
        clearTimeout(timer);
        say(message);
        if (login) login.disabled = false;
        post(settings.reportUrl, payload).catch(() => {});
    }
    if (retry) retry.addEventListener('click', () => handoff(true));
    window.addEventListener('message', event => {
        if (!launched || !fromFacebook(event.origin)) return;
        let message;
        try { message = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (_) { return; }
        if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') return;
        const data = message.data || {};
        if (['FINISH', 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'FINISH_ONLY_WABA'].includes(message.event)) {
            const waba = String(data.waba_id || '');
            finished = message.event;
            if (/^[0-9]{5,40}$/.test(waba)) {
                session = { waba_id: waba, phone_number_id: /^[0-9]{5,40}$/.test(String(data.phone_number_id || '')) ? String(data.phone_number_id) : null, event: message.event };
            }
            // Without an account id the server still finds the shared account; the finish kind is kept either way.
            if (code) { handoff(true); return; }
            // The account came back but Meta has not handed the code to the page yet; without it nothing can connect.
            clearTimeout(noCode);
            noCode = setTimeout(() => { if (!code) report({ event: 'NO_CODE' }, 'Meta hesap seçimini bildirdi ama yetki kodunu vermedi. Nedeni WhatsApp ekranında yazıyor.'); }, 45000);
        } else if (message.event === 'CANCEL' || message.event === 'ERROR') {
            const errorMessage = data.error_message ? String(data.error_message).slice(0, 500) : null;
            report({
                event: errorMessage ? 'ERROR' : 'CANCEL',
                current_step: data.current_step ? String(data.current_step).slice(0, 100) : null,
                error_message: errorMessage,
                error_id: (data.error_id || data.error_code) ? String(data.error_id || data.error_code).slice(0, 100) : null,
                session_id: data.session_id ? String(data.session_id).slice(0, 200) : null,
            }, errorMessage ? 'Meta hata gösterdi: ' + errorMessage : 'Meta penceresi tamamlanmadan kapandı. Sebebi WhatsApp ekranında görünür; tekrar deneyebilirsiniz.');
        }
    });
    if (login) {
        window.fbAsyncInit = () => {
            // Embedded Signup needs the configured business authorization code flow.
            // Opt out of the SDK's separate FedCM/OpenID sign-in step on this page.
            FB.init({ appId: settings.appId, cookie: true, xfbml: false, status: false, fedCM: false, version: settings.version });
            login.disabled = false;
            login.textContent = 'Facebook ile bağla';
            say('');
        };
        const sdk = document.createElement('script');
        sdk.src = 'https://connect.facebook.net/tr_TR/sdk.js';
        sdk.async = true;
        sdk.defer = true;
        sdk.onerror = () => say('Facebook yüklenemedi. Tarayıcıdaki reklam/izleme engelleyicilerini kapatıp sayfayı yenileyin.');
        document.head.appendChild(sdk);
        login.addEventListener('click', () => {
            code = null; session = null; finished = null; launched = true; reported = false; launchedAt = Date.now();
            clearTimeout(timer); clearTimeout(noCode);
            login.disabled = true;
            say('Meta penceresindeki adımları bitirin.');
            // sessionInfoVersion asks the popup to post the chosen account (session logging); Meta requires it for
            // WhatsApp Business app (coexistence) onboarding.
            const extras = { setup: {}, sessionInfoVersion: '3' };
            if (settings.mode === 'coexistence') extras.featureType = 'whatsapp_business_app_onboarding';
            // Call synchronously from the click so browsers permit the OAuth popup.
            try {
                FB.login(response => {
                    if (response.authResponse && typeof response.authResponse.code === 'string') {
                        code = response.authResponse.code;
                        clearTimeout(noCode);
                        if (session || finished) { handoff(true); return; }
                        timer = setTimeout(() => handoff(true), 6000);
                        say('Meta onayı alındı. Hesap bilgisi bekleniyor…');
                    } else if (!reported && Date.now() - launchedAt < 3000) {
                        // An answer within seconds means the popup never really ran (blocked, or this domain is not allowed in Meta).
                        report({ event: 'LOGIN_REFUSED' }, 'Facebook penceresi hemen sonuçsuz döndü. Nedeni WhatsApp ekranında yazıyor.');
                    } else if (!reported) {
                        report({ event: 'POPUP_CLOSED' }, 'Meta penceresi sonuç vermeden kapandı. Adımların hepsini bitirip son ekranda "Bitti" deyin.');
                    }
                }, { config_id: settings.configId, response_type: 'code', override_default_response_type: true, extras });
            } catch (_) {
                say('Facebook penceresi açılamadı. Açılır pencere iznini ve Meta App ID ayarını kontrol edin.');
                login.disabled = false;
            }
        });
    }
    const select = document.getElementById('wa-select-phone');
    if (select) select.addEventListener('click', async () => {
        const phoneId = document.getElementById('wa-selected-phone').value;
        if (!phoneId) { say('Önce işletme numarasını seçin.'); return; }
        select.disabled = true;
        try {
            const data = await post(settings.phoneUrl, { phone_number_id: phoneId });
            window.location.assign(data.redirect);
        } catch (error) { say(error.message); select.disabled = false; }
    });
})();
</script>
@endcomponent
