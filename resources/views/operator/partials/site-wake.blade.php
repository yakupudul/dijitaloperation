{{-- Sites that fetch their work themselves and have work on its way: the operator's browser starts their ask (connector 1.16.0+ beacon, at most once a minute per site on the site's side). --}}
@php($siteWakeUrls = \App\Services\Integrations\WordPress\WordPressConnectorCommands::wakeUrls())
@if ($siteWakeUrls !== [])
    <script data-site-wake>(function(){var u=@json($siteWakeUrls),n=0;function p(){u.forEach(function(x){try{if(navigator.sendBeacon){navigator.sendBeacon(x);}else{fetch(x,{method:'POST',mode:'no-cors',keepalive:true,credentials:'omit'});}}catch(e){}});if(++n<15){setTimeout(p,60000);}}p();})();</script>
@endif
