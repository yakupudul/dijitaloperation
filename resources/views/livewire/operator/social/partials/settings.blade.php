{{-- Ayarlar: the posting plan and the account connections. Set once, then rarely opened; that is why they are not tabs. --}}
<div class="flex flex-wrap gap-1.5" data-social-settings-sections>
    <button type="button" wire:click="$set('section', 'duzen')" @class(['btn h-8', 'border-second bg-second-soft text-second' => $section === 'duzen'])>Paylaşım düzeni</button>
    <button type="button" wire:click="$set('section', 'hesaplar')" @class(['btn h-8', 'border-second bg-second-soft text-second' => $section === 'hesaplar'])>Hesaplar</button>
</div>
@if ($section === 'duzen')
{{-- Paylaşım düzeni (yakup 2026-11-12): the days and the hour; approved posts take these days in line order. --}}
<section class="card space-y-4 p-5" data-social-schedule>
    <div>
        <h2 class="section-title">Paylaşım düzeni</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Onayladığınız gönderiler sıraya girer ve seçtiğiniz günlerde, günde bir tane olarak Instagram ve Facebook’ta yayınlanır. Sırada {{ count($line) }} gönderi{{ $reelLine !== null ? ', '.count($reelLine).' video' : '' }} {{ count($storyLine) }} story ve {{ count($textLine) }} metin gönderisi var.</p>
    </div>
    <div class="grid grid-cols-2 gap-2 lg:grid-cols-4 xl:grid-cols-7">
        @foreach ($dayNames as $day => $name)
            <div @class(['rounded-lg border p-3', 'border-gray-200 dark:border-gray-800' => ! ($planDays[$day] ?? false), 'border-brand-500' => $planDays[$day] ?? false]) data-schedule-day="{{ $day }}">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" wire:model.live="planDays.{{ $day }}" class="rounded border-gray-300 dark:border-gray-700" @disabled(! $canWrite)>
                    {{ $name }}
                </label>
                <input type="time" wire:model="planTimes.{{ $day }}" aria-label="{{ $name }} paylaşım saati" class="mt-2 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" @disabled(! $canWrite || ! ($planDays[$day] ?? false))>
            </div>
        @endforeach
    </div>
    <div data-story-times>
        <p class="text-sm font-medium">Story saatleri</p>
        <p class="mt-1 text-xs text-gray-500">Onayladığınız story’ler kendi sırasına girer ve her gün bu saatlerde yayınlanır; gönderilerin gününü almaz. Kullanmadığınız saati boş bırakın.</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ([0, 1, 2] as $i)
                <input type="time" wire:model="storyTimes.{{ $i }}" aria-label="{{ $i + 1 }}. story saati" class="w-32 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" @disabled(! $canWrite)>
            @endforeach
        </div>
    </div>
    <div data-reel-times>
        <p class="text-sm font-medium">Video saatleri</p>
        <p class="mt-1 text-xs text-gray-500">Saat yazarsanız onaylı videolar (Reels) kendi sırasına girer ve her gün bu saatte, günün gönderisine ek olarak yayınlanır. Boş bırakırsanız videolar gönderi sırasında bekler.</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ([0, 1] as $i)
                <input type="time" wire:model="reelTimes.{{ $i }}" aria-label="{{ $i + 1 }}. video saati" class="w-32 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" @disabled(! $canWrite)>
            @endforeach
        </div>
        <label class="mt-3 flex items-start gap-2 text-sm" data-trial-reels>
            <input type="checkbox" wire:model="trialReels" class="mt-0.5 rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900" @disabled(! $canWrite)>
            <span>Günün son videosu deneme Reels’i olsun
                <span class="block text-xs text-gray-500">İki video saati varken geçerlidir. Instagram videoyu önce takip etmeyenlere gösterir; iyi giderse profile kendisi ekler.</span>
            </span>
        </label>
    </div>
    <div data-text-times>
        <p class="text-sm font-medium">Metin gönderisi saatleri (Threads ve LinkedIn)</p>
        <p class="mt-1 text-xs text-gray-500">Onayladığınız yalnız metin gönderileri kendi sırasına girer ve her gün bu saatlerde Threads ile LinkedIn’de yayınlanır; Instagram sırasını etkilemez. İkisini de boş bırakırsanız metin gönderileri yazıldıkları tarihte çıkar.</p>
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ([0, 1] as $i)
                <input type="time" wire:model="textTimes.{{ $i }}" aria-label="{{ $i + 1 }}. metin gönderisi saati" class="w-32 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" @disabled(! $canWrite)>
            @endforeach
        </div>
    </div>
    @if ($canWrite)
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="saveSchedule" class="btn-primary" wire:loading.attr="disabled">Düzeni kaydet</button>
            <p class="text-xs text-gray-500">Belirli bir güne ait gönderide “Tarihi sabitle”ye basın; o gönderi sıradan bağımsız kendi gününde yayınlanır.</p>
        </div>
    @endif
</section>
@else
@php
    $fb = $accounts->where('platform', 'facebook');
    $ig = $accounts->where('platform', 'instagram');
    $li = $accounts->where('platform', 'linkedin');
    $appLink = $metaAppId ? 'https://developers.facebook.com/apps/'.$metaAppId.'/use_cases/' : 'https://developers.facebook.com/apps/';
    $stepDone = fn (bool $done): string => $done ? 'tone-ok' : 'tone-look';
@endphp
<section class="space-y-4" data-accounts>
    {{-- Hesap bağlama (yakup 2026-11-08: "entegrasyonu yapabileceğim alanı bulamadım"): one card, three steps,
         each with its own state, then the connected accounts. The last attempt stays visible. --}}
    <div class="card space-y-5 p-5" data-connect-steps>
        <div>
            <h2 class="section-title">Hesapları bağlayın</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Instagram ve Facebook tek Meta girişiyle bağlanır; gönderiler, yorumlar ve DM’ler buradan yönetilir. Reklam ve WhatsApp bağlantılarına dokunmaz.</p>
        </div>

        <details @if (! ($fb->isNotEmpty() && $ig->isNotEmpty())) open @endif data-setup-steps>
            <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-300">{{ ($fb->isNotEmpty() && $ig->isNotEmpty()) ? 'Kurulum adımları ve yeniden bağlama (tamamlandı)' : 'Kurulum adımları' }}</summary>
            <div class="mt-4">
        <ol class="space-y-4">
            <li class="flex gap-3" data-step="instagram-page">
                <span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-200 text-sm font-semibold dark:border-gray-800', $stepDone($ig->isNotEmpty())])>{{ $ig->isNotEmpty() ? '✓' : '1' }}</span>
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-semibold">Instagram hesabı Facebook sayfasına bağlı olsun</p>
                    <p class="text-gray-600 dark:text-gray-300">Instagram uygulamasında: Profil › <b>Profili düzenle</b> › <b>Sayfa</b> › Moximu Facebook sayfasını seçin. Hesap türü <b>İşletme</b> ya da <b>İçerik üreticisi</b> olmalı. Bağlı değilse Meta girişi yalnız Facebook’u getirir.</p>
                    @if ($fb->isNotEmpty() && $ig->isEmpty())
                        <p class="tone-look rounded-lg px-3 py-2" data-ig-missing>Facebook sayfası bağlandı ama ona bağlı Instagram hesabı gelmedi. Yukarıdaki bağlantıyı yapıp 3. adımı yeniden çalıştırın.</p>
                    @endif
                </div>
            </li>
            <li class="flex gap-3" data-step="meta-app">
                <span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-200 text-sm font-semibold dark:border-gray-800', $stepDone($fb->isNotEmpty())])>{{ $fb->isNotEmpty() ? '✓' : '2' }}</span>
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-semibold">Meta uygulamasında sosyal medya izinleri açık olsun (bir kez)</p>
                    <p class="text-gray-600 dark:text-gray-300">Giriş, WhatsApp’ta kullandığımız uygulamayla açılır{{ $metaAppId ? ' (App ID '.$metaAppId.')' : '' }}. <a href="{{ $appLink }}" target="_blank" rel="noopener" class="underline">Uygulamanın kullanım durumlarını açın ↗</a>, <b>Add use cases</b> ile şu ikisini ekleyin: <b>“Manage everything on your Page”</b> ve <b>“Manage messaging &amp; content on Instagram”</b>. Her birinde <b>Customize</b> deyip listedeki <b>tüm</b> izinlerin yanında <b>Add</b>’e basın; Meta bunlardan biri eksikse girişte “Invalid Scopes: …” der (ör. <code class="text-xs">pages_read_user_content</code>), adı geçen izni aynı yerden ekleyip yeniden deneyin. Uygulamada yönetici olduğunuz için Meta incelemesi gerekmez.</p>
                    <details class="text-gray-600 dark:text-gray-300">
                        <summary class="cursor-pointer">Gereken izinler ve gelişmiş ayar</summary>
                        <div class="mt-2 space-y-3">
                            <p><code class="text-xs">{{ implode(', ', $scopes) }}</code></p>
                            <p>Yönlendirme adresi WhatsApp kurulumunda zaten eklendi: <code class="text-xs">{{ $callbackUrl }}</code>. Giriş “Özellik kullanılamıyor” ya da “Geçersiz izin” derse izinlerden biri eklenmemiştir.</p>
                            <p>Yapılandırma kimliği <b>boş kalmalı</b>{{ $configId !== '' ? ' (şu an dolu: '.$configId.'; giriş hatası alırsanız silip kaydedin)' : '' }}; boşken giriş yukarıdaki izin listesiyle açılır. Meta giriş penceresi buna rağmen hata verirse: uygulamada <b>Facebook Login for Business › Configurations › Create configuration</b>, ad “Moximu sosyal”, erişim anahtarı <b>User access token</b> (System user değil), varlıklar <b>Pages</b> ve <b>Instagram accounts</b>, izinler yukarıdakiler, WhatsApp izinleri seçilmeden; çıkan Configuration ID’yi buraya kaydedin.</p>
                            <div class="flex flex-wrap gap-2"><input type="text" wire:model="configId" inputmode="numeric" placeholder="Yapılandırma kimliği (boş bırakın)" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"><button type="button" wire:click="saveConfigId" class="btn">Kaydet</button></div>
                            <p>Başka bir Meta uygulaması kullanılacaksa (gerekmez):</p>
                            <div class="flex flex-wrap gap-2"><input type="text" wire:model="socialAppId" inputmode="numeric" placeholder="App ID" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"><input type="password" wire:model="socialAppSecret" autocomplete="off" placeholder="App Secret" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"><button type="button" wire:click="saveSocialApp" class="btn">Uygulamayı kaydet</button></div>
                            @error('socialAppSecret')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </details>
                </div>
            </li>
            <li class="flex gap-3" data-step="meta-login">
                <span @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-200 text-sm font-semibold dark:border-gray-800', $stepDone($fb->isNotEmpty() && $ig->isNotEmpty())])>{{ $fb->isNotEmpty() && $ig->isNotEmpty() ? '✓' : '3' }}</span>
                <div class="min-w-0 space-y-2 text-sm">
                    <p class="font-semibold">Meta ile giriş yapın</p>
                    <p class="text-gray-600 dark:text-gray-300">Açılan pencerede sizin Facebook hesabınızla devam edin; sayfa listesinde <b>Moximu</b> sayfasını, Instagram listesinde <b>@moximucom</b>’u işaretleyin ve tüm izinlere izin verin.</p>
                    @if ($canWrite)
                        <a href="{{ route('integrations.meta.social.authorize') }}" class="btn-primary inline-flex" data-meta-connect>{{ $fb->isNotEmpty() ? 'Meta’yı yeniden bağla' : 'Meta’yı bağla' }}</a>
                    @endif
                    @if ($lastAttempt)
                        <p @class(['rounded-lg px-3 py-2', 'tone-ok' => $lastAttempt['ok'], 'tone-look' => ! $lastAttempt['ok']]) data-last-attempt>Son deneme {{ \Illuminate\Support\Carbon::parse($lastAttempt['at'])->timezone($tz)->format('d.m.Y H:i') }}: {{ $lastAttempt['text'] }}</p>
                    @endif
                </div>
            </li>
        </ol>
            </div>
        </details>
    </div>

    @php
        $onAccounts = $accounts->where('enabled', true);
        $offAccounts = $accounts->where('enabled', false);
        $accountRow = function ($account) use ($tz, $canWrite): string {
            return view('livewire.operator.social.partials.account-row', ['account' => $account, 'tz' => $tz, 'canWrite' => $canWrite])->render();
        };
    @endphp
    <div class="card divide-y divide-gray-100 dark:divide-gray-800" data-account-list>
        <h2 class="section-title p-4">Yayın yapılan hesaplar</h2>
        @forelse ($onAccounts as $account)
            {!! $accountRow($account) !!}
        @empty
            <p class="p-4 text-sm text-gray-500">Henüz açık hesap yok; yukarıdaki adımlarla başlayın.</p>
        @endforelse
    </div>
    @if ($offAccounts->isNotEmpty())
        <details class="card" data-off-accounts>
            <summary class="cursor-pointer p-4 text-sm text-gray-600 dark:text-gray-300">Kapalı hesaplar ({{ $offAccounts->count() }}): Meta girişinde gelen müşteri sayfaları. Moximu gönderileri bunlara gitmez.</summary>
            <div class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($offAccounts as $account)
                    {!! $accountRow($account) !!}
                @endforeach
            </div>
        </details>
    @endif

    @php
        $liAccount = $li->first();
        $liLive = $liAccount !== null && ! $liAccount->isManual();
        $liEnds = $liAccount?->token_expires_at;
        $liStep = fn (bool $done): string => $done ? 'tone-ok' : 'tone-look';
        $liBadge = 'flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-200 text-sm font-semibold dark:border-gray-800';
    @endphp
    <div class="card space-y-5 p-5" data-linkedin>
        <div>
            <h2 class="section-title">LinkedIn şirket sayfası{{ $liLive ? ' · otomatik paylaşım açık' : '' }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @if ($liLive)
                    {{ $liAccount->name }} bağlı. LinkedIn metni olan onaylı gönderiler Instagram’la aynı saatte LinkedIn’de de kendiliğinden paylaşılır (görsel, çoklu görsel ve video; hikâyeler LinkedIn’e gitmez).
                    @if ($liEnds)
                        Bağlantı {{ $liEnds->timezone($tz)->format('d.m.Y') }} tarihine kadar geçerli{{ $liAccount->refresh_token ? ' ve süresi dolmadan kendiliğinden yenilenir' : '; bitmeden “LinkedIn’i yeniden bağla”ya basmanız yeterli' }}.
                    @endif
                @else
                    Şirket sayfasına otomatik paylaşım için LinkedIn’in ayrıca onay verdiği izin gerekir (Community Management API). Aşağıdaki dört adım bir kez yapılır; o zamana kadar LinkedIn gönderileri Bugün sekmesinde “Metni kopyala · LinkedIn’i aç · Paylaştım” olarak bekler.
                @endif
            </p>
            @if ($liLive && $liEnds && ! $liAccount->refresh_token && $liEnds->isBefore(now()->addDays(10)))
                <p class="tone-look mt-2 rounded-lg px-3 py-2 text-sm" data-linkedin-expiring>LinkedIn bağlantısı {{ $liEnds->timezone($tz)->format('d.m.Y') }} tarihinde bitiyor; aşağıdan yeniden bağlayın.</p>
            @endif
        </div>
        <details @if (! ($liLive)) open @endif data-setup-steps>
            <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-300">{{ ($liLive) ? 'Kurulum adımları ve yeniden bağlama (tamamlandı)' : 'Kurulum adımları' }}</summary>
            <div class="mt-4">
        <ol class="space-y-4">
            <li class="flex gap-3" data-step="linkedin-app">
                <span @class([$liBadge, $liStep($linkedinApp)])>{{ $linkedinApp ? '✓' : '1' }}</span>
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-semibold">LinkedIn’de uygulama açın ve sayfaya doğrulatın</p>
                    <p class="text-gray-600 dark:text-gray-300"><a href="https://www.linkedin.com/developers/apps/new" target="_blank" rel="noopener" class="underline">LinkedIn Developers › Create app ↗</a>: ad “Moximu”, LinkedIn Page alanına <b>Moximu</b> şirket sayfası, gizlilik sayfası olarak sitenin gizlilik sayfası, logo olarak Moximu logosu. Oluşunca <b>Settings</b> sekmesinde <b>Verify</b>’a basın, çıkan bağlantıyı Moximu sayfasının süper yöneticisi olarak açıp onaylayın.</p>
                </div>
            </li>
            <li class="flex gap-3" data-step="linkedin-product">
                <span @class([$liBadge, $liStep($liLive)])>{{ $liLive ? '✓' : '2' }}</span>
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="font-semibold">“Community Management API” iznini isteyin</p>
                    <p class="text-gray-600 dark:text-gray-300">Uygulamanın <b>Products</b> sekmesinde <b>Community Management API › Request access</b>. Bu ürün uygulamadaki tek ürün olmalı (başka ürün eklemeyin). Formda şirketin yasal adı, adresi ve kullanım amacı sorulur: “Kendi şirket sayfamızda gönderi paylaşmak”. LinkedIn birkaç gün içinde onaylar; onay gelmeden 4. adım “izin yok” der.</p>
                </div>
            </li>
            <li class="flex gap-3" data-step="linkedin-keys">
                <span @class([$liBadge, $liStep($linkedinApp)])>{{ $linkedinApp ? '✓' : '3' }}</span>
                <div class="min-w-0 space-y-2 text-sm">
                    <p class="font-semibold">Dönüş adresini ekleyin, anahtarları buraya kaydedin</p>
                    <p class="text-gray-600 dark:text-gray-300"><b>Auth</b> sekmesinde <b>Authorized redirect URLs</b>’e şunu ekleyin: <code class="text-xs">{{ $linkedinCallback }}</code>. Aynı sekmedeki <b>Client ID</b> ve <b>Primary Client Secret</b>’ı aşağıya yapıştırın.</p>
                    @if ($canWrite)
                        <div class="flex flex-wrap gap-2">
                            <input type="text" wire:model="linkedinClientId" autocomplete="off" placeholder="Client ID" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <input type="password" wire:model="linkedinClientSecret" autocomplete="off" placeholder="{{ $linkedinApp ? 'Client Secret (kayıtlı)' : 'Primary Client Secret' }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <button type="button" wire:click="saveLinkedinApp" class="btn" data-linkedin-save>Kaydet</button>
                        </div>
                        @error('linkedinClientId')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('linkedinClientSecret')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                </div>
            </li>
            <li class="flex gap-3" data-step="linkedin-login">
                <span @class([$liBadge, $liStep($liLive)])>{{ $liLive ? '✓' : '4' }}</span>
                <div class="min-w-0 space-y-2 text-sm">
                    <p class="font-semibold">LinkedIn ile giriş yapın</p>
                    <p class="text-gray-600 dark:text-gray-300">Moximu sayfasının süper yöneticisi olan kendi LinkedIn hesabınızla girip izin verin. Sayfa kendiliğinden seçilir.</p>
                    @if ($canWrite && $linkedinApp)
                        <a href="{{ route('integrations.linkedin.authorize') }}" class="btn-primary inline-flex" data-linkedin-connect>{{ $liLive ? 'LinkedIn’i yeniden bağla' : 'LinkedIn’i bağla' }}</a>
                    @endif
                    @if ($linkedinAttempt)
                        <p @class(['rounded-lg px-3 py-2', 'tone-ok' => $linkedinAttempt['ok'], 'tone-look' => ! $linkedinAttempt['ok']]) data-linkedin-attempt>Son deneme {{ \Illuminate\Support\Carbon::parse($linkedinAttempt['at'])->timezone($tz)->format('d.m.Y H:i') }}: {{ $linkedinAttempt['text'] }}</p>
                    @endif
                </div>
            </li>
        </ol>
            </div>
        </details>
        @if ($canWrite && ! $liLive)
            <details class="text-sm text-gray-600 dark:text-gray-300">
                <summary class="cursor-pointer">Onay gelene kadar sayfayı elle ekle (kopyala ve aç)</summary>
                <form wire:submit="addLinkedin" class="mt-2 space-y-2">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <input type="text" wire:model="linkedinName" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" placeholder="Ad">
                        <input type="url" wire:model="linkedinUrl" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900" placeholder="https://www.linkedin.com/company/…">
                    </div>
                    <button type="submit" class="btn">{{ $li->isNotEmpty() ? 'Güncelle' : 'LinkedIn sayfasını ekle' }}</button>
                </form>
            </details>
        @endif
    </div>

    @php
        $meAccount = $accounts->where('platform', 'linkedin_me')->first();
        $meLive = $meAccount !== null && ! $meAccount->isManual();
        $meEnds = $meAccount?->token_expires_at;
    @endphp
    <div class="card space-y-5 p-5" data-linkedin-personal>
        <div>
            <h2 class="section-title">Kişisel LinkedIn profili{{ $meLive ? ' · otomatik paylaşım açık' : '' }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @if ($meLive)
                    {{ $meAccount->name }} bağlı. Kişisel metni olan onaylı gönderiler saatinde profilinizde paylaşılır. Bağlantı {{ $meEnds?->timezone($tz)->format('d.m.Y') }} tarihine kadar geçerli; LinkedIn bu izinde kendiliğinden yenileme vermez, bitmeden yeniden bağlamanız gerekir. Kişisel gönderilerin istatistiğini LinkedIn bu yolla vermez.
                @else
                    Kendi profilinizde paylaşım için şirket sayfasından ayrı, ikinci bir LinkedIn uygulaması gerekir (LinkedIn iki izni aynı uygulamada vermiyor). Üç adım bir kez yapılır; izin anında verilir, onay beklemez.
                @endif
            </p>
            @if ($meLive && $meEnds && $meEnds->isBefore(now()->addDays(10)))
                <p class="tone-look mt-2 rounded-lg px-3 py-2 text-sm" data-linkedin-personal-expiring>Kişisel LinkedIn bağlantısı {{ $meEnds->timezone($tz)->format('d.m.Y') }} tarihinde bitiyor; aşağıdan yeniden bağlayın.</p>
            @endif
        </div>
        <details @if (! $meLive) open @endif data-setup-steps>
            <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-300">{{ $meLive ? 'Kurulum adımları ve yeniden bağlama (tamamlandı)' : 'Kurulum adımları' }}</summary>
            <ol class="mt-4 space-y-4 text-sm">
                <li class="flex gap-3" data-step="linkedin-me-app">
                    <span @class([$liBadge, $liStep($linkedinPersonalApp)])>{{ $linkedinPersonalApp ? '✓' : '1' }}</span>
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">İkinci uygulamayı açın ve iki ürünü ekleyin</p>
                        <p class="text-gray-600 dark:text-gray-300"><a href="https://www.linkedin.com/developers/apps/new" target="_blank" rel="noopener" class="underline">LinkedIn’de yeni uygulama</a> açın (ad: Moximu Kişisel, sayfa: Moximu). <b>Products</b> sekmesinde <b>Share on LinkedIn</b> ve <b>Sign In with LinkedIn using OpenID Connect</b> ürünlerini ekleyin.</p>
                    </div>
                </li>
                <li class="flex gap-3" data-step="linkedin-me-keys">
                    <span @class([$liBadge, $liStep($linkedinPersonalApp)])>{{ $linkedinPersonalApp ? '✓' : '2' }}</span>
                    <div class="min-w-0 space-y-2">
                        <p class="font-medium">Dönüş adresini ekleyin, anahtarları buraya yazın</p>
                        <p class="text-gray-600 dark:text-gray-300"><b>Auth</b> sekmesinde <b>Authorized redirect URLs</b>’e şunu ekleyin: <code class="text-xs">{{ $linkedinCallback }}</code></p>
                        @if ($canWrite)
                            <div class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                                <input type="text" wire:model="linkedinPersonalClientId" autocomplete="off" placeholder="Client ID" class="rounded-lg border border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                                <input type="password" wire:model="linkedinPersonalClientSecret" autocomplete="off" placeholder="{{ $linkedinPersonalApp ? 'Client Secret (kayıtlı)' : 'Primary Client Secret' }}" class="rounded-lg border border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                                <button type="button" wire:click="saveLinkedinPersonalApp" class="btn" data-linkedin-personal-save>Kaydet</button>
                            </div>
                            @error('linkedinPersonalClientId')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                            @error('linkedinPersonalClientSecret')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @endif
                    </div>
                </li>
                <li class="flex gap-3" data-step="linkedin-me-login">
                    <span @class([$liBadge, $liStep($meLive)])>{{ $meLive ? '✓' : '3' }}</span>
                    <div class="min-w-0 space-y-2">
                        <p class="font-medium">Kendi LinkedIn hesabınızla giriş yapın</p>
                        @if ($canWrite && $linkedinPersonalApp)
                            <a href="{{ route('integrations.linkedin.authorize', ['hesap' => 'kisisel']) }}" class="btn-primary inline-flex" data-linkedin-personal-connect>{{ $meLive ? 'Kişisel LinkedIn’i yeniden bağla' : 'Kişisel LinkedIn’i bağla' }}</a>
                        @endif
                        @if ($linkedinPersonalAttempt)
                            <p @class(['rounded-lg px-3 py-2', 'tone-ok' => $linkedinPersonalAttempt['ok'], 'tone-look' => ! $linkedinPersonalAttempt['ok']])>Son deneme: {{ $linkedinPersonalAttempt['text'] }}</p>
                        @endif
                    </div>
                </li>
            </ol>
        </details>
    </div>

    @php
        $thAccount = $accounts->where('platform', 'threads')->first();
        $thLive = $thAccount !== null && ! $thAccount->isManual() && ! ($thAccount->token_expires_at?->isPast() ?? false);
    @endphp
    <div class="card space-y-5 p-5" data-threads>
        <div>
            <h2 class="section-title">Threads{{ $thLive ? ' · otomatik paylaşım açık' : '' }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @if ($thLive)
                    {{ $thAccount->displayName() }} bağlı. Threads metni yazılan gönderiler ve blog yazıları Threads’te kısa metin olarak paylaşılır. Bağlantı {{ $thAccount->token_expires_at?->timezone($tz)->format('d.m.Y') }} tarihine kadar geçerli ve bitmeden kendiliğinden yenilenir.
                @else
                    Blog yazıları Instagram yerine Threads’te kısa metin olarak paylaşılır. Aşağıdaki dört adım bir kez yapılır; Meta incelemesi beklenmez.
                @endif
            </p>
        </div>
        <details @if (! ($thLive)) open @endif data-setup-steps>
            <summary class="cursor-pointer text-sm text-gray-600 dark:text-gray-300">{{ ($thLive) ? 'Kurulum adımları ve yeniden bağlama (tamamlandı)' : 'Kurulum adımları' }}</summary>
            <div class="mt-4">
        <ol class="space-y-4 text-sm">
            <li class="flex gap-3" data-step="threads-profile">
                <span @class([$liBadge, $liStep($thLive)])>{{ $thLive ? '✓' : '1' }}</span>
                <div class="min-w-0 space-y-1">
                    <p class="font-semibold">Threads profilini açın</p>
                    <p class="text-gray-600 dark:text-gray-300">Telefonda Threads uygulamasını indirip <b>@moximucom</b> Instagram hesabıyla girin. Profil zaten varsa bu adımı geçin.</p>
                </div>
            </li>
            <li class="flex gap-3" data-step="threads-usecase">
                <span @class([$liBadge, $liStep($threadsApp)])>{{ $threadsApp ? '✓' : '2' }}</span>
                <div class="min-w-0 space-y-1">
                    <p class="font-semibold">Meta uygulamasına Threads’i ekleyin</p>
                    <p class="text-gray-600 dark:text-gray-300"><a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener" class="underline">Meta for Developers › Uygulamalarım ↗</a>: sosyal medya için kullandığımız uygulamada <b>Kullanım alanları › Kullanım alanı ekle › Threads API’ye eriş</b>. Ardından <b>Threads API’ye eriş › Ayarlar</b> sayfasında üç “Yönlendirme / geri çağırma URL” alanına şunu yazın: <code class="text-xs">{{ $threadsCallback }}</code>. <b>İzinler</b> bölümünde <b>threads_content_publish</b> ve <b>threads_manage_insights</b> izinlerini ekleyin (ikincisi gönderi sayılarını Sonuçlar’da gösterir; ekledikten sonra “Threads’i yeniden bağla”ya basın).</p>
                </div>
            </li>
            <li class="flex gap-3" data-step="threads-tester">
                <span @class([$liBadge, $liStep($thLive)])>{{ $thLive ? '✓' : '3' }}</span>
                <div class="min-w-0 space-y-1">
                    <p class="font-semibold">Hesabı test kullanıcısı yapın ve kimlikleri kaydedin</p>
                    <p class="text-gray-600 dark:text-gray-300"><b>Uygulama rolleri › Roller › Kişi ekle › Threads test kullanıcısı</b>: <b>moximucom</b>. Sonra Threads uygulamasında <b>Ayarlar › Hesap › Web sitesi izinleri › Davetler</b>’den daveti kabul edin. Threads ayarları sayfasındaki <b>Threads uygulama kimliği</b> ve <b>Threads uygulama gizli anahtarı</b>’nı (Meta uygulama kimliğinden farklıdır) aşağıya yapıştırın.</p>
                    @if ($canWrite)
                        <div class="mt-2 grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                            <input type="text" wire:model="threadsAppId" autocomplete="off" placeholder="Threads uygulama kimliği" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <input type="password" wire:model="threadsAppSecret" autocomplete="off" placeholder="{{ $threadsApp ? 'Gizli anahtar (kayıtlı)' : 'Threads uygulama gizli anahtarı' }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <button type="button" wire:click="saveThreadsApp" class="btn" data-threads-save>Kaydet</button>
                        </div>
                        @error('threadsAppId')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        @error('threadsAppSecret')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @endif
                </div>
            </li>
            <li class="flex gap-3" data-step="threads-login">
                <span @class([$liBadge, $liStep($thLive)])>{{ $thLive ? '✓' : '4' }}</span>
                <div class="min-w-0 space-y-2">
                    <p class="font-semibold">Threads ile giriş yapın</p>
                    <p class="text-gray-600 dark:text-gray-300">@moximucom hesabıyla girip izin verin.</p>
                    @if ($canWrite && $threadsApp)
                        <a href="{{ route('integrations.threads.authorize') }}" class="btn-primary inline-flex" data-threads-connect>{{ $thLive ? 'Threads’i yeniden bağla' : 'Threads’i bağla' }}</a>
                    @endif
                    @if ($threadsAttempt)
                        <p @class(['rounded-lg px-3 py-2', 'tone-ok' => $threadsAttempt['ok'], 'tone-look' => ! $threadsAttempt['ok']]) data-threads-attempt>Son deneme {{ \Illuminate\Support\Carbon::parse($threadsAttempt['at'])->timezone($tz)->format('d.m.Y H:i') }}: {{ $threadsAttempt['text'] }}</p>
                    @endif
                </div>
            </li>
        </ol>
            </div>
        </details>
    </div>
    <div class="card p-5" data-youtube>
        <h2 class="section-title">YouTube</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Henüz bağlı değil. Moximu kanalı açıldığında bağlantı adımları burada yer alacak; günün videosu Shorts olarak da çıkacak.</p>
    </div>
</section>
@endif
