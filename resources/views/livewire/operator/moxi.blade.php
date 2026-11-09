{{-- Moxi, the corner assistant (yakup 2026-11-09). The corner button is a chat icon made from the Moximu cone (no character); sounds and speech: operator/partials/moxi-script. --}}
<div class="mx-root @if ($alert) has-alert @endif" x-data="moxiChat()" x-init="boot()"
    @moxi-think.window="think()" @moxi-said.window="said($event.detail)" @moxi-happy.window="happy($event.detail)"
    data-rank="{{ $rank['level'] ?? 1 }}" data-rank-name="{{ $rank['name'] ?? 'Çırak' }}" data-cards="{{ $doneCards }}"
    wire:poll.{{ $busy ? '2s' : ($waitingJobs ? '30s' : '90s') }}="refresh" data-moxi>
    <style>
/* Moxi'nin açma düğmesi (yakup 2026-11-09 "logodaki huniyle bütünleşmiş chat ikonu, karakter yok"): Moximu işaretinin
   sarı karesi, içinde tek bir huni (yukarıdan bakılan koni, logodaki gibi açıdan renk geçişi) kuyruklu bir sohbet balonu
   olur; ince sarı çizgi koninin sırtıdır. Düşünürken huni döner, konuşurken nefes alır, uykuda söner. */
@property --g{syntax:'<angle>'; inherits:true; initial-value:157deg}
/* Moxi'nin ikonu: sarı bir sohbet balonu, içindeki "…" Moximu logosunun üç konisi (logodaki gibi yüzeyli gölge). Koniler
   imlece döner (bakar), düşünürken sırayla zıplar, konuşurken atar, uykuda aşağı bakıp söner. */
.mx-c3{display:inline-flex; gap:4px; align-items:center}
.mx-c3 i{display:block; width:15px; height:15px; border-radius:50%; flex:none;
  background:conic-gradient(from 0deg,#155d5a 0deg,#247876 88deg,#28807d 90deg,#2f8c8a 134deg,#164f4c 135deg,#195856 179deg,#02201e 180deg,#093a37 270deg,#155d5a 360deg);
  rotate:calc(var(--g) - 157deg); transition:rotate .8s cubic-bezier(.3,.8,.3,1), scale .3s}
.mx-c3.sm{gap:2px} .mx-c3.sm i{width:6px; height:6px}
.mx-typing .mx-c3 i{width:9px; height:9px}
.mx-c3 i:nth-child(2){animation-delay:.15s!important} .mx-c3 i:nth-child(3){animation-delay:.3s!important}
.mx-c3.is-typing i,.mx-launch.think .mx-c3 i{animation:mxh 1s ease-in-out infinite}
@keyframes mxh{0%,60%,100%{translate:0 0}30%{translate:0 -6px}}
.mx-launch{position:relative; display:grid; place-items:center; width:70px; height:58px; margin:0 0 6px auto; padding:0; border:0; border-radius:20px 20px 20px 7px; background:#FDCF3C; cursor:pointer;
  box-shadow:0 10px 26px rgba(15,61,58,.22); transition:transform .18s ease, box-shadow .18s ease, filter .6s, opacity .6s; animation:mxbreath 4.2s ease-in-out infinite}
.mx-launch::before{content:''; position:absolute; left:0; bottom:-9px; width:16px; height:13px; background:#FDCF3C; clip-path:polygon(0 100%, 0 0, 100% 0)}
@keyframes mxbreath{50%{scale:1.03}}
.mx-launch:hover{transform:translateY(-2px); box-shadow:0 14px 30px rgba(15,61,58,.28)}
.mx-launch:focus-visible{outline:3px solid #1f6461; outline-offset:4px}
.mx-launch > .mx-c3{transition:opacity .15s}
.mx-launch .mx-x{position:absolute; inset:0; display:grid; place-items:center; color:#0f3d3a; opacity:0; transition:opacity .15s}
.mx-launch.is-open > .mx-c3{opacity:0} .mx-launch.is-open .mx-x{opacity:1}
.mx-c3.tick{animation:mxtick .18s ease-out}
@keyframes mxtick{50%{scale:1.08}}
.mx-launch.listen .mx-c3 i{scale:1.1}
.mx-launch.talk .mx-c3 i{animation:mxs .5s ease-in-out infinite}
@keyframes mxs{50%{scale:1.22}}
.mx-launch.happy .mx-c3 i,.mx-launch.wake .mx-c3 i,.mx-launch.bow .mx-c3 i,.mx-launch.v-hop .mx-c3 i{animation:mxh .7s ease-out 1}
.mx-launch.poke .mx-c3 i{animation:mxs .3s ease-out 1}
.mx-launch.confused .mx-c3 i{animation:mxw .45s ease-in-out 3}
@keyframes mxw{25%{translate:-2px 0}75%{translate:2px 0}}
.mx-launch::after{content:''; position:absolute; inset:0; border-radius:inherit; background:linear-gradient(115deg, transparent 40%, rgba(255,255,255,.6) 50%, transparent 60%) no-repeat; background-size:300% 100%; background-position:120% 0; pointer-events:none}
.mx-launch.v-shine::after,.mx-launch.happy::after{animation:mxshine 1.1s ease-in-out 1}
@keyframes mxshine{from{background-position:120% 0}to{background-position:-20% 0}}
.mx-launch.sleep{filter:saturate(.45); opacity:.82; animation-duration:7s}
.mx-launch.sleep .mx-c3 i{scale:.85}
.mx-tip{position:absolute; right:80px; top:50%; translate:0 -50%; white-space:nowrap; background:#0f3d3a; color:#fff; font-size:13px; font-weight:500; padding:7px 11px; border-radius:10px;
  opacity:0; pointer-events:none; transition:opacity .15s}
.mx-launch:hover .mx-tip,.mx-launch:focus-visible .mx-tip{opacity:1}
.mx-launch.is-open .mx-tip{display:none}
.mx-alert-dot{position:absolute; top:-4px; right:-4px; width:14px; height:14px; border-radius:50%; background:#e2553f; border:2px solid #fff; display:none; animation:mxping 2.4s ease-in-out infinite}
@keyframes mxping{50%{box-shadow:0 0 0 5px rgba(226,85,63,.18)}}
.mx-root.has-alert .mx-alert-dot{display:block}
.mx-bubble .mx-c3{margin-top:6px}
@media (prefers-reduced-motion: reduce){ .mx-c3 i,.mx-launch{animation:none!important} }
@keyframes dot{0%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-6px);opacity:1}}
[x-cloak]{display:none!important}
.mx-root{position:fixed; right:max(12px, env(safe-area-inset-right, 0px)); bottom:max(6px, env(safe-area-inset-bottom, 0px)); z-index:100000; font-size:14px; line-height:1.45; color:#1f2a2a}
.mx-panel{position:absolute; right:0; bottom:84px; width:min(400px, calc(100vw - 24px)); height:min(620px, calc(100vh - 110px)); display:flex; flex-direction:column;
  background:#fff; border:1px solid #e6e3d6; border-radius:16px; box-shadow:0 18px 50px rgba(20,40,38,.18); overflow:hidden}
.mx-head{display:flex; align-items:center; gap:8px; padding:10px 10px 10px 14px; border-bottom:1px solid #eeebe0; position:relative}
.mx-head > .mx-c3{background:#FDCF3C; border-radius:9px 9px 9px 3px; padding:7px 6px; flex:none}
.mx-ttl{flex:1; min-width:0; display:grid; line-height:1.2}
.mx-live{display:inline-block; width:7px; height:7px; border-radius:50%; background:#3fb67a; margin-right:5px; vertical-align:1px}
.mx-hb{border:1px solid transparent; background:none; border-radius:8px; padding:5px 9px; font:inherit; font-size:13px; font-weight:500; color:#1f2a2a; cursor:pointer; display:grid; place-items:center}
.mx-hb:hover{background:#f3f2ec} .mx-hb.on{background:#e8f3f1; color:#1f6461}
.mx-hb-i{width:32px; height:32px; padding:0}
.mx-menu-w{position:relative}
.mx-menu{position:absolute; right:0; top:38px; z-index:2; width:250px; background:#fff; border:1px solid #e6e3d6; border-radius:12px; box-shadow:0 14px 34px rgba(20,40,38,.16); padding:6px; display:grid}
.mx-menu button,.mx-menu-rank{display:flex; justify-content:space-between; align-items:center; gap:10px; border:0; background:none; font:inherit; font-size:13px; color:#1f2a2a; padding:8px 10px; border-radius:8px; text-align:left; cursor:pointer}
.mx-menu button:hover{background:#f6f5ef}
.mx-menu em{font-style:normal; color:#6b7473; font-size:12px; text-align:right}
.mx-menu-rank{cursor:default; border-top:1px solid #eeebe0; border-radius:0; margin-top:4px; padding-top:10px}
.mx-head b{font-size:15px; font-weight:600}
.mx-head .mx-status{color:#6b7473; font-size:12px; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.mx-icon{border:1px solid #e6e3d6; background:#fff; border-radius:8px; padding:4px 8px; font-size:12px; color:#1f2a2a; cursor:pointer; white-space:nowrap}
.mx-icon:hover{background:#f6f5ef}
.mx-log{flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:9px; background:#fbfaf6}
.mx-empty{color:#6b7473; font-size:13px; text-align:center; padding:24px 10px}
.mx-hello{margin:auto 0; display:grid; gap:12px; justify-items:center; text-align:center; padding:10px 6px}
.mx-hello .mx-c3{background:#FDCF3C; border-radius:14px 14px 14px 4px; padding:12px 11px}
.mx-hello b{font-size:16px; font-weight:600; color:#1f2a2a}
.mx-hello p{margin:0; color:#6b7473; font-size:13px}
.mx-ex{display:grid; gap:6px; width:100%}
.mx-ex button{border:1px solid #e6e3d6; background:#fff; border-radius:10px; padding:8px 11px; font:inherit; font-size:13px; color:#1f2a2a; text-align:left; cursor:pointer}
.mx-ex button:hover{border-color:#1f6461; color:#1f6461}
.mx-msg{max-width:88%; padding:8px 11px; border-radius:14px; overflow-wrap:anywhere}
.mx-me{align-self:flex-end; background:#1f6461; color:#fff; border-bottom-right-radius:4px}
.mx-bot{align-self:flex-start; background:#fff; border:1px solid #eeebe0; border-bottom-left-radius:4px}
.mx-bot .moxi-link{color:#1f6461; font-weight:600; text-decoration:underline}
.mx-card{align-self:stretch; background:#fff; border:1px solid #e6e3d6; border-radius:12px; padding:10px 12px; display:grid; gap:6px}
.mx-card.audit{border-color:#FDCF3C; box-shadow:inset 3px 0 0 #FDCF3C}
.mx-card.request{border-style:dashed}
.mx-k{font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#1f6461}
.mx-card ul{margin:0; padding-left:16px; display:grid; gap:2px}
.mx-acts{display:flex; gap:6px; flex-wrap:wrap; align-items:center}
.mx-btn{border:1px solid #e6e3d6; background:#fff; border-radius:8px; padding:5px 11px; font-weight:600; font-size:13px; cursor:pointer; color:#1f2a2a}
.mx-btn.pri{background:#1f6461; border-color:#1f6461; color:#fff}
.mx-btn:disabled{opacity:.5; cursor:default}
.mx-tag{font-size:12px; color:#6b7473}
.mx-tag.ok{color:#1f6461} .mx-tag.bad{color:#b4412b}
.mx-dots{display:flex; gap:4px; padding:4px 2px} .mx-dots i{width:7px; height:7px; border-radius:50%; background:#1f6461; animation:dot 1s infinite}
.mx-dots i:nth-child(2){animation-delay:.15s} .mx-dots i:nth-child(3){animation-delay:.3s}
.mx-form{display:flex; gap:8px; padding:10px 12px; border-top:1px solid #eeebe0; background:#fff}
.mx-form textarea{flex:1; min-width:0; resize:none; font:inherit; padding:8px 10px; border-radius:10px; border:1px solid #e6e3d6; background:#fbfaf6; color:#1f2a2a; max-height:120px}
.mx-form textarea:focus{outline:none; border-color:#1f6461; background:#fff; box-shadow:0 0 0 3px rgba(31,100,97,.12)}
@media (max-width:480px){ .mx-panel{position:fixed; left:8px; right:8px; top:max(8px, env(safe-area-inset-top, 0px)); bottom:92px; width:auto; height:auto} }
.mx-bubble{display:flex; gap:9px; align-items:flex-start}
.mx-bubble-t{display:block}
.mx-rank{font-size:11px; font-weight:700; color:#1f6461; background:#e8f3f1; border-radius:20px; padding:2px 8px; white-space:nowrap}
/* konuşma balonu */
.mx-bubble{position:absolute; right:0; bottom:76px; width:max-content; max-width:min(260px, calc(100vw - 90px)); background:#fff; color:#1f2a2a; border:2px solid #1f6461;
  border-radius:14px 14px 4px 14px; padding:9px 12px; font-size:13px; font-weight:500; box-shadow:0 8px 24px rgba(20,40,38,.18); cursor:pointer; text-align:left}
.mx-bubble.tip{border-color:#FDCF3C; box-shadow:inset 3px 0 0 #FDCF3C, 0 8px 24px rgba(20,40,38,.18)}
.mx-bubble.milestone{border-color:#c8452c}
.mx-bubble small{display:block; font-weight:500; color:#1f6461; margin-top:2px}
/* defter */
.mx-book{flex:1; overflow-y:auto; padding:12px; background:#fbfaf6; display:grid; gap:8px; align-content:start}
.mx-book li{display:flex; gap:8px; align-items:flex-start; background:#fff; border:1px solid #eeebe0; border-radius:10px; padding:7px 10px; list-style:none}
.mx-book ul{margin:0; padding:0; display:grid; gap:6px}
.mx-book li span{flex:1}
.mx-book li em{font-style:normal; font-size:11px; font-weight:700; color:#1f6461; text-transform:uppercase; letter-spacing:.04em}
.mx-book li button{border:0; background:none; color:#b4412b; cursor:pointer; font-size:13px}

    </style>

    <section class="mx-panel" x-show="open" x-cloak x-transition.origin.bottom.right role="dialog" aria-label="Moxi">
        <header class="mx-head">
            @include('operator.partials.moxi-cone', ['class' => 'sm'])
            <div class="mx-ttl"><b>Moxi</b><span class="mx-status"><i class="mx-live"></i><span x-text="status">{{ $busy ? 'düşünüyor…' : 'hazır' }}</span></span></div>
            <button type="button" class="mx-hb" :class="book ? 'on' : ''" @click="book = !book; menu = false" :aria-pressed="book" title="Moxi'nin defteri: senden öğrendikleri">Defter</button>
            <div class="mx-menu-w" @click.outside="menu = false">
                <button type="button" class="mx-hb mx-hb-i" @click="menu = !menu" :aria-expanded="menu" aria-label="Ayarlar" title="Ayarlar"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></button>
                <div class="mx-menu" x-show="menu" x-cloak x-transition.origin.top.right>
                    <button type="button" @click="cycleVoice()"><span>Ses</span><em x-text="voiceLabel()"></em></button>
                    <button type="button" @click="toggleBubbles()"><span>İpucu balonları</span><em x-text="bubbles ? 'Açık' : 'Kapalı'"></em></button>
                    <button type="button" wire:click="clear" @click="menu = false"><span>Ekranı temizle</span><em>Moxi yine hatırlar</em></button>
                    <div class="mx-menu-rank"><span>Rütbe</span><em>{{ $rank['name'] ?? 'Çırak' }} · {{ $rank['points'] ?? 0 }} puan{{ ($rank['next'] ?? null) !== null ? ' · '.$rank['next_name'].' için '.$rank['next'] : '' }}</em></div>
                </div>
            </div>
            <button type="button" class="mx-hb mx-hb-i" @click="toggle()" aria-label="Kapat" title="Kapat"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
        </header>

        <div class="mx-book" x-show="book" x-cloak>
            <div class="mx-tag">Moxi'nin senden öğrendikleri. Her cevapta bunlara uyar; yanlışsa ✕ ile unuttur.</div>
            @if ($notebook === [])
                <div class="mx-empty">Defter boş. "Bundan sonra fiyatları +KDV yaz" gibi şeyler söyledikçe buraya not eder.</div>
            @else
                <ul>
                    @foreach ($notebook as $note)
                        <li wire:key="mx-note-{{ $note['id'] }}"><span><em>{{ ['bilgi' => 'Bilgi', 'tercih' => 'Tercih', 'ders' => 'Ders'][$note['kind']] ?? 'Bilgi' }}</em><br>{{ $note['text'] }}</span>
                            <button type="button" wire:click="forget({{ $note['id'] }})" title="Unut" aria-label="Unut">✕</button></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="mx-log" x-ref="log" x-show="!book">
            @forelse ($rows as $row)
                @switch($row->role)
                    @case('user')
                        <div class="mx-msg mx-me" wire:key="mx-{{ $row->id }}">{{ $row->body }}</div>
                        @break
                    @case('moxi')
                        <div class="mx-msg mx-bot" wire:key="mx-{{ $row->id }}">{!! \App\Livewire\Operator\Moxi::html((string) $row->body) !!}</div>
                        @break
                    @case('card')
                        <div class="mx-card" wire:key="mx-{{ $row->id }}">
                            <span class="mx-k">{{ $row->body }}</span>
                            <ul>@foreach ((array) data_get($row->meta, 'lines', []) as $line)<li>{{ $line }}</li>@endforeach</ul>
                            <div class="mx-acts">
                                @if ($row->status === 'pending')
                                    <button type="button" class="mx-btn pri" wire:click="confirm({{ $row->id }})" wire:loading.attr="disabled">Yap</button>
                                    <button type="button" class="mx-btn" wire:click="cancel({{ $row->id }})">Vazgeç</button>
                                @elseif ($row->status === 'done')
                                    <span class="mx-tag ok">✓ {{ data_get($row->meta, 'result', 'Yapıldı') }}</span>
                                    @if (data_get($row->meta, 'url'))<a class="moxi-link mx-tag ok" href="{{ data_get($row->meta, 'url') }}" wire:navigate>Aç</a>@endif
                                @elseif ($row->status === 'failed')
                                    <span class="mx-tag bad">Yapılamadı: {{ data_get($row->meta, 'result') }}</span>
                                @else
                                    <span class="mx-tag">Vazgeçildi</span>
                                @endif
                            </div>
                        </div>
                        @break
                    @case('request')
                        <div class="mx-card request" wire:key="mx-{{ $row->id }}">
                            <span class="mx-k">Claude'a iş</span>
                            <div><strong>{{ $row->body }}</strong></div>
                            @if ($row->status === 'done')
                                <div class="mx-msg mx-bot" style="max-width:100%">{!! \App\Livewire\Operator\Moxi::html((string) data_get($row->meta, 'answer', '')) !!}</div>
                            @else
                                <span class="mx-tag">Claude'un sıradaki turunda yapılacak (08:27, 12:27, 16:27, 21:27).</span>
                            @endif
                        </div>
                        @break
                    @case('audit')
                        <div class="mx-card audit" wire:key="mx-{{ $row->id }}">
                            <span class="mx-k">Moxi'nin önerisi</span>
                            <div><strong>{{ $row->body }}</strong></div>
                            <div class="mx-tag">{{ \Illuminate\Support\Str::limit((string) data_get($row->meta, 'detail'), 280) }}</div>
                            <div class="mx-acts">
                                @if ($row->status === 'pending')
                                    <button type="button" class="mx-btn pri" wire:click="decide({{ $row->id }}, true)">Onayla</button>
                                    <button type="button" class="mx-btn" wire:click="decide({{ $row->id }}, false)">Sonra</button>
                                @elseif ($row->status === 'done')
                                    <span class="mx-tag ok">✓ Geliştirme havuzunda</span>
                                @else
                                    <span class="mx-tag">Havuzda bekliyor</span>
                                @endif
                                <a class="moxi-link mx-tag ok" href="{{ route('operator.settings.improvements') }}" wire:navigate>Havuz</a>
                            </div>
                        </div>
                        @break
                @endswitch
            @empty
                <div class="mx-hello">
                    @include('operator.partials.moxi-cone')
                    <b>Merhaba, ben Moxi.</b>
                    <p>Marka durumu sor, link iste ya da iş ver. Panelde bir şey değişecekse önce sana kart gösteririm.</p>
                    <div class="mx-ex">
                        @foreach (['Bugün beni bekleyen ne var?', 'Gelen kutusu linki', 'Satış\'ta gecikmiş adaylar kimler?'] as $example)
                            <button type="button" @click="$wire.set('text', @js($example)).then(() => submit())">{{ $example }}</button>
                        @endforeach
                    </div>
                </div>
            @endforelse
            @if ($busy)
                <div class="mx-msg mx-bot mx-typing" aria-label="Moxi yazıyor">@include('operator.partials.moxi-cone', ['class' => 'sm is-typing'])</div>
            @endif
        </div>

        <form class="mx-form" @submit.prevent="submit()">
            <textarea rows="1" wire:model="text" x-ref="input" placeholder="Moxi'ye yaz…" aria-label="Moxi'ye mesaj"
                @input="typing()" @keydown.enter.prevent="if (! $event.shiftKey) $el.form.requestSubmit()" @if ($busy) disabled @endif></textarea>
            <button type="submit" class="mx-btn pri" @if ($busy) disabled @endif>Gönder</button>
        </form>
    </section>

    <div wire:ignore>
        <button type="button" class="mx-bubble" x-show="bubbleText && !open" x-cloak x-transition.origin.bottom.right :class="bubbleKind" @click="bubbleClick()">
            @include('operator.partials.moxi-cone', ['class' => 'sm'])<span class="mx-bubble-t"><span x-text="bubbleText"></span><small x-show="bubbleUrl">Göster →</small></span>
        </button>
    </div>

    <button type="button" class="mx-mascot mx-launch" :class="[open ? 'is-open' : '', mood]" wire:ignore @click="tap()" :aria-label="open ? 'Moxi\'yi kapat' : 'Moxi\'ye sor'" aria-label="Moxi'ye sor">
        @include('operator.partials.moxi-cone')
        <span class="mx-x" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></span>
        <span class="mx-tip">Moxi'ye sor</span>
        <span class="mx-alert-dot"></span>
    </button>
</div>
