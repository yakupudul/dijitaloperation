{{-- Moxi (panel asistanı): ilk yağlı boya samuray, yalnız kafa ve şapka (yakup 2026-11-09 "ilk yaptığın en iyisiydi, sadece
     kafa kalsa"). Gövde yok; kafa büyütülüp ortalandı. Sınıf adları hareketler için; değiştirme. Dönüşüm kökenleri iç
     grubun (çizimin kendi) koordinatlarındadır. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<filter id="moxi-oil" x="-15%" y="-15%" width="130%" height="130%" color-interpolation-filters="sRGB">
 <feTurbulence type="fractalNoise" baseFrequency="0.045" numOctaves="2" seed="4" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="4.5" xChannelSelector="R" yChannelSelector="G" result="d"/>
 <feTurbulence type="fractalNoise" baseFrequency="0.05 0.16" numOctaves="3" seed="11" result="t"/>
 <feDiffuseLighting in="t" surfaceScale="1.2" lighting-color="#ffffff" result="l"><feDistantLight azimuth="235" elevation="58"/></feDiffuseLighting>
 <feComponentTransfer in="l" result="l2"><feFuncR type="linear" slope="1.12" intercept=".02"/><feFuncG type="linear" slope="1.12" intercept=".02"/><feFuncB type="linear" slope="1.12" intercept=".02"/></feComponentTransfer>
 <feBlend in="d" in2="l2" mode="multiply" result="m"/>
 <feComposite in="m" in2="d" operator="in"/>
</filter>
<filter id="moxi-oilsoft" x="-20%" y="-20%" width="140%" height="140%">
 <feTurbulence type="fractalNoise" baseFrequency="0.03" numOctaves="2" seed="7" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="14" xChannelSelector="R" yChannelSelector="G"/>
</filter>
<radialGradient id="moxi-gSkin" cx=".38" cy=".32" r=".75"><stop offset="0" stop-color="#ffe2bd"/><stop offset=".55" stop-color="#efbb8c"/><stop offset="1" stop-color="#c47a4e"/></radialGradient>
<linearGradient id="moxi-gTeal" x1="0" y1="0" x2=".3" y2="1"><stop offset="0" stop-color="#3a9a92"/><stop offset=".55" stop-color="#1f6461"/><stop offset="1" stop-color="#123b39"/></linearGradient>
<linearGradient id="moxi-gHat" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#0f3533"/><stop offset=".28" stop-color="#25756f"/><stop offset=".45" stop-color="#8fd3c6"/><stop offset=".56" stop-color="#2e8a85"/><stop offset=".8" stop-color="#1a5552"/><stop offset="1" stop-color="#0c2c2a"/></linearGradient>
<linearGradient id="moxi-gGold" x1="0" y1="0" x2=".4" y2="1"><stop offset="0" stop-color="#ffe7a0"/><stop offset=".45" stop-color="#f4bf33"/><stop offset="1" stop-color="#a8681a"/></linearGradient>
<linearGradient id="moxi-gHak" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#4a3c36"/><stop offset="1" stop-color="#211a18"/></linearGradient>
<linearGradient id="moxi-gRed" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e2633f"/><stop offset="1" stop-color="#9c2f1f"/></linearGradient>
</defs></svg>
<svg viewBox="0 0 200 240" aria-hidden="true">
<ellipse class="m-shadow" cx="100" cy="206" rx="44" ry="6" fill="#1b2a28" opacity=".2"/>
<g transform="translate(-17 8) scale(1.15)">
<circle cx="100" cy="100" r="74" fill="#FDCF3C" opacity=".42" filter="url(#moxi-oilsoft)"/>
<g class="m-all" filter="url(#moxi-oil)">
 <g class="m-tails"><path d="M140 94 C158 88 168 100 186 92 C176 104 160 100 142 104Z" fill="url(#moxi-gRed)"/><path d="M140 100 C156 104 160 118 178 116 C164 126 152 114 140 106Z" fill="url(#moxi-gRed)"/></g>
 <g class="m-arm-r"></g>
 <g class="m-head">
  <path d="M58 90 Q48 116 62 132 Q58 112 66 96Z" fill="#1d1513"/><path d="M142 90 Q152 116 138 132 Q142 112 134 96Z" fill="#1d1513"/>
  <circle cx="100" cy="114" r="44" fill="url(#moxi-gSkin)"/>
  <path d="M80 99 Q90 95 100 96" stroke="#fff3df" stroke-width="4" stroke-linecap="round" opacity=".55" fill="none"/>
  <path d="M57 93 Q100 81 143 93 L143 103 Q100 91 57 103Z" fill="url(#moxi-gRed)"/>
  <circle cx="142" cy="98" r="5" fill="#a83322"/>
  <path class="m-brow-l" d="M72 103 L93 108" stroke="#1d1513" stroke-width="5" stroke-linecap="round"/>
  <path class="m-brow-r" d="M128 103 L107 108" stroke="#1d1513" stroke-width="5" stroke-linecap="round"/>
  <g class="m-eyes">
   <g class="m-eo"><ellipse cx="84" cy="119" rx="6.3" ry="7.6" fill="#16100e"/><ellipse cx="116" cy="119" rx="6.3" ry="7.6" fill="#16100e"/>
    <circle cx="86.3" cy="116.2" r="2.3" fill="#fff"/><circle cx="118.3" cy="116.2" r="2.3" fill="#fff"/><circle cx="82" cy="122" r="1" fill="#fff" opacity=".8"/><circle cx="114" cy="122" r="1" fill="#fff" opacity=".8"/>
    <path d="M76 113 Q84 109 93 114 M107 114 Q116 109 124 113" stroke="#16100e" stroke-width="2.6" fill="none" stroke-linecap="round"/></g>
   <g class="m-eh" fill="none" stroke="#16100e" stroke-width="3.4" stroke-linecap="round"><path d="M77 121 Q84 112 91 121"/><path d="M109 121 Q116 112 123 121"/></g>
   <g class="m-es" fill="none" stroke="#16100e" stroke-width="3" stroke-linecap="round"><path d="M77 119 Q84 124 91 119"/><path d="M109 119 Q116 124 123 119"/></g>
  </g>
  <ellipse cx="73" cy="131" rx="7.5" ry="3.8" fill="#e8705a" opacity=".45"/><ellipse cx="127" cy="131" rx="7.5" ry="3.8" fill="#e8705a" opacity=".45"/>
  <path d="M100 124 q2.5 3 -1 4.5" stroke="#b56d45" stroke-width="1.6" fill="none" stroke-linecap="round"/>
  <g class="m-mouth"><path class="mc" d="M93 135 Q101 139 108 132" stroke="#4a1c16" stroke-width="2.8" fill="none" stroke-linecap="round"/><path class="mo" d="M94 133 Q101 144 108 133 Q101 135 94 133Z" fill="#6b231d"/><ellipse class="mr" cx="101" cy="136" rx="3.6" ry="4.6" fill="#6b231d"/><path class="mw" d="M93 133 Q101 141 109 133 Q101 136 93 133Z" fill="#6b231d"/><path class="mz" d="M93 136 q3.5 -3 7 0 t7 0" stroke="#4a1c16" stroke-width="2.6" fill="none" stroke-linecap="round"/></g>
  <path class="m-sweat" d="M146 104 q6 9 0 13 q-6 -4 0 -13z" fill="#d9f3ee" stroke="#1f6461" stroke-width="1.2"/>
 </g>
 <g class="m-hat">
  <ellipse cx="100" cy="82" rx="81" ry="16" fill="#0c2827"/>
  <path d="M19 79 L100 34 L181 79 Q100 98 19 79Z" fill="url(#moxi-gHat)"/>
  <path d="M19 79 Q100 98 181 79" stroke="url(#moxi-gGold)" stroke-width="2.6" fill="none"/>
  <path d="M74 50 L50 68" stroke="#c7f1e8" stroke-width="5" stroke-linecap="round" opacity=".45"/>
  <path d="M112 44 L138 62" stroke="#c7f1e8" stroke-width="3" stroke-linecap="round" opacity=".25"/>
  <g transform="translate(100 70)"><rect x="-9" y="-9" width="18" height="18" rx="5" fill="url(#moxi-gGold)"/><circle cx="-3.4" cy="-3.4" r="3" fill="#1f6461"/><circle cx="3.4" cy="-3.4" r="3" fill="#1f6461"/><circle cx="-3.4" cy="3.4" r="3" fill="#1f6461"/><circle cx="3.4" cy="3.4" r="3" fill="#1f6461"/></g>
  <circle cx="100" cy="35" r="4.8" fill="url(#moxi-gGold)"/>
  <path class="m-r2" d="M30 77 Q100 60 170 77" stroke="#FDCF3C" stroke-width="3.2" fill="none" stroke-linecap="round"/>
  <path class="m-r4" d="M93 63 Q82 38 66 40 Q82 50 97 68Z M107 63 Q118 38 134 40 Q118 50 103 68Z" fill="url(#moxi-gGold)" stroke="#a8681a" stroke-width="1"/>
  <circle class="m-alert" cx="150" cy="62" r="6.5" fill="#e2553f" stroke="#fff4dc" stroke-width="2.5"/>
 </g>
 <g class="m-r3"><circle cx="142" cy="98" r="8" fill="none" stroke="url(#moxi-gGold)" stroke-width="2.4"/></g>
 <path class="m-glint" d="M44 74 L96 44" stroke="#fffbe8" stroke-width="3.5" stroke-linecap="round"/>
 <g class="m-scroll" transform="translate(-6 -58)"><rect x="134" y="176" width="34" height="22" rx="2" fill="#fbf1d8"/><rect x="132" y="174" width="4" height="26" rx="2" fill="#7a4b2a"/><rect x="166" y="174" width="4" height="26" rx="2" fill="#7a4b2a"/><path d="M140 182 H162 M140 187 H158 M140 192 H160" stroke="#1f6461" stroke-width="1.6"/><circle cx="158" cy="193" r="3" fill="#c8452c"/></g>
</g>
</g>
<path class="m-fx" d="M166 30 l3 9 9 3 -9 3 -3 9 -3 -9 -9 -3 9 -3z" fill="#f4bf33"/>
<g class="m-dots" fill="#1f6461"><circle cx="160" cy="26" r="4"/><circle cx="172" cy="26" r="4"/><circle cx="184" cy="26" r="4"/></g>
<path class="m-slash" d="M28 70 Q110 0 186 86" stroke="#fff0b8" stroke-width="5" fill="none" stroke-linecap="round"/>
<text class="m-bang" x="160" y="58" style="font:800 30px var(--display); fill:#c8452c">!</text>
<g class="m-zzz"><text x="160" y="40">z</text><text x="170" y="30">z</text></g>
</svg>
