{{-- Moxi (panel asistanı), fütüristik sürüm (yakup 2026-11-09): yalnız kafa ve şapka, ağız yok. İnci beyazı bir kabuk,
     koyu cam vizör, vizörde ışıklı gözler (duygu yalnız gözlerden), kulaklarda konuşurken yanan ışık halkaları; şapka
     Moximu ikonu. Sınıf adları (m-head, m-eyes, m-mouth…) Moxi'nin hareketleri için; değiştirme. m-mouth artık ağız değil,
     kulak ışıklarıdır. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<radialGradient id="moxi-gShell" cx=".38" cy=".3" r=".8"><stop offset="0" stop-color="#ffffff"/><stop offset=".6" stop-color="#e9eeed"/><stop offset="1" stop-color="#b9c6c4"/></radialGradient>
<linearGradient id="moxi-gVisor" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1f3335"/><stop offset="1" stop-color="#0c1516"/></linearGradient>
<linearGradient id="moxi-gHat" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#174744"/><stop offset=".45" stop-color="#1f6461"/><stop offset=".55" stop-color="#2d7f7a"/><stop offset="1" stop-color="#174744"/></linearGradient>
<linearGradient id="moxi-gGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe08a"/><stop offset="1" stop-color="#e0ab22"/></linearGradient>
<filter id="moxi-glow" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="2.4" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
</defs></svg>
<svg viewBox="0 0 200 240" aria-hidden="true">
<ellipse class="m-shadow" cx="100" cy="212" rx="34" ry="5" fill="#1b2a28" opacity=".14"/>
<g class="m-all">
 <g class="m-tails"></g>
 <g class="m-arm-r"></g>
 <g class="m-head">
  {{-- kulaklar: konuşurken ışıkları yanar --}}
  <circle cx="44" cy="142" r="12" fill="url(#moxi-gShell)" stroke="#c9d3d1" stroke-width="1"/>
  <circle cx="156" cy="142" r="12" fill="url(#moxi-gShell)" stroke="#c9d3d1" stroke-width="1"/>
  <g class="m-mouth" filter="url(#moxi-glow)">
   <g class="mc" fill="none" stroke="#5fd3c4" stroke-width="2.4" opacity=".45"><circle cx="44" cy="142" r="6"/><circle cx="156" cy="142" r="6"/></g>
   <g class="mo" fill="#7fe8da" stroke="#5fd3c4" stroke-width="2.4"><circle cx="44" cy="142" r="6"/><circle cx="156" cy="142" r="6"/></g>
   <g class="mr" fill="none" stroke="#9ff2e6" stroke-width="3.2"><circle cx="44" cy="142" r="6.5"/><circle cx="156" cy="142" r="6.5"/></g>
   <g class="mw" fill="#5fd3c4" opacity=".75"><circle cx="44" cy="142" r="7"/><circle cx="156" cy="142" r="7"/></g>
   <g class="mz" fill="none" stroke="#FDCF3C" stroke-width="2.4"><circle cx="44" cy="142" r="6"/><circle cx="156" cy="142" r="6"/></g>
  </g>
  <g class="m-r3" fill="#FDCF3C"><circle cx="44" cy="128" r="1.8"/><circle cx="156" cy="128" r="1.8"/></g>
  {{-- kabuk ve vizör --}}
  <ellipse cx="100" cy="142" rx="56" ry="52" fill="url(#moxi-gShell)" stroke="#c9d3d1" stroke-width="1"/>
  <rect x="58" y="116" width="84" height="48" rx="23" fill="url(#moxi-gVisor)"/>
  <path d="M68 122 Q84 117 104 118" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" opacity=".18" fill="none"/>
  <path class="m-glint" d="M64 158 L94 120" stroke="#ffffff" stroke-width="5" stroke-linecap="round" opacity=".5"/>
  <ellipse cx="100" cy="162" rx="30" ry="3" fill="#5fd3c4" opacity=".16"/>
  {{-- gözler: tek ifade yeri --}}
  <path class="m-brow-l" d="M78 128 Q85 125 92 128" stroke="#5fd3c4" stroke-width="1.8" stroke-linecap="round" fill="none" opacity=".55"/>
  <path class="m-brow-r" d="M108 128 Q115 125 122 128" stroke="#5fd3c4" stroke-width="1.8" stroke-linecap="round" fill="none" opacity=".55"/>
  <g class="m-eyes" filter="url(#moxi-glow)">
   <g class="m-eo" fill="#7fe8da"><rect x="78" y="131" width="13" height="16" rx="6.5"/><rect x="109" y="131" width="13" height="16" rx="6.5"/>
    <circle cx="87.5" cy="135.5" r="1.6" fill="#ffffff"/><circle cx="118.5" cy="135.5" r="1.6" fill="#ffffff"/></g>
   <g class="m-eh" fill="none" stroke="#7fe8da" stroke-width="3.4" stroke-linecap="round"><path d="M78 142 Q84.5 133 91 142"/><path d="M109 142 Q115.5 133 122 142"/></g>
   <g class="m-es" fill="none" stroke="#7fe8da" stroke-width="3" stroke-linecap="round"><path d="M78 140 H91"/><path d="M109 140 H122"/></g>
  </g>
  <ellipse cx="74" cy="154" rx="6" ry="2.6" fill="#f08a7a" opacity=".22"/><ellipse cx="126" cy="154" rx="6" ry="2.6" fill="#f08a7a" opacity=".22"/>
  <path class="m-sweat" d="M146 112 q5 8 0 11 q-5 -3 0 -11z" fill="#e6f6f3" stroke="#1f6461" stroke-width="1"/>
 </g>
 {{-- şapka: Moximu ikonu --}}
 <g class="m-hat">
  <ellipse cx="100" cy="96" rx="70" ry="11" fill="#0f2f2d"/>
  <path d="M30 94 L100 52 L170 94 Q100 108 30 94Z" fill="url(#moxi-gHat)"/>
  <path d="M30 94 Q100 108 170 94" stroke="#5fd3c4" stroke-width="1.6" fill="none" filter="url(#moxi-glow)" opacity=".85"/>
  <path d="M70 66 L52 80" stroke="#ffffff" stroke-width="3.5" stroke-linecap="round" opacity=".18"/>
  <path class="m-r2" d="M42 88 Q100 74 158 88" stroke="url(#moxi-gGold)" stroke-width="2" fill="none" stroke-linecap="round"/>
  <path class="m-r4" d="M96 70 Q90 56 80 55 Q90 62 98 74Z M104 70 Q110 56 120 55 Q110 62 102 74Z" fill="url(#moxi-gGold)"/>
  <g transform="translate(100 80)"><rect x="-7" y="-7" width="14" height="14" rx="4" fill="url(#moxi-gGold)"/><circle cx="-2.6" cy="-2.6" r="2.2" fill="#1f6461"/><circle cx="2.6" cy="-2.6" r="2.2" fill="#1f6461"/><circle cx="-2.6" cy="2.6" r="2.2" fill="#1f6461"/><circle cx="2.6" cy="2.6" r="2.2" fill="#1f6461"/></g>
  <circle cx="100" cy="53" r="3.4" fill="#7fe8da" filter="url(#moxi-glow)"/>
  <circle class="m-alert" cx="150" cy="72" r="5.5" fill="#e2553f" stroke="#ffffff" stroke-width="2"/>
 </g>
 {{-- öneri: vizörün yanında ışıktan bir kart --}}
 <g class="m-scroll" filter="url(#moxi-glow)"><rect x="150" y="160" width="34" height="24" rx="4" fill="#5fd3c4" opacity=".22" stroke="#7fe8da" stroke-width="1.4"/>
  <path d="M156 178 L163 172 L168 175 L177 166" stroke="#7fe8da" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/><circle cx="177" cy="166" r="2" fill="#FDCF3C"/></g>
</g>
<path class="m-fx" d="M168 40 l2.4 7 7 2.4 -7 2.4 -2.4 7 -2.4 -7 -7 -2.4 7 -2.4z" fill="#FDCF3C"/>
<g class="m-dots" fill="#1f6461"><circle cx="160" cy="36" r="3.5"/><circle cx="171" cy="36" r="3.5"/><circle cx="182" cy="36" r="3.5"/></g>
<path class="m-slash" d="M34 80 Q110 26 178 92" stroke="#7fe8da" stroke-width="2.6" fill="none" stroke-linecap="round" opacity=".8"/>
<text class="m-bang" x="164" y="66" style="font:700 26px var(--display); fill:#e2553f">!</text>
<g class="m-zzz"><text x="158" y="52">z</text><text x="168" y="42">z</text></g>
</svg>
