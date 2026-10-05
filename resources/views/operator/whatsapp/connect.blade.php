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
            @if($attempt['status'] === 'prepared' && $attempt['launched_at'])
                <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" data-wa-previous-popup>Bu bağlantı için Meta penceresi daha önce açılmıştı (saat {{ $attempt['launched_at']->timezone('Europe/Istanbul')->format('H:i') }}). O pencere hâlâ açıksa kapatın ve aşağıdan yeniden başlatın; sayfa yenilendiği için eski pencerenin sonucu buraya ulaşamaz.</p>
            @endif
            @php
                $steps = $coexistence ? [
                    ['"Facebook ile bağla"ya basın', 've Facebook hesabınızla devam edin. Bu sayfayı iş bitene kadar kapatmayın ve yenilemeyin.'],
                    ['İşletme portföyünü seçin.', 'Meta uygulamasının sahibi olan portföy bu pencerede seçilemez; bu Meta kuralıdır.'],
                    ['WhatsApp Business\'ta kullandığınız numarayı girin.', 'Meta numaranın uygulamada kullanıldığını görür ve telefondaki uygulamayla birlikte kullanımı kendisi başlatır.'],
                    ['Telefonda onay verin.', 'WhatsApp Business\'a Facebook\'tan gelen mesajdaki adımları izleyin ya da penceredeki QR kodu okutun. Sohbet geçmişi sorulunca "Sohbetleri paylaş"ı seçin.'],
                    ['Son ekranda "Bitti" deyin.', 'Bu sayfa sonucu alır ve sizi WhatsApp ekranına götürür.'],
                ] : [
                    ['"Facebook ile bağla"ya basın', 've işletme portföyünü seçin.'],
                    ['WhatsApp hesabını ve numarayı seçin,', 'doğrulama adımını bitirip son ekranda "Bitti" deyin.'],
                ];
            @endphp
            <ol class="space-y-3 text-sm">
                @foreach($steps as [$lead, $rest])
                    <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold dark:bg-gray-800">{{ $loop->iteration }}</span><span><strong>{{ $lead }}</strong> {{ $rest }}</span></li>
                @endforeach
            </ol>
            <div class="flex flex-wrap items-center gap-3">
                <button id="wa-facebook-login" type="button" disabled class="rounded-lg bg-brand-500 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Facebook yükleniyor…</button>
                <button id="wa-focus-popup" type="button" hidden class="rounded-lg px-5 py-3 text-sm ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Meta penceresine dön</button>
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
    const back = document.getElementById('wa-focus-popup');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    // launched: the popup was started from this page; ended: an ending without a result was reported; done: leaving on purpose.
    let code = null, session = null, finished = null, saving = false, launched = false, ended = false, done = false;
    let timer = null, noCode = null, watcher = null, launchedAt = 0, popup = null, popupSeen = false, blocked = false;
    const BLOCKED = 'Tarayıcı Facebook penceresini engelledi. Adres çubuğundaki açılır pencere simgesinden bu siteye izin verin ve tekrar basın.';
    const fromFacebook = origin => { try { const url = new URL(origin); return url.protocol === 'https:' && (url.hostname === 'facebook.com' || url.hostname.endsWith('.facebook.com')); } catch (_) { return false; } };
    const say = message => { status.textContent = message; };
    const popupOpen = () => popup !== null && !popup.closed;
    async function post(url, payload, keepalive = false) {
        const controller = new AbortController();
        const deadline = setTimeout(() => controller.abort(), 25000);
        let response;
        try {
            response = await fetch(url, {
                signal: controller.signal, keepalive,
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify(payload),
            });
        } catch (_) {
            throw Object.assign(new Error('Sunucudan yanıt alınamadı. İnternet bağlantınızı kontrol edip tekrar deneyin.'), { status: 0 });
        } finally { clearTimeout(deadline); }
        // A login redirect or an HTML error page is never a saved result.
        const isJson = (response.headers.get('Content-Type') || '').includes('application/json');
        const data = isJson ? await response.json().catch(() => ({})) : {};
        if (!response.ok || response.redirected || !isJson) {
            const fieldError = data.errors ? Object.values(data.errors).flat()[0] : null;
            const message = [401, 419].includes(response.status) || response.redirected
                ? 'MoxDOP oturumunuz kapanmış. Sayfayı yenileyip giriş yapın, sonra WhatsApp ekranından yeniden başlatın.'
                : response.status === 429 ? 'Çok sık denendi. Bir dakika bekleyip tekrar deneyin.'
                : response.status >= 500 ? 'Sunucuda bir hata oluştu (HTTP ' + response.status + '). Tekrar deneyin; sürerse bu ekranın görüntüsünü iletin.'
                : (fieldError || data.message || 'Sunucu beklenmeyen bir yanıt verdi (HTTP ' + response.status + ').');
            throw Object.assign(new Error(message), { status: response.status });
        }
        return data;
    }
    // The connect page's diary on the WhatsApp screen. It never blocks the signup; a session that is no longer valid
    // is said at once because Meta's result could not be saved either.
    function trace(event, note = '') {
        return post(settings.reportUrl, { event, note: String(note).slice(0, 300) }, true).catch(error => {
            if ([401, 403, 409, 419].includes(error.status) && !done) say(error.message);
        });
    }
    // Meta's code expires ~30 seconds after the popup closes, so it goes to the server at once; without an
    // account selection the server finds the shared WhatsApp account from the token itself.
    async function handoff(force = false) {
        if (!code || saving || (!session && !force)) return;
        saving = true;
        clearTimeout(timer); clearTimeout(noCode);
        if (retry) retry.hidden = true;
        say('Meta onayı alındı. Numara bağlanıyor…');
        try {
            const data = await post(settings.completeUrl, session
                ? { code, waba_id: session.waba_id, phone_number_id: session.phone_number_id, event: session.event }
                : { code, event: 'CODE_ONLY', finish_event: finished });
            if (typeof data.redirect !== 'string') throw Object.assign(new Error('Sunucu sonucu aldığını doğrulamadı. Sonucu tekrar gönderin.'), { status: 200 });
            code = null; session = null; done = true;
            window.location.assign(data.redirect);
        } catch (error) {
            say(error.message);
            trace('POST_FAILED', 'HTTP ' + (error.status ?? 0) + ' · ' + error.message);
            if (retry) retry.hidden = false;
        } finally { saving = false; }
    }
    // An ending without a result: shown here and kept on the WhatsApp screen with the reason.
    function report(payload, message) {
        ended = true;
        clearTimeout(timer); clearTimeout(noCode);
        say(message);
        if (login && !popupOpen()) login.disabled = false;
        post(settings.reportUrl, payload).catch(error => say(message + ' Bu sonuç WhatsApp ekranına kaydedilemedi: ' + error.message));
    }
    // Facebook normally answers when its popup closes (with or without a code); silence means its answer never reached this page.
    function watchPopup() {
        clearInterval(watcher);
        if (back) back.hidden = !popupOpen();
        if (!popup) return;
        watcher = setInterval(() => {
            if (!popup.closed) { popupSeen = true; return; }
            clearInterval(watcher);
            popup = null;
            if (back) back.hidden = true;
            if (login && !saving && !code) login.disabled = false;
            if (popupSeen) setTimeout(() => {
                if (!code && !ended && !saving && !done) report({ event: 'NO_CALLBACK' }, 'Meta penceresi kapandı ama Facebook bu sayfaya sonuç iletmedi. Nedeni WhatsApp ekranında yazıyor.');
            }, 8000);
        }, 1000);
    }
    if (back) back.addEventListener('click', () => { if (popupOpen()) popup.focus(); });
    if (retry) retry.addEventListener('click', () => handoff(true));
    window.addEventListener('beforeunload', event => {
        if (launched && !done && !ended && (saving || code || popupOpen())) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pagehide', () => {
        if (!launched || done || ended || !navigator.sendBeacon) return;
        const form = new FormData();
        form.append('_token', csrf);
        form.append('event', 'PAGE_LEFT');
        form.append('note', popupOpen() ? 'Meta penceresi açıktı' : (code ? 'Meta onayı sunucuya gönderilmemişti' : ''));
        navigator.sendBeacon(settings.reportUrl, form);
    });
    window.addEventListener('message', event => {
        if (!launched || !fromFacebook(event.origin)) return;
        let message;
        try { message = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (_) { return; }
        if (!message || message.type !== 'WA_EMBEDDED_SIGNUP') return;
        const data = message.data || {};
        if (message.event !== 'CANCEL' && message.event !== 'ERROR') {
            trace('MESSAGE', [message.event, data.current_step, data.waba_id ? 'waba ' + data.waba_id : null, data.phone_number_id ? 'numara ' + data.phone_number_id : null].filter(Boolean).join(' · '));
        }
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
            noCode = setTimeout(() => { if (!code && !ended) report({ event: 'NO_CODE' }, 'Meta hesap seçimini bildirdi ama yetki kodunu vermedi. Nedeni WhatsApp ekranında yazıyor.'); }, 45000);
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
            code = null; session = null; finished = null; launched = true; ended = false; launchedAt = Date.now();
            popup = null; popupSeen = false; blocked = false;
            clearTimeout(timer); clearTimeout(noCode); clearInterval(watcher);
            login.disabled = true;
            say('Meta penceresindeki adımları bitirin. Bu sayfayı kapatmayın.');
            trace('LAUNCHED');
            // sessionInfoVersion asks the popup to post the chosen account (session logging); Meta requires it for
            // WhatsApp Business app (coexistence) onboarding.
            const extras = { setup: {}, sessionInfoVersion: '3' };
            if (settings.mode === 'coexistence') extras.featureType = 'whatsapp_business_app_onboarding';
            // Keep the window the SDK opens so its closing is noticed even when Facebook never answers.
            const nativeOpen = window.open;
            window.open = function (...args) {
                const opened = nativeOpen.apply(window, args);
                if (opened) { popup = opened; } else { blocked = true; }
                return opened;
            };
            // Call synchronously from the click so browsers permit the OAuth popup.
            try {
                FB.login(response => {
                    const received = !!(response && response.authResponse && typeof response.authResponse.code === 'string');
                    trace('SDK_CALLBACK', 'durum ' + String((response && response.status) || '-') + ' · kod ' + (received ? 'var' : 'yok'));
                    if (received) {
                        code = response.authResponse.code;
                        clearTimeout(noCode);
                        if (session || finished) { handoff(true); return; }
                        timer = setTimeout(() => handoff(true), 6000);
                        say('Meta onayı alındı. Hesap bilgisi bekleniyor…');
                    } else if (ended) {
                        // Already reported (cancelled or an error inside the popup).
                    } else if (finished) {
                        report({ event: 'NO_CODE' }, 'Meta hesap seçimini bildirdi ama yetki kodunu vermedi. Nedeni WhatsApp ekranında yazıyor.');
                    } else if (Date.now() - launchedAt < 3000) {
                        // An answer within seconds means the popup never really ran (blocked, or this domain is not allowed in Meta).
                        report({ event: 'LOGIN_REFUSED' }, blocked ? BLOCKED : 'Facebook penceresi hemen sonuçsuz döndü. Nedeni WhatsApp ekranında yazıyor.');
                    } else {
                        report({ event: 'POPUP_CLOSED' }, 'Meta penceresi sonuç vermeden kapandı. Adımların hepsini bitirip son ekranda "Bitti" deyin.');
                    }
                }, { config_id: settings.configId, response_type: 'code', override_default_response_type: true, extras });
            } catch (_) {
                launched = false;
                login.disabled = false;
                say('Facebook penceresi açılamadı. Açılır pencere iznini ve Meta App ID ayarını kontrol edin.');
                return;
            } finally {
                window.open = nativeOpen;
            }
            if (blocked) {
                trace('POPUP_BLOCKED');
                say(BLOCKED);
            }
            watchPopup();
        });
    }
    const select = document.getElementById('wa-select-phone');
    if (select) select.addEventListener('click', async () => {
        const phoneId = document.getElementById('wa-selected-phone').value;
        if (!phoneId) { say('Önce işletme numarasını seçin.'); return; }
        select.disabled = true;
        try {
            const data = await post(settings.phoneUrl, { phone_number_id: phoneId });
            if (typeof data.redirect !== 'string') throw new Error('Sunucu seçimi aldığını doğrulamadı. Tekrar deneyin.');
            done = true;
            window.location.assign(data.redirect);
        } catch (error) { say(error.message); select.disabled = false; }
    });
})();
</script>
@endcomponent
