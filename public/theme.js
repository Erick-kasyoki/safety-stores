(function(){
 var D=document,R=D.documentElement,$=function(i){return D.getElementById(i)};
 function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
 window.mk=function(x){x=String(x||'');var i=x.indexOf('@');return i<2?x:x[0]+'******'+x[i-1]+x.slice(i)};
 window.whoHtml=function(u){return '<span class="av"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><span class="un"><b>'+esc(u.name)+'</b><small>'+esc(mk(u.email))+'</small></span>'};
 /* theme toggle */
 var tg=$('tg');
 /* night-sky switch: starry track, clouds, little orange moon, glowing moon/sun knob */
 /* cloud bank: periodic bumps (period 120) drawn as outlined circles, repeated so it can drift forever */
 function cloudSvg(cls,bumps,grad,stroke){
  var c='',k,i,b2;
  for(k=-1;k<=2;k++)for(i=0;i<bumps.length;i++){b2=bumps[i];c+='<circle cx="'+(b2[0]+k*120)+'" cy="'+b2[2]+'" r="'+(b2[1]+1.1)+'"/>'}
  var f='';for(k=-1;k<=2;k++)for(i=0;i<bumps.length;i++){b2=bumps[i];f+='<circle cx="'+(b2[0]+k*120)+'" cy="'+b2[2]+'" r="'+b2[1]+'"/>'}
  return '<svg class="nm-cl '+cls+'" viewBox="-120 0 360 40" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="g'+cls+'" gradientUnits="userSpaceOnUse" x1="0" y1="8" x2="0" y2="40">'+grad+'</linearGradient></defs><g fill="'+stroke+'">'+c+'</g><g fill="url(#g'+cls+')">'+f+'<rect x="-120" y="30" width="360" height="12"/></g></svg>'}
 var CLOUD=
  cloudSvg('bk',[[6,7,31],[19,9,28],[33,8,31],[47,11,27],[60,8,31],[72,9,29],[85,10,27],[98,8,31],[110,9,29]],'<stop offset="0" stop-color="#9cc8ff"/><stop offset="1" stop-color="#4f86d6"/>','#cfe5ff')+
  cloudSvg('fr',[[0,8,35],[12,10,32],[26,9,35],[39,12,31],[53,9,35],[65,10,33],[78,12,31],[92,9,35],[104,11,33],[116,8,35]],'<stop offset="0" stop-color="#ffffff"/><stop offset=".5" stop-color="#d6eaff"/><stop offset="1" stop-color="#78aef0"/>','#ffffff');
 var CRES='<svg class="nm-cr" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.8 2.2A10.3 10.3 0 1 0 21.8 17 8.4 8.4 0 0 1 17.8 2.2z" fill="#fbbf24" stroke="#c2740c" stroke-width="1.3" stroke-linejoin="round"/><circle cx="8.6" cy="14.2" r="1.35" fill="#e8920f"/><circle cx="12.6" cy="18.3" r="1" fill="#e8920f"/><circle cx="7.4" cy="9.4" r=".9" fill="#e8920f"/></svg>';
 if(tg){tg.className='hi nm';tg.setAttribute('role','switch');tg.setAttribute('aria-label','Night mode');tg.title='Night mode';
  tg.innerHTML='<span class="nm-sky"><span class="nm-day"></span><i class="nm-st a"></i><i class="nm-st b"></i><i class="nm-st c"></i><i class="nm-st d"></i><i class="nm-st e"></i><i class="nm-st f"></i><i class="nm-st g"></i>'+CRES+CLOUD+'</span><span class="nm-knob"></span>'}
 var SUN='<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',MOON='<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
 function setT(t){R.setAttribute('data-theme',t);if(tg)tg.setAttribute('aria-checked',t=='dark'?'true':'false');try{localStorage.setItem('ss-theme',t)}catch(x){}}
 var t0='light';try{t0=localStorage.getItem('ss-theme')||'light'}catch(x){}
 setT(t0);if(tg)tg.onclick=function(){tg.classList.remove('go');void tg.offsetWidth;tg.classList.add('go');setT(R.getAttribute('data-theme')=='dark'?'light':'dark')};
 /* bell + settings */
 var bell=$('hbell'),set=$('hset');
 if(bell)bell.onclick=function(){var b=D.querySelector('[data-t=o]');if(b)b.click()};
 if(set)set.onclick=function(){var b=D.querySelector('[data-t=s]');if(b)b.click()};
 var ob=$('ob'),bb=$('bb');
 function sync(){if(!ob||!bb)return;var t=ob.textContent.trim();bb.hidden=ob.hidden||!t||t=='0';bb.textContent=t}
 if(ob&&bb){new MutationObserver(sync).observe(ob,{attributes:true,childList:true,characterData:true,subtree:true});sync()}
 /* split login (admin + worker gate) */
 var gate=$('gate'),lpb=$('lpb'),up=D.querySelector('#gate [data-g=up]'),inn=D.querySelector('#gate [data-g=in]');
 if(gate&&lpb&&up&&inn){
  var base=up.textContent.trim();
  function gs(){var isUp=up.classList.contains('on');lpb.hidden=up.hidden;gate.classList.toggle('up',isUp);var pl=lpb.parentNode;pl.classList.remove('sw');void pl.offsetWidth;pl.classList.add('sw');
   $('rph').textContent=isUp?base:'Login';$('lph').textContent=isUp?'Welcome back!':'Hello, Welcome';
   $('lpp').textContent=isUp?'Already have an account?':"Don't have an account?";lpb.textContent=isUp?'Login':'Register'}
  var mo=new MutationObserver(gs);mo.observe(up,{attributes:true});mo.observe(inn,{attributes:true});gs();
  lpb.onclick=function(){(up.classList.contains('on')?inn:up).click()};
 }
 /* store sign-in dialog */
 var ad=$('ad'),rf=$('rf'),ar=$('adreg');
 if(ad&&rf&&ar){
  function as(){var isUp=!rf.hidden;ad.classList.toggle('up',isUp);var pl=ar.parentNode;pl.classList.remove('sw');void pl.offsetWidth;pl.classList.add('sw');$('adh').textContent=isUp?'Create account':'Login';
   $('adh2').textContent=isUp?'Welcome back!':'Hello, Welcome';$('adp').textContent=isUp?'Already have an account?':"Don't have an account?";ar.textContent=isUp?'Login':'Register'}
  new MutationObserver(as).observe(rf,{attributes:true});as();
  ar.onclick=function(){if(rf.hidden){var b=D.querySelector('[data-o=reg]');if(b)b.click()}else if(window.rmode)rmode(false)};
 }
 /* live clock (admin + worker) */
 var ck=$('clk');
 if(ck){var fm=new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit',second:'2-digit'});
  function tick(){ck.textContent=fm.format(new Date())}tick();setInterval(tick,1000)}
})();
