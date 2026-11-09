{{-- Moxi (panel asistanı): the samurai drawn as one SVG; oil-paint look from an SVG filter, no image file. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<filter id="moxi-oil" x="-15%" y="-15%" width="130%" height="130%" color-interpolation-filters="sRGB">
 <feTurbulence type="fractalNoise" baseFrequency="0.045" numOctaves="2" seed="4" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="2.6" xChannelSelector="R" yChannelSelector="G" result="d"/>
 <feTurbulence type="fractalNoise" baseFrequency="0.05 0.16" numOctaves="3" seed="11" result="t"/>
 <feDiffuseLighting in="t" surfaceScale=".8" lighting-color="#ffffff" result="l"><feDistantLight azimuth="235" elevation="58"/></feDiffuseLighting>
 <feComponentTransfer in="l" result="l2"><feFuncR type="linear" slope="1.12" intercept=".02"/><feFuncG type="linear" slope="1.12" intercept=".02"/><feFuncB type="linear" slope="1.12" intercept=".02"/></feComponentTransfer>
 <feBlend in="d" in2="l2" mode="multiply" result="m"/>
 <feComposite in="m" in2="d" operator="in"/>
</filter>
<filter id="moxi-oilsoft" x="-20%" y="-20%" width="140%" height="140%">
 <feTurbulence type="fractalNoise" baseFrequency="0.03" numOctaves="2" seed="7" result="n"/>
 <feDisplacementMap in="SourceGraphic" in2="n" scale="14" xChannelSelector="R" yChannelSelector="G"/>
</filter>
<radialGradient id="moxi-gSkin" cx=".38" cy=".32" r=".75"><stop offset="0" stop-color="#f2e1cf"/><stop offset=".55" stop-color="#dcc0a6"/><stop offset="1" stop-color="#a7866d"/></radialGradient>
<linearGradient id="moxi-gTeal" x1="0" y1="0" x2=".3" y2="1"><stop offset="0" stop-color="#5f7f7b"/><stop offset=".55" stop-color="#34504d"/><stop offset="1" stop-color="#22312f"/></linearGradient>
<linearGradient id="moxi-gHat" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#1f2b2a"/><stop offset=".28" stop-color="#3e5a57"/><stop offset=".45" stop-color="#9fb3ae"/><stop offset=".56" stop-color="#4a6763"/><stop offset=".8" stop-color="#2f4341"/><stop offset="1" stop-color="#1d2726"/></linearGradient>
<linearGradient id="moxi-gGold" x1="0" y1="0" x2=".4" y2="1"><stop offset="0" stop-color="#e3d6b4"/><stop offset=".45" stop-color="#b59a5e"/><stop offset="1" stop-color="#7c6844"/></linearGradient>
<linearGradient id="moxi-gHak" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3d3a38"/><stop offset="1" stop-color="#1f1d1c"/></linearGradient>
<linearGradient id="moxi-gRed" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8f4a3e"/><stop offset="1" stop-color="#5e2a23"/></linearGradient>
</defs></svg>
<svg viewBox="0 0 200 240" aria-hidden="true">
<circle cx="100" cy="122" r="84" fill="#d8d3c4" opacity=".35" filter="url(#moxi-oilsoft)"/>
<ellipse class="m-shadow" cx="100" cy="226" rx="46" ry="7" fill="#1b2a28" opacity=".22"/>
<g class="m-all" filter="url(#moxi-oil)">
 <g class="m-tails"><path d="M140 94 C158 88 168 100 186 92 C176 104 160 100 142 104Z" fill="url(#moxi-gRed)"/><path d="M140 100 C156 104 160 118 178 116 C164 126 152 114 140 106Z" fill="url(#moxi-gRed)"/></g>
 <path d="M150 204 L62 132" stroke="#2a1d19" stroke-width="10" stroke-linecap="round"/>
 <path d="M150 204 L62 132" stroke="#5a3a2c" stroke-width="3" stroke-linecap="round" opacity=".6"/>
 <ellipse cx="60" cy="130" rx="8" ry="5" transform="rotate(40 60 130)" fill="url(#moxi-gGold)"/>
 <path d="M57 127 L38 106" stroke="#efe3c8" stroke-width="8.5" stroke-linecap="round"/>
 <path d="M57 127 L38 106" stroke="#2a1d19" stroke-width="8.5" stroke-dasharray="2.5 3" opacity=".75"/>
 <path class="m-glint" d="M60 131 L36 103" stroke="#f4f1e8" stroke-width="3.5" stroke-linecap="round"/>
 <circle cx="37" cy="105" r="4.2" fill="url(#moxi-gGold)"/>
 <path d="M70 186 L130 186 L144 220 Q100 228 56 220 Z" fill="url(#moxi-gHak)"/>
 <path d="M88 188 L84 222 M100 188 L100 224 M112 188 L116 222" stroke="#000" stroke-width="1.6" opacity=".35"/>
 <ellipse cx="82" cy="223" rx="11" ry="5" fill="#1d1513"/><ellipse cx="118" cy="223" rx="11" ry="5" fill="#1d1513"/>
 <path d="M68 150 Q100 138 132 150 L135 190 L65 190 Z" fill="url(#moxi-gTeal)"/>
 <path d="M71 150 L36 148 Q31 158 40 166 L74 168 Z" fill="url(#moxi-gGold)"/>
 <path d="M129 150 L164 148 Q169 158 160 166 L126 168 Z" fill="url(#moxi-gGold)"/>
 <path d="M72 152 L87 149 L93 187 L70 187 Z" fill="url(#moxi-gGold)"/><path d="M128 152 L113 149 L107 187 L130 187 Z" fill="url(#moxi-gGold)"/>
 <path d="M86 147 L100 178 L114 147 L107 147 L100 163 L93 147Z" fill="#f1ece2"/>
 <g transform="translate(52 156)"><circle r="6.5" fill="#34504d"/><circle cx="-2.2" cy="-2.2" r="1.8" fill="#c9b78a"/><circle cx="2.2" cy="-2.2" r="1.8" fill="#c9b78a"/><circle cx="-2.2" cy="2.2" r="1.8" fill="#c9b78a"/><circle cx="2.2" cy="2.2" r="1.8" fill="#c9b78a"/></g>
 <g class="m-r3" transform="translate(148 156)"><circle r="6.5" fill="#7d3b31"/><circle r="2.6" fill="#c9b78a"/><path d="M-3 6 L-5 15 M0 6 L0 16 M3 6 L5 15" stroke="#7d3b31" stroke-width="2" stroke-linecap="round"/></g>
 <rect x="64" y="181" width="72" height="10" rx="3" fill="#7d3b31"/><path d="M66 184 L134 184" stroke="#b98b80" stroke-width="1.5" opacity=".6"/>
 <path d="M58 158 Q48 172 56 190 L72 186 Q70 170 72 160Z" fill="url(#moxi-gTeal)"/><circle cx="62" cy="190" r="7" fill="url(#moxi-gSkin)"/>
 <g class="m-arm-r"><path d="M142 158 Q152 172 144 190 L128 186 Q130 170 128 160Z" fill="url(#moxi-gTeal)"/><circle cx="138" cy="190" r="7" fill="url(#moxi-gSkin)"/>
  <g class="m-scroll"><rect x="134" y="176" width="34" height="22" rx="2" fill="#f1ece2"/><rect x="132" y="174" width="4" height="26" rx="2" fill="#7a4b2a"/><rect x="166" y="174" width="4" height="26" rx="2" fill="#7a4b2a"/><path d="M140 182 H162 M140 187 H158 M140 192 H160" stroke="#34504d" stroke-width="1.6"/><circle cx="158" cy="193" r="3" fill="#7d3b31"/></g></g>
 <g class="m-head">
  <path d="M58 90 Q48 116 62 132 Q58 112 66 96Z" fill="#1d1513"/><path d="M142 90 Q152 116 138 132 Q142 112 134 96Z" fill="#1d1513"/>
  <circle cx="100" cy="114" r="44" fill="url(#moxi-gSkin)"/>
  <path d="M80 99 Q90 95 100 96" stroke="#f6ece0" stroke-width="4" stroke-linecap="round" opacity=".55" fill="none"/>
  <path d="M57 93 Q100 81 143 93 L143 103 Q100 91 57 103Z" fill="url(#moxi-gRed)"/>
  <circle cx="142" cy="98" r="5" fill="#6a3028"/>
  <path class="m-brow-l" d="M72 103 L93 108" stroke="#1d1513" stroke-width="3.6" stroke-linecap="round"/>
  <path class="m-brow-r" d="M128 103 L107 108" stroke="#1d1513" stroke-width="3.6" stroke-linecap="round"/>
  <g class="m-eyes">
   <g class="m-eo"><ellipse cx="84" cy="119" rx="5.2" ry="5.8" fill="#16100e"/><ellipse cx="116" cy="119" rx="5.2" ry="5.8" fill="#16100e"/>
    <circle cx="86.3" cy="116.2" r="1.3" fill="#fff"/><circle cx="118.3" cy="116.2" r="1.3" fill="#fff"/><circle cx="82" cy="122" r=".6" fill="#fff" opacity=".4"/><circle cx="114" cy="122" r=".6" fill="#fff" opacity=".4"/>
    <path d="M76 113 Q84 109 93 114 M107 114 Q116 109 124 113" stroke="#16100e" stroke-width="2.6" fill="none" stroke-linecap="round"/></g>
   <g class="m-eh" fill="none" stroke="#16100e" stroke-width="3.4" stroke-linecap="round"><path d="M77 121 Q84 112 91 121"/><path d="M109 121 Q116 112 123 121"/></g>
   <g class="m-es" fill="none" stroke="#16100e" stroke-width="3" stroke-linecap="round"><path d="M77 119 Q84 124 91 119"/><path d="M109 119 Q116 124 123 119"/></g>
  </g>
  <ellipse cx="73" cy="131" rx="7" ry="3.2" fill="#c99a8a" opacity=".18"/><ellipse cx="127" cy="131" rx="7" ry="3.2" fill="#c99a8a" opacity=".18"/>
  <path d="M100 124 q2.5 3 -1 4.5" stroke="#9a7a64" stroke-width="1.6" fill="none" stroke-linecap="round"/>
  <g class="m-mouth"><path class="mc" d="M93 135 Q101 139 108 132" stroke="#4a3430" stroke-width="2.8" fill="none" stroke-linecap="round"/><path class="mo" d="M94 133 Q101 144 108 133 Q101 135 94 133Z" fill="#5a3a34"/><ellipse class="mr" cx="101" cy="136" rx="3.6" ry="4.6" fill="#5a3a34"/><path class="mw" d="M93 133 Q101 141 109 133 Q101 136 93 133Z" fill="#5a3a34"/><path class="mz" d="M93 136 q3.5 -3 7 0 t7 0" stroke="#4a3430" stroke-width="2.6" fill="none" stroke-linecap="round"/></g>
  <path class="m-sweat" d="M146 104 q6 9 0 13 q-6 -4 0 -13z" fill="#e6ece9" stroke="#34504d" stroke-width="1.2"/>
 </g>
 <g class="m-hat">
  <ellipse cx="100" cy="82" rx="81" ry="16" fill="#1b2423"/>
  <path d="M19 79 L100 34 L181 79 Q100 98 19 79Z" fill="url(#moxi-gHat)"/>
  <path d="M19 79 Q100 98 181 79" stroke="url(#moxi-gGold)" stroke-width="2.6" fill="none"/>
  <path d="M74 50 L50 68" stroke="#d9e2df" stroke-width="5" stroke-linecap="round" opacity=".45"/>
  <path d="M112 44 L138 62" stroke="#d9e2df" stroke-width="3" stroke-linecap="round" opacity=".25"/>
  <g transform="translate(100 70)"><rect x="-9" y="-9" width="18" height="18" rx="5" fill="url(#moxi-gGold)"/><circle cx="-3.4" cy="-3.4" r="3" fill="#34504d"/><circle cx="3.4" cy="-3.4" r="3" fill="#34504d"/><circle cx="-3.4" cy="3.4" r="3" fill="#34504d"/><circle cx="3.4" cy="3.4" r="3" fill="#34504d"/></g>
  <circle cx="100" cy="35" r="4.8" fill="url(#moxi-gGold)"/>
  <path class="m-r2" d="M30 77 Q100 60 170 77" stroke="#c9b78a" stroke-width="3.2" fill="none" stroke-linecap="round"/>
  <path class="m-r4" d="M93 63 Q82 38 66 40 Q82 50 97 68Z M107 63 Q118 38 134 40 Q118 50 103 68Z" fill="url(#moxi-gGold)" stroke="#7c6844" stroke-width="1"/>
  <circle class="m-alert" cx="150" cy="62" r="6.5" fill="#9c4a3d" stroke="#f1ece2" stroke-width="2.5"/>
 </g>
</g>
<path class="m-fx" d="M166 30 l3 9 9 3 -9 3 -3 9 -3 -9 -9 -3 9 -3z" fill="#b59a5e"/>
<g class="m-dots" fill="#34504d"><circle cx="160" cy="26" r="4"/><circle cx="172" cy="26" r="4"/><circle cx="184" cy="26" r="4"/></g>
<path class="m-slash" d="M28 70 Q110 0 186 86" stroke="#efe8d6" stroke-width="5" fill="none" stroke-linecap="round"/>
<text class="m-bang" x="160" y="58" style="font:800 30px var(--display); fill:#7d3b31">!</text>
<g class="m-zzz"><text x="160" y="40">z</text><text x="170" y="30">z</text></g>
</svg>
