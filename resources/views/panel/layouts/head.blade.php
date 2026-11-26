<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Panel' }} | {{ config('panel.name') }}</title>
<link rel="icon" type="image/png" href="/images/app/favicon-64.png?v=2" />
@vite(['resources/css/operator.css', 'resources/js/operator.js'])
@include('operator.partials.theme-colors')
{{-- Meta's login window checks the calling site from the Referer; without the origin it answers with a code bound to another address. --}}
<meta name="referrer" content="strict-origin-when-cross-origin">
<script>
    // The inbox connect button: opens Meta's own window (WhatsApp signup or Facebook login) and hands its answer to the page.
    window.panelConnect = function (cfg) {
        return {
            ready: false, busy: false, message: '', session: null,
            init() {
                const start = () => { this.ready = true; };
                if (window.__panelFb) { window.__panelFb.then(start, () => { this.message = 'Facebook yüklenemedi. Sayfayı yenileyip tekrar deneyin.'; }); return; }
                window.__panelFb = new Promise((resolve, reject) => {
                    window.fbAsyncInit = () => { FB.init({ appId: cfg.appId, cookie: true, xfbml: false, status: false, version: cfg.version }); resolve(); };
                    const sdk = document.createElement('script');
                    sdk.src = 'https://connect.facebook.net/tr_TR/sdk.js';
                    sdk.async = true; sdk.defer = true; sdk.crossOrigin = 'anonymous';
                    sdk.onerror = reject;
                    document.head.appendChild(sdk);
                });
                window.__panelFb.then(start, () => { this.message = 'Facebook yüklenemedi. Reklam engelleyiciyi kapatıp sayfayı yenileyin.'; });
                window.addEventListener('message', (event) => {
                    let host = '';
                    try { host = new URL(event.origin).hostname; } catch (_) { return; }
                    if (host !== 'facebook.com' && !host.endsWith('.facebook.com')) return;
                    let data;
                    try { data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch (_) { return; }
                    if (!data || data.type !== 'WA_EMBEDDED_SIGNUP') return;
                    if (String(data.event || '').startsWith('FINISH')) {
                        this.session = { waba_id: String(data.data?.waba_id || ''), phone_number_id: String(data.data?.phone_number_id || '') };
                    } else if (data.event === 'CANCEL' && data.data?.error_message) {
                        this.message = 'Meta: ' + String(data.data.error_message).slice(0, 300);
                    }
                });
            },
            whatsapp(coexistence) {
                if (!this.ready || this.busy) return;
                this.message = ''; this.session = null;
                if (!cfg.config) { this.message = 'Bağlantı şu an açılamıyor; Moximu\'ya haber verildi.'; return; }
                const extras = { setup: {}, sessionInfoVersion: '3' };
                if (coexistence) extras.featureType = 'whatsapp_business_app_onboarding';
                FB.login((response) => {
                    const code = response && response.authResponse && response.authResponse.code;
                    if (!code) { if (!this.message) this.message = 'Meta penceresi tamamlanmadan kapandı. Adımların hepsini bitirip son ekranda “Bitti” deyin.'; return; }
                    this.busy = true;
                    // Meta's account message can arrive just after the code; wait a moment for it, the server finds the number either way.
                    setTimeout(() => {
                        this.$wire.linkWhatsApp(Object.assign({ code }, this.session || {})).finally(() => { this.busy = false; });
                    }, this.session ? 0 : 1500);
                }, { config_id: cfg.config, response_type: 'code', override_default_response_type: true, extras });
            },
            facebook() {
                if (!this.ready || this.busy) return;
                this.message = '';
                FB.login((response) => {
                    const token = response && response.authResponse && response.authResponse.accessToken;
                    if (!token) { this.message = 'Facebook girişi tamamlanmadı. Yeniden deneyin ve izinleri onaylayın.'; return; }
                    this.busy = true;
                    this.$wire.linkMeta(token).finally(() => { this.busy = false; });
                }, { scope: cfg.scopes.join(','), auth_type: 'rerequest', return_scopes: true });
            },
        };
    };
</script>
