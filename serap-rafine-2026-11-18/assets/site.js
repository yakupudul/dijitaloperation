(function(){
  var WA='https://wa.me/905532953400?text=';
  function dl(o){(window.dataLayer=window.dataLayer||[]).push(o);}

  /* Video: tıklamadan önce hiçbir şey inmez; tıklayınca aynı yerde oynar. */
  function playClip(fig){
    if(!fig||fig.classList.contains('playing'))return;
    var v=fig.querySelector('video');
    if(!v){
      v=document.createElement('video');
      v.src=fig.dataset.src;v.controls=true;v.playsInline=true;v.setAttribute('playsinline','');
      v.poster=fig.querySelector('.poster').getAttribute('src');
      fig.querySelector('.frame').appendChild(v);
      v.addEventListener('ended',function(){fig.classList.remove('playing');v.remove();});
      v.addEventListener('error',function(){fig.classList.remove('playing');v.remove();var l=fig.querySelector('.ig');if(l)l.hidden=false;});
    }
    fig.classList.add('playing');
    var p=v.play();if(p&&p.catch)p.catch(function(){});
    dl({event:'video_izle',video:fig.dataset.name||''});
  }
  document.querySelectorAll('.clip .play').forEach(function(b){
    b.addEventListener('click',function(){playClip(b.closest('.clip'));});
  });
  /* B: hikâye halkaları ilgili videoya götürür ve oynatır */
  document.querySelectorAll('.story,.c-say').forEach(function(s){
    s.addEventListener('click',function(){
      var fig=document.getElementById(s.dataset.target);if(!fig)return;
      fig.scrollIntoView({behavior:'smooth',block:'center'});
      setTimeout(function(){playClip(fig);},500);
    });
  });

  /* İlk mesaj: seçenek, ad ve gün cümleyi değiştirir */
  var card=document.querySelector('.card');
  if(card){
    var msg=card.querySelector('.msg'),go=card.querySelector('.go'),
        name=card.querySelector('#ad'),when=card.querySelector('#gun');
    function build(){
      var r=document.querySelector('input[name=soru]:checked');
      var base=r?r.value:msg.dataset.base;
      var t=base;
      if(name&&name.value.trim())t=t.replace('Merhaba,','Merhaba, ben '+name.value.trim()+',');
      if(when&&when.value)t+=' '+when.value;
      msg.textContent=t;go.href=WA+encodeURIComponent(t);
    }
    document.querySelectorAll('input[name=soru]').forEach(function(r){r.addEventListener('change',function(){build();dl({event:'hazir_soru',soru:r.dataset.k});});});
    [name,when].forEach(function(el){if(el)el.addEventListener('input',build);});
    go.addEventListener('click',function(){dl({event:'randevu_formu',uygulama:card.dataset.svc});});
    build();
  }
  document.querySelectorAll('a[href^="https://wa.me"]').forEach(function(a){a.addEventListener('click',function(){dl({event:'whatsapp_tik',yer:a.dataset.yer||''});});});
  document.querySelectorAll('a[href^="tel:"]').forEach(function(a){a.addEventListener('click',function(){dl({event:'telefon_tik',yer:a.dataset.yer||''});});});
})();

/* 18.11.2026 rafine: sağ alttaki sessiz video, açılır pencere, bugünün saati */
(function(){
  function dl(o){(window.dataLayer=window.dataLayer||[]).push(o);}
  var d=new Date().getDay();
  document.querySelectorAll('#saatler [data-d]').forEach(function(r){var k=r.dataset.d,hit=k==='1-5'?(d>=1&&d<=5):(+k===d);if(hit)r.firstElementChild.insertAdjacentHTML('beforeend','<span class="r-today">Bugün</span>');});

  var bub=document.querySelector('.r-bub'),pop=document.querySelector('.r-pop');
  if(!bub||!pop)return;
  var small=bub.querySelector('video'),big=pop.querySelector('video'),last=null;
  var closed=false;try{closed=sessionStorage.getItem('r-bub-kapali')==='1';}catch(e){}
  var still=window.matchMedia&&matchMedia('(prefers-reduced-motion:reduce)').matches;
  function startSmall(){
    if(closed)return;
    bub.hidden=false;
    if(still)return;
    small.src=bub.dataset.src;small.muted=true;
    var p=small.play();if(p&&p.catch)p.catch(function(){});
  }
  /* sayfa yüklendikten sonra başlar; ilk ekranın hızını düşürmez */
  if(document.readyState==='complete')setTimeout(startSmall,1500);
  else window.addEventListener('load',function(){setTimeout(startSmall,1500);});

  function open(from){
    last=from||document.activeElement;
    pop.hidden=false;document.body.classList.add('r-lock');
    if(!big.getAttribute('src')){big.src=bub.dataset.src;big.poster=bub.dataset.poster;}
    try{big.currentTime=small.currentTime||0;}catch(e){}
    big.muted=false;small.pause();
    var p=big.play();if(p&&p.catch)p.catch(function(){});
    pop.querySelector('.r-pop-x').focus();
    dl({event:'video_izle',video:bub.dataset.name||'',yer:'kose'});
  }
  function close(){
    big.pause();pop.hidden=true;document.body.classList.remove('r-lock');
    if(!bub.hidden&&!still){var p=small.play();if(p&&p.catch)p.catch(function(){});}
    if(last&&last.focus)last.focus();
  }
  bub.querySelector('.r-bub-open').addEventListener('click',function(){open(this);});
  document.querySelectorAll('[data-pop]').forEach(function(b){b.addEventListener('click',function(){open(b);});});
  bub.querySelector('.r-bub-x').addEventListener('click',function(){
    small.pause();small.removeAttribute('src');small.load();bub.hidden=true;closed=true;
    try{sessionStorage.setItem('r-bub-kapali','1');}catch(e){}
  });
  pop.addEventListener('click',function(e){if(e.target===pop)close();});
  pop.querySelector('.r-pop-x').addEventListener('click',close);
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!pop.hidden)close();});
})();
