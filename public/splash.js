// Shows the store logo with a small animation while the page loads, then fades it out.
(function(){
 var s=document.createElement('div');s.id='splash';s.setAttribute('aria-hidden','true');
 s.innerHTML='<img src="img/logo.png" alt=""><i></i>';
 document.documentElement.appendChild(s);
 var t0=Date.now(),done=false;
 function hide(){if(done)return;done=true;var w=Math.max(0,900-(Date.now()-t0));setTimeout(function(){s.classList.add('off');setTimeout(function(){s.remove()},600)},w)}
 if(document.readyState==='complete')hide();else window.addEventListener('load',hide);
 setTimeout(hide,4000);
})();
