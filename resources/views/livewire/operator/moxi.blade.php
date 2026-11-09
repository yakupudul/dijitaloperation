{{-- Moxi, the corner assistant (yakup 2026-11-09). Mascot moves, sounds and speech: operator/partials/moxi-script. --}}
<div class="mx-root @if ($alert) has-alert @endif" x-data="moxiChat()" x-init="boot()"
    @moxi-think.window="think()" @moxi-said.window="said($event.detail)" @moxi-happy.window="happy($event.detail)"
    data-rank="{{ $rank['level'] ?? 1 }}" data-rank-name="{{ $rank['name'] ?? 'Çırak' }}" data-cards="{{ $doneCards }}"
    wire:poll.{{ $busy ? '2s' : ($waitingJobs ? '30s' : '90s') }}="refresh" data-moxi>
    <style>
/* ---------- Moxi (SVG, yağlı boya filtresi) ---------- */
.moxi{--s:1; position:relative; width:calc(200px*var(--s)); height:calc(240px*var(--s)); flex:none}
.moxi svg{width:100%; height:100%; display:block; overflow:visible}
.moxi svg *{transform-box:view-box}
.m-shadow{transform-origin:100px 226px; animation:shadow 3.2s ease-in-out infinite}
.m-all{transform-origin:100px 224px; animation:breathe 3.2s ease-in-out infinite}
.m-head{transform-origin:100px 156px; transition:transform .35s ease}
.m-hat{transform-origin:100px 80px; transition:transform .35s ease; animation:hatsway 6s ease-in-out infinite}
.m-tails{transform-origin:142px 97px; animation:flutter 2.6s ease-in-out infinite}
.m-eyes{transition:transform .25s ease}
.m-eo{transform-origin:100px 118px; animation:blink 4.6s infinite}
.m-eh,.m-es,.m-mouth .mo,.m-mouth .mr,.m-mouth .mw,.m-mouth .mz,.m-zzz{display:none}
.m-mouth.open .mc,.m-mouth.round .mc,.m-mouth.wide .mc{display:none}
.m-mouth.open .mo,.m-mouth.round .mr,.m-mouth.wide .mw{display:inline}
.m-eyes{transform:translate(var(--ex,0px),var(--ey,0px))}
.m-sweat,.m-bang,.m-slash{opacity:0}
.m-slash{stroke-dasharray:140; stroke-dashoffset:140}
.m-glint{stroke-dasharray:5 40; stroke-dashoffset:45; opacity:0}

.m-arm-r{transform-origin:136px 160px; transition:transform .35s ease}
.m-scroll{opacity:0; transform-origin:150px 170px; transform:scale(.3) rotate(-30deg); transition:.35s}
.m-brow-l{transform-origin:83px 106px; transition:transform .3s} .m-brow-r{transform-origin:117px 106px; transition:transform .3s}
.m-alert{transform-origin:150px 62px; transform:scale(0); transition:.3s cubic-bezier(.5,1.8,.5,1)}
.m-fx,.m-dots{opacity:0; transition:opacity .25s}
.m-dots circle{animation:dot 1s infinite; transform-origin:center}
.m-dots circle:nth-child(2){animation-delay:.15s} .m-dots circle:nth-child(3){animation-delay:.3s}

/* halleri */
.moxi.listen .m-head{transform:rotate(-6deg) translateX(-2px)}
.moxi.listen .m-hat{transform:rotate(-6deg)}
.moxi.listen .m-eyes{transform:translate(var(--lx,0px), 4px)}
.moxi.listen .m-all{animation-duration:2.2s}
.moxi.nod .m-head{transform:rotate(-6deg) translateY(3px)}
.moxi.think .m-eyes{transform:translate(5px,-5px)}
.moxi.think .m-hat{animation:thinkhat 1.6s ease-in-out infinite}
.moxi.think .m-dots{opacity:1}
.moxi.think .m-brow-l{transform:translateY(-3px) rotate(-6deg)}
.moxi.talk .m-all{animation:talkbob .5s ease-in-out infinite}
.moxi.talk .m-arm-r{transform:rotate(-35deg)}
.moxi.happy .m-all{animation:hop .7s ease-out 2}
.moxi.happy .m-eo,.moxi.sleep .m-eo{display:none}
.moxi.happy .m-eh{display:inline}
.moxi.happy .m-fx{opacity:1}
.moxi.audit .m-scroll{opacity:1; transform:none}
.moxi.audit .m-arm-r{transform:rotate(-55deg)}
.moxi.audit .m-brow-r{transform:translateY(-5px) rotate(14deg)}
.moxi.audit .m-alert{transform:scale(1)}
.moxi.audit .m-eyes{transform:translateX(4px)}
.moxi.sleep .m-es,.moxi.sleep .m-zzz{display:inline}
.moxi.sleep .m-all{animation-duration:5s}
.moxi.sleep .m-hat{transform:rotate(7deg) translateY(7px)}
.moxi.sleep .m-tails{animation-duration:4s}

/* yeni hareketler */
.moxi.enter svg{animation:reveal 1.2s cubic-bezier(.2,.7,.2,1)}
.moxi.bow .m-head,.moxi.bow .m-hat{animation:bow 1.4s ease-in-out}
.moxi.bow .m-all{animation:bowbody 1.4s ease-in-out}
.moxi.bow .m-eo{display:none} .moxi.bow .m-eh{display:inline}
.moxi.happy .m-slash{animation:slash .7s .15s ease-out}
.moxi.happy .m-glint,.moxi.v-sword .m-glint{animation:glint .9s ease-in-out}
.moxi.happy .m-head{animation:proud 1.4s ease-in-out}
.moxi.think .m-arm-r{transform:rotate(100deg)}
.moxi.think .m-mouth .mc{display:none} .moxi.think .m-mouth .mz{display:inline}
.moxi.confused .m-head{transform:rotate(9deg)}
.moxi.confused .m-hat{transform:rotate(9deg) translate(4px,1px)}
.moxi.confused .m-arm-r{animation:scratch .35s ease-in-out infinite alternate}
.moxi.confused .m-sweat{animation:sweat 1.6s ease-in infinite}
.moxi.confused .m-brow-l{transform:translateY(2px) rotate(8deg)} .moxi.confused .m-brow-r{transform:translateY(-4px) rotate(10deg)}
.moxi.confused .m-mouth .mc{display:none} .moxi.confused .m-mouth .mz{display:inline}
.moxi.poke .m-all{animation:poke .5s ease-out}
.moxi.poke .m-bang,.moxi.wake .m-bang{animation:bang .8s ease-out}
.moxi.poke .m-eo{transform:scale(1.18)}
.moxi.wake .m-all{animation:hop .55s ease-out}
.moxi.wake .m-brow-l,.moxi.wake .m-brow-r{transform:translateY(-5px)}
.moxi.v-look .m-eyes{animation:look 2.6s ease-in-out}
.moxi.v-look .m-head{animation:lookhead 2.6s ease-in-out}
.moxi.v-hat .m-arm-r{animation:armhat 1.8s ease-in-out}
.moxi.v-hat .m-hat{animation:hatfix 1.8s ease-in-out}
.moxi.v-yawn .m-eo{display:none} .moxi.v-yawn .m-es{display:inline}
.moxi.v-yawn .m-mouth .mc{display:none} .moxi.v-yawn .m-mouth .mr{display:inline; animation:yawn 2s ease-in-out; transform-origin:101px 136px}
.moxi.v-yawn .m-all{animation:stretch 2s ease-in-out}
@keyframes reveal{0%{clip-path:circle(0% at 50% 55%); filter:blur(8px) saturate(0)}60%{filter:blur(1px) saturate(.6)}100%{clip-path:circle(85% at 50% 55%); filter:none}}
@keyframes bow{0%,100%{transform:none}30%,62%{transform:translateY(10px)}}
@keyframes bowbody{0%,100%{transform:none}30%,62%{transform:scale(1.03,.95)}}
@keyframes slash{0%{opacity:1; stroke-dashoffset:140}60%{opacity:1; stroke-dashoffset:0}100%{opacity:0; stroke-dashoffset:-140}}
@keyframes glint{0%{opacity:0; stroke-dashoffset:45}20%{opacity:1}100%{opacity:0; stroke-dashoffset:-5}}
@keyframes proud{0%,100%{transform:none}40%,70%{transform:rotate(-4deg) translateY(-2px)}}
@keyframes scratch{from{transform:rotate(-165deg)}to{transform:rotate(-178deg)}}
@keyframes sweat{0%{opacity:0; transform:translateY(-4px)}25%{opacity:1}100%{opacity:0; transform:translateY(14px)}}
@keyframes poke{0%{transform:none}25%{transform:scale(1.1,.86)}55%{transform:scale(.94,1.08) translateY(-6px)}100%{transform:none}}
@keyframes bang{0%{opacity:0; transform:translateY(6px) scale(.5)}30%{opacity:1; transform:none}100%{opacity:0}}
@keyframes look{0%,100%{transform:none}25%,40%{transform:translateX(-5px)}60%,78%{transform:translateX(5px)}}
@keyframes lookhead{0%,100%{transform:none}25%,40%{transform:rotate(-5deg)}60%,78%{transform:rotate(5deg)}}
@keyframes armhat{0%,100%{transform:none}35%,70%{transform:rotate(-160deg)}}
@keyframes hatfix{0%,100%{transform:none}40%{transform:translateY(-6px) rotate(-5deg)}55%{transform:translateY(-6px) rotate(4deg)}75%{transform:none}}
@keyframes yawn{0%,100%{transform:none}30%,70%{transform:scale(1.5,1.8)}}
@keyframes stretch{0%,100%{transform:none}35%,65%{transform:scale(.97,1.05)}}
@keyframes flutter{0%,100%{transform:rotate(0)}50%{transform:rotate(4deg) skewY(-2deg)}}
@keyframes thinkhat{0%,100%{transform:rotate(-3deg)}50%{transform:rotate(4deg)}}
@keyframes zz{0%{opacity:0; transform:translate(0,0)}30%{opacity:1}100%{opacity:0; transform:translate(10px,-18px)}}
.m-zzz text{animation:zz 2.4s infinite; font:700 16px "Baloo 2", system-ui, sans-serif; fill:#1f6461}
.m-zzz text:nth-child(2){animation-delay:1.2s}

@keyframes breathe{50%{transform:scale(1.012,.988)}}
@keyframes shadow{50%{transform:scaleX(1.06)}}
@keyframes blink{0%,92%,100%{transform:scaleY(1)}95%{transform:scaleY(.1)}}
@keyframes hatsway{0%,100%{rotate:0deg}50%{rotate:1.5deg}}
@keyframes spin{to{--a:360deg}}
@keyframes dot{0%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-6px);opacity:1}}
@keyframes talkbob{50%{transform:translateY(-1.5px)}}
@keyframes hop{30%{transform:translateY(-7px)}60%{transform:translateY(0)}}
@media (prefers-reduced-motion: reduce){ .moxi *{animation:none!important; transition:none!important} }


[x-cloak]{display:none!important}
.mx-root{position:fixed; right:max(12px, env(safe-area-inset-right, 0px)); bottom:max(6px, env(safe-area-inset-bottom, 0px)); z-index:9000; font-size:14px; line-height:1.45; color:#1f2a2a}
.mx-mascot{display:block; width:84px; height:101px; padding:0; margin:0 0 0 auto; border:0; background:none; cursor:pointer; position:relative}
.mx-mascot .moxi{--s:.42}
.mx-mascot:focus-visible{outline:3px solid #FDCF3C; outline-offset:2px; border-radius:14px}
.mx-root.has-alert .m-alert{transform:scale(1)}
.mx-panel{position:absolute; right:0; bottom:104px; width:min(390px, calc(100vw - 24px)); height:min(600px, calc(100vh - 140px)); display:flex; flex-direction:column;
  background:#fff; border:1px solid #e6e3d6; border-radius:16px; box-shadow:0 18px 50px rgba(20,40,38,.18); overflow:hidden}
.mx-head{display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #eeebe0}
.mx-head b{font-size:16px; font-weight:700}
.mx-head .mx-status{color:#6b7473; font-size:12px; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
.mx-icon{border:1px solid #e6e3d6; background:#fff; border-radius:8px; padding:4px 8px; font-size:12px; color:#1f2a2a; cursor:pointer; white-space:nowrap}
.mx-icon:hover{background:#f6f5ef}
.mx-log{flex:1; overflow-y:auto; padding:12px; display:flex; flex-direction:column; gap:9px; background:#fbfaf6}
.mx-empty{color:#6b7473; font-size:13px; text-align:center; padding:24px 10px}
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
.mx-form textarea:focus{outline:2px solid #FDCF3C; outline-offset:1px}
@media (max-width:480px){ .mx-panel{height:calc(100vh - 130px)} }
/* rütbe: Samuray şapka şeridi, Usta omuz arması, Şogun altın boynuzlar */
.m-r2,.m-r3,.m-r4{display:none}
.moxi.rank-2 .m-r2,.moxi.rank-3 .m-r2,.moxi.rank-4 .m-r2,.moxi.rank-3 .m-r3,.moxi.rank-4 .m-r3,.moxi.rank-4 .m-r4{display:inline}
.mx-rank{font-size:11px; font-weight:700; color:#1f6461; background:#e8f3f1; border-radius:20px; padding:2px 8px; white-space:nowrap}
/* konuşma balonu */
.mx-bubble{position:absolute; right:58px; bottom:96px; width:max-content; max-width:min(260px, calc(100vw - 90px)); background:#fff; color:#1f2a2a; border:2px solid #1f6461;
  border-radius:16px 16px 4px 16px; padding:8px 12px; font-size:13px; font-weight:600; box-shadow:0 8px 24px rgba(20,40,38,.18); cursor:pointer; text-align:left}
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
            <b>Moxi</b>
            <span class="mx-rank" title="{{ $rank ? $rank['points'].' puan'.($rank['next'] !== null ? ' · '.$rank['next_name'].' için '.$rank['next'] : '') : '' }}">{{ $rank['name'] ?? 'Çırak' }}</span>
            <span class="mx-status" x-text="status">{{ $busy ? 'düşünüyor…' : 'hazır' }}</span>
            <button type="button" class="mx-icon" @click="book = !book" :aria-pressed="book" title="Moxi'nin defteri: senden öğrendikleri">Defter</button>
            <button type="button" class="mx-icon" @click="toggleBubbles()" x-text="bubbles ? '💬 Balon' : '💬 Kapalı'" title="Ara sıra konuşma balonları"></button>
            <button type="button" class="mx-icon" @click="cycleVoice()" x-text="voiceLabel()" title="Ses: konuşma, mırıltı ya da sessiz"></button>
            <button type="button" class="mx-icon" wire:click="clear" title="Ekranı temizle (Moxi yine hatırlar)">Temizle</button>
            <button type="button" class="mx-icon" @click="toggle()" aria-label="Kapat">✕</button>
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
                <div class="mx-empty">Selam! Marka durumu sorabilir, link isteyebilir ya da iş verebilirsin.<br>Örnek: "Gelen kutusu linki", "Portakal Diş'in durumu ne?", "Ayşe Hanım'ı müşteri yap, 14.000, 6 ay".</div>
            @endforelse
            @if ($busy)
                <div class="mx-msg mx-bot"><span class="mx-dots"><i></i><i></i><i></i></span></div>
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
            <span x-text="bubbleText"></span><small x-show="bubbleUrl">Göster →</small>
        </button>
    </div>

    <button type="button" class="mx-mascot" wire:ignore @click="tap()" :aria-label="open ? 'Moxi\'yi kapat' : 'Moxi\'yi aç'" aria-label="Moxi">
        <div class="moxi" :class="[mood, 'rank-' + rank]">
            @include('operator.partials.moxi-mascot')
        </div>
    </button>
</div>
