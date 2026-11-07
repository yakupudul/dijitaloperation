// Moximu sosyal medya görsel üreticisi. Kullanım: node gen.cjs <icerik.json> <cikti-klasoru>
// Bilgi kartları (açık zemin): cover, item, end, split, date, case, three, veri, mockup, story, story-score.
// Fotoğraflı editoryal (2026-11-07): foto, foto-sayi, foto-story, siyah, siyah-cevap. Kapak fotoğraflı, iç sayfa kart.
// AI görseli yok: lisansı serbest stok klinik fotoğrafları (assets/foto, kaynaklar.md), tipografi, Moximu işareti, müşteri logoları.
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const [, , inFile, outDir] = process.argv;
const data = JSON.parse(fs.readFileSync(inFile, 'utf8'));
const A = path.join(__dirname, 'assets');
const mark = fs.readFileSync(path.join(A, 'mark-clean.svg'), 'utf8').replace(/width="1000" height="1000"/, '');
const img64 = (f) => `data:image/${/\.jpe?g$/i.test(f) ? 'jpeg' : 'png'};base64,` + fs.readFileSync(path.join(A, f)).toString('base64');
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
// Editoryal metin: satır sonu \n, *sarı vurgu*.
const fmt = (s) => esc(s).replace(/\*([^*]+)\*/g, '<span class="fx-y">$1</span>').replace(/\n/g, '<br>');
let series = 'Rehber';

const css = `
*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1350px;background:#F5F4F0;font-family:Inter,sans-serif;color:#111827;overflow:hidden;-webkit-font-smoothing:antialiased}
body.story{height:1920px}
.wrap{position:absolute;inset:0;padding:72px 72px 64px;display:flex;flex-direction:column}
.top{display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:14px;font-size:30px;font-weight:600;letter-spacing:-.01em}
.brand .m{width:46px;height:46px;border-radius:11px;overflow:hidden}
.brand .m svg{width:46px;height:46px;display:block}
.brand span{color:#8A8F98;font-weight:400}
.tag{font-size:22px;color:#0F5A52;background:#E3EFEC;border-radius:999px;padding:10px 22px;font-weight:500}
h1{font-size:92px;line-height:1.02;font-weight:500;letter-spacing:-.035em;margin-top:64px}
h1 .g{color:#8A8F98;display:block}
.lead{font-size:30px;line-height:1.4;color:#4B5160;margin-top:28px;max-width:860px}
.panel{margin-top:auto;border-radius:44px;padding:52px;position:relative;overflow:hidden;background:#FBF6E6}
.panel:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 92% 8%,rgba(253,207,60,.95),rgba(253,207,60,0) 46%),radial-gradient(circle at 6% 100%,rgba(15,90,82,.28),rgba(15,90,82,0) 48%)}
.panel>*{position:relative}
.card{background:#fff;border-radius:28px;padding:36px 40px;box-shadow:0 18px 50px rgba(17,24,39,.08)}
.li{display:flex;gap:18px;align-items:flex-start;font-size:31px;line-height:1.3;padding:13px 0}
.dot{flex:none;width:34px;height:34px;border-radius:50%;background:#0F5A52;color:#fff;font-size:18px;display:flex;align-items:center;justify-content:center;margin-top:2px}
.dot.no{background:#E5E7EB;color:#6B7280}
.foot{display:flex;align-items:center;justify-content:space-between;margin-top:40px;font-size:26px;color:#4B5160}
.foot b{color:#111827;font-weight:500}
.pill{background:#0F5A52;color:#fff;border-radius:999px;padding:20px 36px;font-size:27px;font-weight:500}
.num{font-size:150px;font-weight:300;letter-spacing:-.05em;color:#0F5A52;line-height:1;margin-top:90px}
.ih{font-size:68px;line-height:1.08;font-weight:500;letter-spacing:-.03em;margin-top:36px}
.ip{font-size:36px;line-height:1.45;color:#4B5160;margin-top:34px;max-width:900px}
.bar{position:absolute;left:72px;right:72px;bottom:64px;display:flex;justify-content:space-between;font-size:24px;color:#8A8F98}
.note{font-size:22px;color:#8A8F98;margin-top:22px;line-height:1.4}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.cols h3{font-size:24px;font-weight:600;margin-bottom:8px;color:#0F5A52}
.cols .card.n h3{color:#9CA3AF}
.cols .li{font-size:27px}
.big{font-size:210px;font-weight:300;letter-spacing:-.06em;line-height:.95;color:#111827}
.logo{height:92px;max-width:520px;object-fit:contain}
.row{display:flex;gap:24px;margin-top:34px}
.kpi{flex:1;background:#fff;border-radius:24px;padding:28px 32px}
.kpi b{display:block;font-size:64px;font-weight:400;letter-spacing:-.03em;color:#0F5A52}
.kpi span{font-size:25px;color:#4B5160}
.src{font-size:22px;color:#6B7280;margin-top:26px}
.three .t{display:flex;gap:26px;padding:26px 0;border-top:1px solid #ECE9E1}
.three .t:first-child{border-top:0}
.three .t i{flex:none;font-style:normal;width:64px;height:64px;border-radius:18px;background:#FDCF3C;font-size:28px;font-weight:600;display:flex;align-items:center;justify-content:center}
.three .t b{display:block;font-size:34px;font-weight:600;letter-spacing:-.01em}
.three .t span{display:block;font-size:27px;line-height:1.4;color:#4B5160;margin-top:6px}
.story .wrap{padding:110px 80px 120px}
.story h1{font-size:104px;margin-top:150px}
.story .ip{font-size:42px}
.story .cta{margin-top:auto;align-self:flex-start;background:#0F5A52;color:#fff;border-radius:999px;padding:28px 46px;font-size:36px;font-weight:500}
.story .disc{position:absolute;right:-170px;top:1050px;width:560px;height:560px;border-radius:50%;background:#FDCF3C;opacity:.9}
.deco{position:absolute;right:-140px;bottom:-140px;width:520px;height:520px;border-radius:50%;background:#FDCF3C;opacity:.85}
.deco2{position:absolute;right:300px;bottom:-200px;width:360px;height:360px;border-radius:50%;background:#0F5A52;opacity:.12}
.wrap{z-index:1}
.fx{font-family:"Inter Display",Inter,sans-serif;color:#fff}
.fx-bg{position:absolute;inset:0;background:#0d0d0e}
.fx-ph{position:absolute;inset:0;background-size:cover;background-repeat:no-repeat}
.fx-shade{position:absolute;inset:0}
.fx-tag{position:absolute;left:56px;top:52px;display:flex;align-items:center;gap:12px;font-size:24px;font-weight:500;letter-spacing:-.01em;color:#fff}
.story .fx-tag{left:70px;top:150px;font-size:30px}
.fx-tag .m{width:30px;height:30px;border-radius:7px;overflow:hidden}.fx-tag .m svg{width:30px;height:30px;display:block}
.story .fx-tag .m,.story .fx-tag .m svg{width:38px;height:38px}
.fx-tag span{font-weight:300;opacity:.8}
.fx-t{position:absolute;font-weight:300;letter-spacing:-.045em;line-height:.98;color:#fff}
.fx-y{color:#FDCF3C}
.fx-swipe{position:absolute;right:56px;bottom:52px;font-size:22px;font-weight:400;opacity:.85;color:#fff}
.fx-src{position:absolute;left:56px;bottom:52px;font-size:20px;font-weight:300;opacity:.8;color:#fff;max-width:760px;line-height:1.35}
.fx-cta{position:absolute;left:70px;bottom:300px;background:#FDCF3C;color:#111;border-radius:999px;padding:26px 44px;font-size:34px;font-weight:500;font-family:"Inter Display",Inter,sans-serif}
.fx-c{position:absolute;left:0;right:0;top:0;bottom:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:0 80px}
.fx-line{width:2px;background:#FDCF3C;margin-bottom:70px}
.fx-pg{position:absolute;right:56px;bottom:52px;font-size:22px;font-weight:300;opacity:.6;color:#fff}
.bars .b{padding:16px 0}
.bars .b .l{display:flex;justify-content:space-between;font-size:30px;margin-bottom:12px}
.bars .b .l b{font-weight:500;color:#0F5A52}
.bars .b .tr{height:22px;border-radius:999px;background:#ECE9E1;overflow:hidden}
.bars .b .tr i{display:block;height:100%;border-radius:999px;background:#0F5A52}
.bars .b:first-child .tr i{background:#FDCF3C}
.mock{position:relative;height:640px}
.mock .bw{position:absolute;left:0;top:10px;width:780px;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 24px 60px rgba(17,24,39,.16)}
.mock .bw .bb{height:34px;background:#ECE9E1;display:flex;gap:9px;align-items:center;padding-left:16px}
.mock .bw .bb i{width:12px;height:12px;border-radius:50%;background:#D4D0C6}
.mock .bw img{display:block;width:100%}
.mock .ph{position:absolute;right:0;top:60px;width:270px;height:570px;border-radius:40px;background:#111;padding:10px;box-shadow:0 30px 70px rgba(17,24,39,.28)}
.mock .ph img{width:100%;height:100%;object-fit:cover;object-position:top;border-radius:31px;display:block}
.story .disc2{position:absolute;left:-200px;top:1180px;width:460px;height:460px;border-radius:50%;background:#0F5A52;opacity:.12}
`;

const brand = (label = series) => `<div class="brand"><div class="m">${mark}</div>Moximu <span>${label}</span></div>`;
const top = (tag, label) => `<div class="top">${brand(label)}${tag ? `<div class="tag">${esc(tag)}</div>` : ''}</div>`;
const fxTag = (label = series) => `<div class="fx-tag"><div class="m">${mark}</div>Moximu <span>${esc(label)}</span></div>`;
const photo = (s) => `<div class="fx-bg"></div><div class="fx-ph" style="background-image:url(${img64(s.img)});background-position:${s.pos || '50% 50%'}"></div><div class="fx-shade" style="background:rgba(10,10,12,${s.shade ?? 0.3})"></div>`;
const lis = (arr, cls = '') => arr.map((x, i) => `<div class="li"><div class="dot ${cls}">${cls === 'no' ? '×' : '✓'}</div><div>${esc(x)}</div></div>`).join('');

function slideHtml(s, idx, total) {
  const pager = total > 1 ? `<div class="bar"><span>Moximu ${esc(series)}</span><span>${idx + 1} / ${total}</span></div>` : '';
  switch (s.t) {
    case 'cover':
      return `<div class="wrap">${top(s.tag)}<h1>${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1><p class="lead">${esc(s.lead)}</p>
        <div class="panel"><div class="card">${s.list.map((x, i) => `<div class="li"><div class="dot">${i + 1}</div><div>${esc(x)}</div></div>`).join('')}</div></div>
        <div class="foot"><span><b>moximu.com</b>/rehber</span><span class="pill">Kaydırın ›</span></div></div>`;
    case 'item':
      return `<div class="deco"></div><div class="deco2"></div><div class="wrap">${top(null)}<div class="num">${esc(s.n)}</div><div class="ih">${esc(s.h)}</div><p class="ip">${esc(s.p)}</p></div>${pager}`;
    case 'end':
      return `<div class="wrap">${top(null)}<h1 style="font-size:80px">${esc(s.h)}</h1>
        <div class="panel"><div class="card">${lis(s.list)}</div>${s.note ? `<p class="note">${esc(s.note)}</p>` : ''}</div>
        <div class="foot"><span><b>${esc(s.url.split('/')[0])}</b>/${esc(s.url.split('/').slice(1).join('/'))}</span><span class="pill">${esc(s.pill || 'Rehberi okuyun')} ›</span></div></div>`;
    case 'split':
      return `<div class="wrap">${top(s.tag)}<h1 style="font-size:84px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel"><div class="cols"><div class="card"><h3>Serbest</h3>${lis(s.yes)}</div><div class="card n"><h3>Kurallara aykırı</h3>${lis(s.no, 'no')}</div></div></div>
        <div class="foot"><span><b>moximu.com</b>/rehber</span><span class="pill">Rehberi okuyun ›</span></div></div>`;
    case 'date':
      return `<div class="wrap">${top(s.tag, 'Gündem')}<div class="num" style="margin-top:70px;font-size:120px;color:#111827">${esc(s.big)}</div>
        <h1 style="margin-top:20px;font-size:80px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel"><div class="card">${lis(s.list)}</div></div>
        <div class="foot"><span><b>moximu.com</b>/saglik-reklam-mevzuati</span><span class="pill">Özeti okuyun ›</span></div></div>`;
    case 'case':
      return `<div class="wrap">${top(s.tag, 'Vaka')}
        <div class="card" style="margin-top:64px;display:flex;align-items:center;justify-content:space-between;padding:44px 48px"><img class="logo" style="max-width:430px" src="${img64(s.logo)}"><span style="font-size:26px;color:#4B5160;white-space:nowrap;margin-left:24px">${esc(s.who)}</span></div>
        <div class="panel" style="margin-top:36px"><div class="big">${esc(s.big)}</div><div style="font-size:40px;margin-top:10px">${esc(s.label)}</div>
        <div class="row">${s.rows.map(([b, l]) => `<div class="kpi"><b>${esc(b)}</b><span>${esc(l)}</span></div>`).join('')}</div>
        <p class="src">${esc(s.src)} · yüzdeler Google'ın kendi verisinden</p></div>
        <div class="foot"><span><b>moximu.com</b>/referanslar</span><span class="pill">Vakayı okuyun ›</span></div></div>`;
    case 'three':
      return `<div class="wrap">${top(s.tag)}<h1 style="font-size:88px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel three"><div class="card">${s.items.map(([b, t], i) => `<div class="t"><i>${i + 1}</i><div><b>${esc(b)}</b><span>${esc(t)}</span></div></div>`).join('')}</div></div>
        <div class="foot"><span><b>${esc((s.url || 'moximu.com/rehber').split('/')[0])}</b>${(s.url || 'moximu.com/rehber').includes('/') ? '/' + esc((s.url || 'moximu.com/rehber').split('/').slice(1).join('/')) : ''}</span><span class="pill">${esc(s.pill || 'Rehberi okuyun')} ›</span></div></div>`;
    case 'story':
      return `<div class="disc"></div><div class="disc2"></div><div class="wrap">${top(s.tag)}<h1>${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1><p class="ip">${esc(s.p)}</p><div class="cta">${esc(s.cta)} ›</div></div>`;
    case 'story-score':
      return `<div class="disc"></div><div class="disc2"></div><div class="wrap">${top(s.tag, s.tag === 'Vaka' ? 'Vaka' : '')}<div class="big" style="margin-top:200px;font-size:${String(s.big).length > 4 ? 230 : 300}px">${esc(s.big)}</div><div style="font-size:46px;margin-top:20px">${esc(s.label)}</div><p class="ip">${esc(s.p)}</p><div class="cta">${esc(s.cta)} ›</div></div>`;
    case 'veri':
      return `<div class="wrap">${top(s.tag)}<h1 style="font-size:80px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        ${s.note ? `<p class="lead">${esc(s.note)}</p>` : ''}
        <div class="panel" style="margin-bottom:60px"><div class="card bars">${s.bars.map(([l, v, shown]) => `<div class="b"><div class="l"><span>${esc(l)}</span><b>${esc(shown ?? '%' + v)}</b></div><div class="tr"><i style="width:${Math.max(2, v)}%"></i></div></div>`).join('')}</div>
        <p class="src" style="margin-top:22px">${esc(s.src)}</p></div></div>${pager}`;
    case 'mockup':
      return `<div class="wrap">${top(s.tag)}<h1 style="font-size:80px;margin-top:52px">${esc(s.h1)}<span class="g">${esc(s.h2)}</span></h1>
        <div class="panel" style="padding:44px 44px 30px"><div class="mock"><div class="bw"><div class="bb"><i></i><i></i><i></i></div><img src="${img64(s.desktop)}"></div><div class="ph"><img src="${img64(s.mobile)}"></div></div></div>
        <div class="foot"><span><b>${esc(s.url)}</b></span><span class="pill">Kaydırın ›</span></div></div>`;
    case 'foto':
      return `<div class="fx">${photo(s)}${fxTag(s.series)}
        <div class="fx-t" style="left:54px;top:${s.tt ?? 150}px;font-size:${s.ts ?? 92}px">${fmt(s.top)}</div>
        <div class="fx-t" style="${s.right ? 'right:56px;text-align:right' : 'left:54px'};bottom:${s.bb ?? 130}px;font-size:${s.bs ?? 100}px">${fmt(s.bottom)}</div>
        ${s.src ? `<div class="fx-src">${esc(s.src)}</div>` : ''}${total > 1 ? '<div class="fx-swipe">Kaydırın ›</div>' : ''}</div>`;
    case 'foto-sayi':
      return `<div class="fx">${photo({ shade: 0.45, ...s })}${fxTag(s.series)}
        <div class="fx-t" style="left:48px;top:140px;font-size:${s.bigSize ?? 260}px">${fmt(s.big)}</div>
        <div class="fx-t" style="right:56px;top:${s.sideTop ?? 440}px;font-size:60px;text-align:right">${fmt(s.side)}</div>
        <div class="fx-t" style="left:56px;bottom:150px;font-size:42px;max-width:820px;line-height:1.18;letter-spacing:-.025em">${fmt(s.sub)}</div>
        <div class="fx-src">${esc(s.src)}</div>${total > 1 ? '<div class="fx-swipe">Kaydırın ›</div>' : ''}</div>`;
    case 'foto-story':
      return `<div class="fx">${photo(s)}${fxTag(s.series)}
        <div class="fx-t" style="left:66px;top:${s.tt ?? 330}px;font-size:${s.ts ?? 140}px">${fmt(s.top)}</div>
        <div class="fx-t" style="left:66px;top:${s.bt ?? 1060}px;font-size:${s.bs ?? 120}px">${fmt(s.bottom)}</div>
        ${s.cta ? `<div class="fx-cta">${esc(s.cta)} ›</div>` : ''}</div>`;
    case 'siyah':
      return `<div class="fx"><div class="fx-bg"></div>${fxTag(s.series)}<div class="fx-c"><div class="fx-line" style="height:${s.line ?? 300}px"></div>
        <div class="fx-t" style="position:static;font-size:${s.ts ?? 84}px">${fmt(s.h)}</div>
        ${s.quote ? `<div style="font-size:${s.qs ?? 44}px;font-weight:300;letter-spacing:-.02em;line-height:1.25;margin-top:56px;max-width:880px">${fmt(s.quote)}</div>` : ''}</div>
        ${s.foot ? `<div style="position:absolute;left:0;right:0;bottom:${s.footBottom ?? 56}px;text-align:center;font-size:22px;font-weight:300;opacity:.6">${esc(s.foot)}</div>` : ''}
        ${s.cta ? `<div class="fx-cta" style="left:50%;transform:translateX(-50%)">${esc(s.cta)} ›</div>` : ''}</div>`;
    case 'siyah-cevap':
      return `<div class="fx"><div class="fx-bg"></div>${fxTag(s.series)}
        <div class="fx-t" style="left:50px;top:170px;font-size:${s.bigSize ?? 240}px">${fmt(s.big)}</div>
        <div style="position:absolute;left:56px;right:80px;top:${s.pTop ?? 520}px;font-size:44px;font-weight:300;letter-spacing:-.02em;line-height:1.3">${fmt(s.p)}</div>
        ${s.src ? `<div class="fx-src" style="opacity:.55">${esc(s.src)}</div>` : ''}${total > 1 ? `<div class="fx-pg">${idx + 1} / ${total}</div>` : ''}</div>`;
  }
  throw new Error('Bilinmeyen şablon: ' + s.t);
}

(async () => {
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await chromium.launch();
  const page = await browser.newPage({ deviceScaleFactor: 1 });
  const manifest = [];
  for (const post of data.posts) {
    const files = [];
    if (!post.slides) continue; // Reels: reel-foto.cjs
    const story = post.kind === 'story';
    series = post.series || 'Rehber';
    for (let i = 0; i < post.slides.length; i++) {
      await page.setViewportSize({ width: 1080, height: story ? 1920 : 1350 });
      await page.setContent(`<!doctype html><html lang="tr"><head><meta charset="utf-8"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet"><style>${css}</style></head><body class="${story ? 'story' : ''}">${slideHtml(post.slides[i], i, post.slides.length)}</body></html>`, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      const file = path.join(outDir, `${post.slug}-${i + 1}.jpg`);
      await page.screenshot({ path: file, type: 'jpeg', quality: 92 });
      files.push(file);
    }
    manifest.push({ ...post, files });
    console.log(post.slug, files.length);
  }
  fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2));
  await browser.close();
})();
