@php
    // Sosyal medya (ADR-083). The question: "What goes out on Moximu's accounts in the coming weeks, and what waits for
    // my approval?" One card per post: visual, text, channels, time. A week is approved with one click.
    $chipClass = fn (string $status): string => match ($status) {
        'draft', 'failed', 'partial', 'manual' => 'tone-look',
        'published', 'done' => 'tone-ok',
        default => 'tone-info',
    };
    $tabs = ['instagram' => 'Instagram', 'plan' => 'Plan', 'trendler' => 'Trendler', 'gecmis' => 'Yayınlananlar', 'yeni' => 'Yeni gönderi', 'hesaplar' => 'Hesaplar'];
    $meta = $accounts->whereIn('platform', ['instagram', 'facebook']);
@endphp
<div class="dark:text-gray-200" data-social-page>
    <div class="space-y-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <p class="mb-1 text-xs text-gray-500"><a href="{{ route('operator.marketing', ['kanal' => 'social']) }}" wire:navigate class="link-neutral" data-social-marketing-link>Pazarlama</a> › Sosyal medya</p>
                <h1 class="page-title">Sosyal medya</h1>
                <p class="page-sub">Instagram, Facebook ve LinkedIn. Onayladığınız gönderiler paylaşım düzenindeki gün ve saatte, sırayla yayınlanır{{ $drafts > 0 ? '; '.$drafts.' gönderi onayınızı bekliyor.' : '.' }}</p>
            </div>
        </header>

        <nav class="flex flex-wrap gap-1.5" role="tablist" aria-label="Sekmeler">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key]) role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}</button>
            @endforeach
            {{-- Messages and comments are answered in the Gelen kutusu (Aşama 2). --}}
            <a href="{{ route('operator.inbox') }}" wire:navigate class="seg" data-social-inbox-link>Mesajlar{{ $waitingCount > 0 ? ' ('.$waitingCount.')' : '' }} →</a>
        </nav>

        @if ($message !== '')
            <p role="status" @class(['rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800', 'tone-ok' => $messageTone === 'success', 'tone-look' => $messageTone === 'error', 'tone-info' => $messageTone === 'info']) data-social-message>{{ $message }}</p>
        @endif

        @if ($meta->isEmpty() && $tab !== 'hesaplar')
            <p class="tone-look rounded-lg border border-gray-200 px-3.5 py-2.5 text-sm dark:border-gray-800">Instagram ve Facebook henüz bağlı değil; gönderiler hazırlanır ama yayınlanamaz. <button type="button" wire:click="setTab('hesaplar')" class="underline">Hesapları bağlayın</button>.</p>
        @endif

        @if ($tab === 'instagram')
            @include('livewire.operator.social.partials.instagram')
        @elseif ($tab === 'plan')
            {{-- Paylaşım düzeni (yakup 2026-11-12): the days and the hour; approved posts take these days in line order. --}}
            <section class="card space-y-4 p-5" data-social-schedule>
                <div>
                    <h2 class="section-title">Paylaşım düzeni</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Onayladığınız gönderiler sıraya girer ve seçtiğiniz günlerde, günde bir tane olarak Instagram ve Facebook’ta yayınlanır. Sırada {{ count($line) }} gönderi{{ $reelLine !== null ? ', '.count($reelLine).' video' : '' }} ve {{ count($storyLine) }} story var.</p>
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
                </div>
                @if ($canWrite)
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="saveSchedule" class="btn-primary" wire:loading.attr="disabled">Düzeni kaydet</button>
                        <p class="text-xs text-gray-500">Belirli bir güne ait gönderide “Tarihi sabitle”ye basın; o gönderi sıradan bağımsız kendi gününde yayınlanır.</p>
                    </div>
                @endif
            </section>

            @if ($manual->isNotEmpty())
                <section class="space-y-3" data-manual>
                    <h2 class="section-title">LinkedIn’de paylaşmanız bekleniyor</h2>
                    @foreach ($manual as $target)
                        <div class="card space-y-3 p-4" x-data="{ copied: false }">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold">{{ $target->post?->title ?? 'Gönderi' }} · {{ $target->account?->displayName() }}</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="btn" x-on:click="navigator.clipboard.writeText($refs.text.value); copied = true"><span x-text="copied ? 'Kopyalandı' : 'Metni kopyala'"></span></button>
                                    @foreach ($target->post?->mediaItems() ?? [] as $i => $item)
                                        <a class="btn" href="{{ $item['url'] }}" download>{{ $item['type'] === 'video' ? 'Videoyu' : 'Görseli' }} indir{{ count($target->post->mediaItems()) > 1 ? ' '.($i + 1) : '' }}</a>
                                    @endforeach
                                    <a class="btn" href="{{ $target->account?->profileUrl() ?? 'https://www.linkedin.com/' }}" target="_blank" rel="noopener">LinkedIn’i aç ↗</a>
                                    @if ($canWrite)<button type="button" class="btn-primary" wire:click="markDone({{ $target->id }})">Paylaştım</button>@endif
                                </div>
                            </div>
                            <textarea x-ref="text" readonly rows="5" class="w-full rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-800 dark:bg-gray-900">{{ $target->post?->linkedin_text }}</textarea>
                        </div>
                    @endforeach
                </section>
            @endif

            @forelse ($weeks as $weekStart => $posts)
                @php
                    $start = \Illuminate\Support\Carbon::parse($weekStart, $tz);
                    $weekDrafts = $posts->where('status', 'draft')->count();
                @endphp
                <section class="space-y-3" data-week="{{ $weekStart }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="section-title">{{ $start->translatedFormat('j F') }} – {{ $start->copy()->addDays(6)->translatedFormat('j F') }} haftası · {{ $posts->count() }} gönderi</h2>
                        @if ($canWrite && $weekDrafts > 0)
                            <button type="button" class="btn-primary" x-on:click="if (confirm('Bu haftanın onay bekleyen {{ $weekDrafts }} gönderisi onaylansın mı? Paylaşım düzenindeki günlere sırayla yerleşir.')) $wire.approveWeek('{{ $weekStart }}')">Haftayı onayla ({{ $weekDrafts }})</button>
                        @endif
                    </div>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($posts as $post)
                            @include('livewire.operator.social.partials.post-card', ['post' => $post])
                        @endforeach
                    </div>
                </section>
            @empty
                <x-ta.empty-state size="sm" title="Planlı gönderi yok." />
            @endforelse
        @elseif ($tab === 'trendler')
            {{-- Trendler (yakup 2026-11-13): posts of watched accounts that did far better than that account's usual. --}}
            <section class="card space-y-4 p-5" data-social-trends>
                <div>
                    <h2 class="section-title">Şu an tutan gönderiler</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">İzlediğiniz hesapların son üç haftadaki gönderileri okunur; hesabın kendi ortalamasının en az iki katı beğeni ve yorum alan gönderi burada görünür. Yalnızca işletme ve içerik üretici hesapları okunabilir.</p>
                </div>
                @if ($canWrite)
                    <label class="block text-sm font-medium">İzlenen hesaplar <span class="font-normal text-gray-500">(her satıra bir kullanıcı adı)</span>
                        <textarea wire:model="watchlist" rows="5" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" placeholder="kullaniciadi"></textarea></label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="saveWatchlist" class="btn" wire:loading.attr="disabled">Listeyi kaydet</button>
                        <button type="button" wire:click="readTrends" class="btn-primary" wire:loading.attr="disabled" wire:target="readTrends"><span wire:loading.remove wire:target="readTrends">Şimdi oku</span><span wire:loading wire:target="readTrends">Okunuyor…</span></button>
                    </div>
                @endif
                @if (($trends['read_at'] ?? null) !== null)
                    <p class="text-xs text-gray-500">Son okuma: {{ \Illuminate\Support\Carbon::parse($trends['read_at'])->timezone($tz)->translatedFormat('j F H:i') }} · {{ count($trends['accounts']) }} hesap okundu{{ $trends['errors'] !== [] ? ', '.count($trends['errors']).' hesap okunamadı ('.implode(', ', array_filter(array_keys($trends['errors']))).')' : '' }}.</p>
                @endif
            </section>
            @if (($trends['hits'] ?? []) !== [])
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-trend-hits>
                    @foreach ($trends['hits'] as $hit)
                        <a href="{{ $hit['permalink'] }}" target="_blank" rel="noopener" class="card block space-y-2 p-4 hover:border-brand-500">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="tone-ok rounded-full px-2 py-0.5">ortalamasının {{ str_replace('.', ',', (string) $hit['times']) }} katı</span>
                                <span class="text-gray-500">{{ $hit['type'] === 'reels' ? 'Reels' : ($hit['type'] === 'carousel_album' ? 'Kaydırmalı' : 'Görsel') }} · {{ \Illuminate\Support\Carbon::parse($hit['at'])->timezone($tz)->translatedFormat('j F') }}</span>
                            </div>
                            <p class="text-sm font-semibold">{{ '@'.$hit['account'] }} <span class="font-normal text-gray-500">· {{ number_format($hit['followers'], 0, ',', '.') }} takipçi</span></p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $hit['caption'] !== '' ? $hit['caption'] : 'Açıklama yok.' }}</p>
                            <p class="text-xs text-gray-500">{{ number_format($hit['likes'], 0, ',', '.') }} beğeni · {{ number_format($hit['comments'], 0, ',', '.') }} yorum · Instagram’da aç ↗</p>
                        </a>
                    @endforeach
                </div>
            @elseif (($trends['read_at'] ?? null) !== null)
                <x-ta.empty-state size="sm" title="Bu okumada ortalamasının iki katını geçen gönderi yok." />
            @endif
        @elseif ($tab === 'gecmis')
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($history as $post)
                    @include('livewire.operator.social.partials.post-card', ['post' => $post])
                @empty
                    <x-ta.empty-state size="sm" title="Henüz yayınlanan gönderi yok." />
                @endforelse
            </div>
        @elseif ($tab === 'yeni')
            <form wire:submit="createPost" class="card max-w-3xl space-y-4 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block text-sm">Tür
                        <select wire:model="newKind" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <option value="image">Gönderi (tek görsel)</option><option value="carousel">Kaydırmalı (2-10)</option><option value="reels">Reels (video)</option><option value="story">Story</option>
                        </select>
                    </label>
                    <label class="block text-sm">Başlık
                        <select wire:model="newPillar" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                            <option value="">—</option><option value="rehber">Rehber</option><option value="vaka">Vaka</option><option value="gundem">Gündem</option><option value="anlatim">Yakup anlatıyor</option><option value="guven">Güven</option>
                        </select>
                    </label>
                    <label class="block text-sm">Yayın zamanı
                        <input type="datetime-local" wire:model="newTime" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    </label>
                </div>
                <label class="block text-sm">Kısa ad (yalnız burada görünür)
                    <input type="text" wire:model="newTitle" maxlength="190" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                </label>
                <label class="block text-sm">Görsel ya da video (jpg, png, mp4; kaydırmalıda sırayla; Reels’te videodan sonra eklenen görsel kapak olur)
                    <input type="file" wire:model="newFiles" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" class="mt-1 block w-full text-sm">
                </label>
                @error('newFiles')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                @error('newFiles.*')<p class="text-sm text-error-600">{{ $message }}</p>@enderror
                <label class="block text-sm">Instagram ve Facebook metni
                    <textarea wire:model="newCaption" rows="6" maxlength="2200" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
                </label>
                <label class="block text-sm">LinkedIn metni (boş bırakılırsa LinkedIn’e gitmez)
                    <textarea wire:model="newLinkedin" rows="6" maxlength="3000" class="mt-1 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></textarea>
                </label>
                <div class="flex gap-2"><button type="submit" class="btn-primary" wire:loading.attr="disabled">Plana ekle</button><span wire:loading wire:target="newFiles" class="text-sm text-gray-500">Dosyalar yükleniyor…</span></div>
            </form>
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
                                Şirket sayfasına otomatik paylaşım için LinkedIn’in ayrıca onay verdiği izin gerekir (Community Management API). Aşağıdaki dört adım bir kez yapılır; o zamana kadar LinkedIn gönderileri Plan’ın başında “Metni kopyala · LinkedIn’i aç · Paylaştım” olarak bekler.
                            @endif
                        </p>
                        @if ($liLive && $liEnds && ! $liAccount->refresh_token && $liEnds->isBefore(now()->addDays(10)))
                            <p class="tone-look mt-2 rounded-lg px-3 py-2 text-sm" data-linkedin-expiring>LinkedIn bağlantısı {{ $liEnds->timezone($tz)->format('d.m.Y') }} tarihinde bitiyor; aşağıdan yeniden bağlayın.</p>
                        @endif
                    </div>
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
            </section>
        @endif
    </div>
</div>
