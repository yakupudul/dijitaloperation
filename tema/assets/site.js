(function(){
  var m=document.getElementById('modal'),box=document.getElementById('mbox'),mb=document.getElementById('mbar');
  function dl(o){(window.dataLayer=window.dataLayer||[]).push(o)}
  function play(v){var p=v.play();if(p&&p.catch)p.catch(function(){})}
  var files={};
  function prep(img){if(!img||files[img]!==undefined||!window.fetch||!window.File||!navigator.canShare)return;files[img]=null;
    fetch(img).then(function(r){return r.ok?r.blob():null}).then(function(bl){if(!bl)return;var f=new File([bl],'video-kapak.'+((bl.type.split('/')[1]||'jpg').replace('jpeg','jpg')),{type:bl.type});if(navigator.canShare({files:[f]}))files[img]=f}).catch(function(){})}
  function note(el,txt){if(!el)return;var o=el.getAttribute('data-txt')||el.innerHTML;el.setAttribute('data-txt',o);el.textContent=txt;setTimeout(function(){el.innerHTML=o},1800)}
  function copy(u,el){var ok=function(){note(el,'Bağlantı kopyalandı')};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(ok,function(){window.prompt('Bağlantı',u)})}else{window.prompt('Bağlantı',u)}dl({event:'video_paylas',kanal:'kopya'})}
  function share(t,u,img,el){
    if(navigator.share){var f=img&&files[img];var d=f?{title:t,text:t+'\n'+u,files:[f]}:{title:t,text:t,url:u};
      navigator.share(d).then(function(){dl({event:'video_paylas',kanal:'cihaz'})}).catch(function(){});return}
    copy(u,el)}
  window.mxpShare=share;
  function bar(b){if(!mb)return;var u=b&&b.dataset.url;if(!u){mb.hidden=true;return}
    mb.hidden=false;mb.querySelector('b').textContent=b.dataset.name||'';mb.querySelector('a').href=u;mb.dataset.url=u;mb.dataset.name=b.dataset.name||'';mb.dataset.img=b.dataset.img||b.dataset.poster||'';prep(mb.dataset.img)}
  function open(id,wide,b){box.className='modal-box'+(wide?' wide':'');box.innerHTML='<iframe src="https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1&rel=0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="Ali Akbulut video"></iframe>';bar(b);m.classList.add('on');document.body.style.overflow='hidden';dl({event:'video_izle',video:(b&&b.dataset.name)||id,yer:'youtube'})}
  function openMp4(src,poster,name,b,wide){box.className='modal-box'+(wide?' wide':'');box.innerHTML='<video controls playsinline autoplay></video>';var v=box.firstChild;if(poster)v.poster=poster;v.src=src;bar(b);m.classList.add('on');document.body.style.overflow='hidden';play(v);dl({event:'video_izle',video:name||'',yer:'dosya'})}
  function close(){m.classList.remove('on');box.innerHTML='';if(mb)mb.hidden=true;document.body.style.overflow=''}
  document.querySelectorAll('[data-yt]').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();open(b.dataset.yt,b.hasAttribute('data-wide'),b)})});
  document.querySelectorAll('[data-mp4]').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();openMp4(b.dataset.mp4,b.dataset.poster,b.dataset.name,b,b.hasAttribute('data-wide'))})});
  if(m){document.getElementById('mx').onclick=close;
    m.addEventListener('click',function(e){if(e.target===m)close()});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&m.classList.contains('on'))close()});}
  document.querySelectorAll('[data-share]').forEach(function(el){
    var host=el.closest('[data-url]')||el;
    if(host.dataset.img)prep(host.dataset.img);
    el.addEventListener('click',function(e){e.preventDefault();var h=el.closest('[data-url]')||el;share(h.dataset.name||document.title,h.dataset.url||location.href,h.dataset.img,el)})});
  document.querySelectorAll('[data-copy]').forEach(function(el){el.addEventListener('click',function(e){e.preventDefault();var h=el.closest('[data-url]');copy(h?h.dataset.url:location.href,el)})});
  document.querySelectorAll('[data-net]').forEach(function(el){el.addEventListener('click',function(){dl({event:'video_paylas',kanal:el.dataset.net})})});
  if(!navigator.share)document.querySelectorAll('.only-share').forEach(function(el){el.hidden=true});
  document.querySelectorAll('[data-ytl]').forEach(function(b){b.addEventListener('click',function(){var f=document.createElement('iframe');f.src='https://www.youtube-nocookie.com/embed/'+b.dataset.ytl+'?autoplay=1&rel=0';f.allow='autoplay; encrypted-media; picture-in-picture';f.allowFullscreen=true;f.title=b.dataset.name||'Video';b.parentNode.replaceChild(f,b);dl({event:'video_izle',video:b.dataset.name||b.dataset.ytl,yer:'video-sayfasi'})})});
  var bg=document.getElementById('burger'),nav=document.getElementById('nav');
  if(bg&&nav){bg.onclick=function(){var o=nav.classList.toggle('open');bg.setAttribute('aria-expanded',o)};
  nav.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){nav.classList.remove('open')})});}
  var still=window.matchMedia&&matchMedia('(prefers-reduced-motion:reduce)').matches;
  function stopClips(keep){document.querySelectorAll('.clip.playing').forEach(function(f){if(f===keep)return;var m=f.querySelector('video,iframe');if(m)m.remove();f.classList.remove('playing')})}
  function playClip(f){if(!f||f.classList.contains('playing'))return;stopClips(f);var fr=f.querySelector('.frame'),m;
    if(f.dataset.yt){m=document.createElement('iframe');m.src='https://www.youtube-nocookie.com/embed/'+f.dataset.yt+'?autoplay=1&playsinline=1&rel=0';m.allow='autoplay; encrypted-media; picture-in-picture';m.allowFullscreen=true;m.title=f.dataset.name||''}
    else{m=document.createElement('video');m.src=f.dataset.clip;m.controls=true;m.playsInline=true;m.setAttribute('playsinline','');m.poster=f.querySelector('.poster').getAttribute('src');
      m.addEventListener('ended',function(){f.classList.remove('playing');m.remove()});play(m)}
    fr.appendChild(m);f.classList.add('playing');dl({event:'video_izle',video:f.dataset.name||'',yer:'serit'})}
  document.querySelectorAll('.clip .play,.clip .poster').forEach(function(b){b.addEventListener('click',function(){playClip(b.closest('.clip'))})});
  document.querySelectorAll('.reels').forEach(function(box){var row=box.querySelector('.r-clips'),bs=box.querySelectorAll('.r-cn');if(!row||!bs.length)return;
    function upd(){bs[0].disabled=row.scrollLeft<4;bs[1].disabled=row.scrollLeft+row.clientWidth>=row.scrollWidth-4}
    bs.forEach(function(b){b.addEventListener('click',function(){row.scrollBy({left:(+b.dataset.d)*row.clientWidth*.8,behavior:'smooth'})})});
    row.addEventListener('scroll',upd,{passive:true});window.addEventListener('resize',upd);upd()});
  var bub=document.querySelector('.bub'),pop=document.querySelector('.pop');
  if(bub&&bub.dataset.v){
    var fr=bub.querySelector('.bub-fr');
    function ytSmall(){bub.hidden=false;if(still||!fr)return;var id=bub.dataset.v;
      fr.innerHTML='<iframe src="https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1&mute=1&loop=1&playlist='+id+'&controls=0&playsinline=1&rel=0&modestbranding=1&disablekb=1" allow="autoplay; encrypted-media" tabindex="-1" title="" aria-hidden="true"></iframe>'}
    if(document.readyState==='complete')setTimeout(ytSmall,1500);else window.addEventListener('load',function(){setTimeout(ytSmall,1500)});
  }
  if(bub&&pop&&!bub.dataset.v){
    var small=bub.querySelector('video'),big=pop.querySelector('video'),last=null;
    
    small.loop=true;small.addEventListener('ended',function(){if(!pop.hidden||still)return;small.currentTime=0;play(small)});
    small.addEventListener('pause',function(){if(pop.hidden&&!still&&small.getAttribute('src')&&!document.hidden&&small.ended){small.currentTime=0;play(small)}});
    document.addEventListener('visibilitychange',function(){if(!document.hidden&&pop.hidden&&!still&&small.getAttribute('src')&&small.paused)play(small)});
    function startSmall(){bub.hidden=false;
      if(still)return;small.src=bub.dataset.src;small.muted=true;play(small)}
    if(document.readyState==='complete')setTimeout(startSmall,1500);else window.addEventListener('load',function(){setTimeout(startSmall,1500)});
    function popOpen(){last=document.activeElement;pop.hidden=false;document.body.classList.add('lock');if(!big.getAttribute('src')){big.src=bub.dataset.full;big.poster=bub.dataset.poster}
      big.muted=false;small.pause();play(big);pop.querySelector('.pop-x').focus();dl({event:'video_izle',video:bub.dataset.name||'',yer:'kose'})}
    function popClose(){big.pause();pop.hidden=true;document.body.classList.remove('lock');if(!bub.hidden&&!still)play(small);if(last&&last.focus)last.focus()}
    bub.querySelector('.bub-open').addEventListener('click',popOpen);
    pop.addEventListener('click',function(e){if(e.target===pop)popClose()});
    pop.querySelector('.pop-x').addEventListener('click',popClose);
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!pop.hidden)popClose()});
  }
  if('IntersectionObserver' in window){var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target)}})},{rootMargin:'0px 0px -8% 0px'});document.querySelectorAll('.rv').forEach(function(el){io.observe(el)})}else{document.querySelectorAll('.rv').forEach(function(el){el.classList.add('in')})}
})();
(function(){
  function dl(o){(window.dataLayer=window.dataLayer||[]).push(o)}
  function esc(t){return String(t).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
  var AR='<svg class="ico"><use href="#i-arrow"/></svg>',WA='<svg class="ico"><use href="#i-wa"/></svg>';
  document.querySelectorAll('.yon-box').forEach(function(box){
    var D;try{D=JSON.parse(box.querySelector('.yon-data').textContent)}catch(e){return}
    var body=box.querySelector('.yon-body'),step=box.querySelector('.yon-step'),back=box.querySelector('.yon-back'),bar=box.querySelector('.yon-bar i'),st=[],started=false,moved=false;
    function q(n){return n===1?D.q2[st[0].k]:D.q[n]}
    function focus(){if(!moved)return;var t=body.querySelector('.yon-t');if(t){t.setAttribute('tabindex','-1');t.focus({preventScroll:true})}
      var r=box.getBoundingClientRect();if(r.top<70||r.top>window.innerHeight*.5)window.scrollTo({top:window.pageYOffset+r.top-110,behavior:'smooth'})}
    function render(){
      var n=st.length;back.hidden=n===0;bar.style.width=(Math.min(n+1,4)*25)+'%';
      if(n===4){result();focus();return}
      step.innerHTML='Soru <b>'+(n+1)+'</b> / 4';
      var Q=q(n);
      body.innerHTML='<p class="yon-t">'+esc(Q.t)+'</p><div class="yon-o">'+Q.o.map(function(o,i){return '<button type="button" data-i="'+i+'">'+esc(o.l)+AR+'</button>'}).join('')+'</div>';
      body.querySelectorAll('button').forEach(function(b){b.addEventListener('click',function(){
        if(!started){started=true;dl({event:'yonlendirici_basla'})}
        moved=true;st.push(Q.o[+b.dataset.i]);render()})});
      focus()}
    function result(){
      var sl=st[1].s,p=D.p[sl],on=st[2].k==='online';
      var msg=['Merhaba, sitenizdeki "Hangi terapi bana uygun?" sorularını cevapladım.','Kimin için: '+st[0].l,'Konu: '+st[1].l,'Görüşme: '+st[2].l,'Ne zaman: '+st[3].l,'Görüşme hakkında bilgi almak istiyorum.'].join('\n');
      step.innerHTML='Sonuç';
      body.innerHTML='<p class="yon-t">Size en yakın sayfa</p>'+
        '<a class="yon-res" href="'+p.u+'"><img src="'+p.i+'" alt="" width="120" height="120" loading="lazy"><span><b>'+esc(p.m)+'</b><small>'+esc(p.s)+'</small><span class="more">Sayfayı okuyun</span></span></a>'+
        (on&&sl!=='online-terapi'?'<p class="yon-p">Bu konuda online görüşme de yapılıyor. <a href="'+D.p['online-terapi'].u+'">Online görüşmeler nasıl ilerliyor?</a></p>':'')+
        '<p class="yon-p">Bu bir ön yönlendirme. Hangi yöntemle çalışılacağına ilk görüşmede, sizi dinledikten sonra birlikte karar verilir.</p>'+
        '<div class="yon-act"><a class="btn btn-green" data-konum="yonlendirici" href="https://wa.me/'+box.dataset.wa+'?text='+encodeURIComponent(msg)+'">'+WA+'Cevaplarımla WhatsApp\'tan yazın</a>'+
        '<button type="button" class="btn btn-line yon-re">Baştan başlayın</button></div>';
      body.querySelector('.yon-re').addEventListener('click',function(){st=[];render()});
      dl({event:'yonlendirici_sonuc',sayfa:sl,kimin:st[0].k,gorusme:st[2].k,zaman:st[3].k})}
    back.addEventListener('click',function(){moved=true;st.pop();render()});
    render()});
  document.addEventListener('click',function(e){
    var a=e.target.closest&&e.target.closest('a[href]');if(!a||a.hasAttribute('data-net'))return;
    var h=a.getAttribute('href')||'',ev=h.indexOf('wa.me/')>-1?'whatsapp_tikla':(h.indexOf('tel:')===0?'telefon_tikla':'');if(!ev)return;
    var s=a.closest('[data-konum],section,header,footer,aside,.pop,.mbar,.side-cta'),k=a.dataset.konum||'';
    if(!k&&s)k=s.dataset.konum||s.id||(s.className&&String(s.className).split(' ')[0])||s.tagName.toLowerCase();
    dl({event:ev,konum:k,sayfa:location.pathname})},true);
})();
(function(){
  var tt=document.querySelectorAll('.story .tt');if(!tt.length)return;
  function fit(){var m=document.createElement('span');m.style.cssText='position:absolute;visibility:hidden;white-space:nowrap;left:-9999px';document.body.appendChild(m);
    tt.forEach(function(t){var it=t.parentNode,o=it.querySelector('.orb'),W=(o?o.clientWidth:it.clientWidth)*.9,H=it.clientHeight*.56,mx=Math.round(it.clientWidth/7);
      m.style.font=getComputedStyle(t).font;var words=t.textContent.split(' ');
      for(var f=mx;f>=11;f-=.5){m.style.fontSize=f+'px';var w=0;words.forEach(function(x){m.textContent=x;w=Math.max(w,m.offsetWidth)});
        t.style.fontSize=f+'px';if(w<=W&&t.scrollHeight<=H)break}});
    m.remove()}
  (document.fonts&&document.fonts.ready?document.fonts.ready:Promise.resolve()).then(fit);
  var rt;window.addEventListener('resize',function(){clearTimeout(rt);rt=setTimeout(fit,150)});
})();
(function(){
  var d=document.querySelector('.belge-dlg');if(!d||!d.showModal)return;
  var im=d.querySelector('img'),p=d.querySelector('p');
  document.querySelectorAll('[data-belge]').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();
    im.src=a.getAttribute('href');var t=a.querySelector('span');p.textContent=t?t.textContent:'';im.alt=p.textContent;d.showModal()})});
  d.querySelector('.belge-x').addEventListener('click',function(){d.close()});
  d.addEventListener('click',function(e){if(e.target===d)d.close()});
})();
