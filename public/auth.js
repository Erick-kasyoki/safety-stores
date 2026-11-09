/* Login + sign-up look shared by the store, admin and worker pages:
   - an animated character beside the form (covers its eyes while a password is typed)
   - lock icon + show/hide eye on every password box
   - a "Remember me" tick box on every login and sign-up form
   Loaded after the page's own scripts; it only decorates the existing forms, so their ids and submit code stay as they were. */
(function(){
 var D=document;
 var ICON={
  mail:'<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7.5 8 6 8-6"/>',
  lock:'<rect x="5" y="10.5" width="14" height="9.5" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>',
  user:'<circle cx="12" cy="8.2" r="3.8"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
  phone:'<path d="M6.6 3.5h2.6l1.4 4-1.8 1.3a11 11 0 0 0 5.4 5.4l1.3-1.8 4 1.4v2.6a2 2 0 0 1-2.1 2A15.5 15.5 0 0 1 4.6 5.6a2 2 0 0 1 2-2.1z"/>'
 };
 var EYE='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
 var EYEOFF='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 3.5 20.5 20.5"/><path d="M6.4 7.2A16.5 16.5 0 0 0 2 12s3.6 6.5 10 6.5c1.7 0 3.2-.4 4.5-1"/><path d="M9.9 5.7c.7-.1 1.4-.2 2.1-.2 6.4 0 10 6.5 10 6.5a16.8 16.8 0 0 1-2.6 3.3"/><path d="M10 10a3 3 0 0 0 4 4"/></svg>';
 function ico(n){return '<svg class="af-ic" viewBox="0 0 24 24" aria-hidden="true">'+ICON[n]+'</svg>'}

 /* the picture: a photo of tea being poured, brought to life with CSS only
    (slow camera drift, rising steam, a shimmering stream, ripples in the cup).
    While a password is typed the photo blurs behind a lock; when the password is shown it clears again. */
 var MASCOT='<div class="af-tea">'+
  '<img src="img/tea-pour.jpg?v=1" alt="" width="520" height="853" decoding="async" draggable="false">'+
  '<i class="tea-stream"></i>'+
  '<i class="tea-rip r1"></i><i class="tea-rip r2"></i><i class="tea-rip r3"></i>'+
  '<i class="tea-steam s1"></i><i class="tea-steam s2"></i><i class="tea-steam s3"></i>'+
  '<svg class="tea-lock" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/></svg>'+
 '</div>';

 function hostOf(el){return el.closest('dialog.split')||el.closest('#gate')}

 function decorate(form){
  if(!form||form.dataset.afDone)return;form.dataset.afDone='1';
  var host=hostOf(form);if(!host)return;
  form.querySelectorAll('input').forEach(function(inp){
   var t=(inp.getAttribute('type')||'text').toLowerCase(),n=null;
   if(t=='email')n='mail';else if(t=='password')n='lock';else if(t=='tel')n='phone';
   else if(t=='text'&&(inp.getAttribute('autocomplete')=='name'||/name/i.test(inp.placeholder||'')))n='user';
   if(!n)return;
   var w=D.createElement('span');w.className='af-f';inp.parentNode.insertBefore(w,inp);w.appendChild(inp);
   w.insertAdjacentHTML('afterbegin',ico(n));
   if(t=='password'){
    w.classList.add('af-pw');
    var b=D.createElement('button');b.type='button';b.className='af-eye';b.setAttribute('aria-label','Show password');b.setAttribute('aria-pressed','false');b.innerHTML=EYE;
    b.onclick=function(){
     var show=inp.type=='password';inp.type=show?'text':'password';
     b.innerHTML=show?EYEOFF:EYE;b.setAttribute('aria-pressed',show?'true':'false');b.setAttribute('aria-label',show?'Hide password':'Show password');
     host.classList.toggle('af-show',show);inp.focus();
    };
    w.appendChild(b);
   }
  });
  /* Remember me, placed just above the error line / submit button */
  var rm=D.createElement('label');rm.className='af-rm';rm.innerHTML='<input type="checkbox" name="remember" value="1"><span>Remember me</span>';
  var at=form.querySelector(':scope>[role=alert]')||form.querySelector(':scope>button');
  if(at)form.insertBefore(rm,at);else form.appendChild(rm);
 }

 function setup(host){
  if(!host||host.dataset.afHost)return;host.dataset.afHost='1';host.classList.add('af');
  var pl=host.querySelector('.gpl');
  if(pl)pl.insertAdjacentHTML('afterbegin','<div class="af-mascot" aria-hidden="true">'+MASCOT+'</div>');
  var rfm=D.getElementById('rf');
  if(rfm&&host.matches('dialog')){var sync=function(){host.classList.toggle('af-reg',!rfm.hidden)};new MutationObserver(sync).observe(rfm,{attributes:true,attributeFilter:['hidden']});sync()}
  host.addEventListener('focusin',function(e){host.classList.toggle('af-pwf',!!e.target.closest('.af-pw'))});
  host.addEventListener('focusout',function(e){
   if(!e.target.closest('.af-pw'))return;
   var r=e.relatedTarget;if(r&&r.classList&&r.classList.contains('af-eye'))return;
   host.classList.remove('af-pwf');
  });
  /* hide the password again whenever the store sign-in box is closed */
  host.addEventListener('close',function(){
   host.classList.remove('af-pwf','af-show');
   host.querySelectorAll('.af-pw input').forEach(function(i){i.type='password'});
   host.querySelectorAll('.af-eye').forEach(function(b){b.innerHTML=EYE;b.setAttribute('aria-pressed','false');b.setAttribute('aria-label','Show password')});
  });
 }

 ['af','lf2','rf2','lf','sf2'].forEach(function(id){
  var f=D.getElementById(id);if(!f)return;
  setup(hostOf(f));decorate(f);
 });
})();
