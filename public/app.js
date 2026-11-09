const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)],B=document.body;
const e=s=>String(s??'').replace(/[&<>"']/g,c=>'&#'+c.charCodeAt(0)+';');
let SITE={name:'Store',currency:'KSh',phone:'',email:'',depts:[],dept_images:{}},D=['All'];
const num=n=>Number(n).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
const cur=()=>String(SITE.currency||'KSh').trim();
const m=n=>cur()+' '+num(n),mh=n=>cur()+num(n);
const EMPTY={items:[],total:0,count:0};
let cart=EMPTY,qty={},prods=null,allp=[],dept='All',q='',user=null;
const LABEL={'Footwear':'Safety Footwear'};
const CAT_ORDER=['Workwear','Footwear','Head & Face','Gloves','Site Safety','Tools & Hardware'];
async function api(a,body,extra=''){
 const r=await fetch('api.php?action='+a+extra,body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});
 const j=await r.json().catch(()=>({error:'Unexpected response from the server.'}));
 if(!r.ok)throw new Error(j.error||'Request failed.');
 return j;
}
const st=i=>`<span class="st"><button data-a="dec" data-i="${i}" aria-label="Remove one">−</button><b>${qty[i]}</b><button data-a="inc" data-i="${i}" aria-label="Add one">+</button></span>`;
const cl=()=>B.classList.remove('m-menu','m-cart');
function toast(t){const x=$('#toast');x.textContent=t;x.classList.add('on');clearTimeout(toast.h);toast.h=setTimeout(()=>x.classList.remove('on'),3400)}
const stockTag=p=>p.stock>5?'<span class="badge">In Stock</span>':p.stock>0?`<span class="badge low">Only ${p.stock} left</span>`:'<span class="badge out">Out of Stock</span>';
const cartBtn=p=>!p.stock?'<button class="addc" disabled>Out of Stock</button>':qty[p.id]?`<div class="stw">${st(p.id)}</div>`:`<button class="addc" data-a="add" data-i="${p.id}"><svg class="ic sm"><use href="#i-cart"/></svg> Add to Cart</button>`;
function card(p,c){
 return `<article class="pc"><div class="im">${c&&p.stock>0?'':stockTag(p)}${p.was?'<em class="sale">Sale</em>':''}${p.image?`<img src="${e(p.image)}" alt="${e(p.name)}" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'em',textContent:'${e(p.emoji||'📦')}'}))">`:`<span class="em">${e(p.emoji||'📦')}</span>`}</div>
 <h3>${e(p.name)}</h3><div class="pr"><b>${m(p.price)}</b>${p.was?`<s>${m(p.was)}</s>`:''}</div>${p.descr&&!c?`<p class="ds">${e(p.descr)}</p>`:''}${cartBtn(p)}</article>`;
}
let bestList=[];
async function loadBest(){
 try{const r=await api('best');bestList=r.items||[]}catch(x){bestList=[]}
 $('#best').hidden=!bestList.length;if(bestList.length)$('#bgrid').innerHTML=bestList.map(p=>card(p,1)).join('');
}
function cats(){
 const have=SITE.depts,list=[...CAT_ORDER.filter(d=>have.includes(d)),...have.filter(d=>!CAT_ORDER.includes(d))].slice(0,6);
 $('#cats').innerHTML=list.map(d=>{const img=(SITE.dept_images||{})[d];return `<button class="cat" data-d="${e(d)}"><span class="ci2">${img?`<img src="${e(img)}" alt="" loading="lazy" onerror="this.remove()">`:''}</span><span class="cn">${e(LABEL[d]||d)}<i><svg class="ic sm"><use href="#i-arrow"/></svg></i></span></button>`}).join('');
 $('.cats').hidden=!list.length;
}
async function rg(){
 try{prods=await api('products',null,'&q='+encodeURIComponent(q)+'&dept='+encodeURIComponent(dept))}
 catch(x){prods=null;$('#grid').innerHTML='<p class="empty">Couldn’t load products: '+e(x.message)+' Check that Apache and PostgreSQL are running and that you opened the site through http://localhost/.</p>';return}
 if(dept=='All'&&!q){allp=prods}
 draw();
}
function draw(){
 if(!prods)return;
 $('#gt').textContent=q?`Results for “${q}”`:dept=='All'?(C().all_title||'All Products'):(LABEL[dept]||dept);
 $('#clr').hidden=!q&&dept=='All';
 $('#chips').innerHTML=D.map(d=>`<button class="chip${d==dept&&!q?' on':''}" data-d="${e(d)}">${d=='All'?'All':e(LABEL[d]||d)}</button>`).join('');
 $('#grid').innerHTML=prods.length?prods.map(p=>card(p)).join(''):'<p class="empty">No items match your search. Try another word or department.</p>';
  if(bestList.length)$('#bgrid').innerHTML=bestList.map(p=>card(p,1)).join('');
}
function setCart(c){
 cart=c;qty={};c.items.forEach(i=>qty[i.id]=i.qty);
 $('#cn').textContent=c.count;$('#cn').hidden=!c.count;$('#ctot').textContent=mh(c.total);$('#sub').textContent=m(c.total);$('#co').disabled=!c.count;
 $('#cl').innerHTML=c.items.length?c.items.map(i=>`<li class="ci"><span class="th">${i.image?`<img src="${e(i.image)}" alt="" onerror="this.remove()">`:`<span>${e(i.emoji||'📦')}</span>`}</span><div><span class="nm">${e(i.name)}</span>${m(i.price)}</div>${st(i.id)}<button class="rm" data-a="rm" data-i="${i.id}" aria-label="Remove item">✕</button></li>`).join(''):'<li class="ci empty">Your cart is empty. Add items to get started.</li>';
 draw();
}
async function setQty(id,n){try{setCart(await api('cart',{product_id:id,qty:n}));if(n==1)toast('Added to cart')}catch(x){toast(x.message)}}
function ru(){
 $('#bk')?.remove(); // the public store never shows a link back to the admin or worker pages
 $('#au').textContent=user?user.name.split(' ')[0].slice(0,14):'Sign in';window.nfRefresh&&nfRefresh();
 $$('.am').forEach(b=>b.textContent=user?'Sign out':'Sign in or create account');
}
function rmode(on){
 $('#rf').hidden=!on;$('#rn').required=on;$('[data-o=reg]').textContent=on?'Create my account':'Create account';$('#sib').hidden=on;$('#ae').textContent='';
 $('#adh').textContent=on?'Registration':'Login';$('#adh2').textContent=on?'Welcome Back!':'Hello, Welcome';$('#adp').textContent=on?'Already have an account?':"Don't have an account?";$('#adreg').textContent=on?'Login':'Register';
 $('#pw').autocomplete=on?'new-password':'current-password';
}
async function auth(a){
 $('#ae').textContent='';
 try{const r=await api(a,{email:$('#em').value,password:$('#pw').value,name:$('#rn').value,phone:$('#rp').value,remember:!!($('#af').elements.remember&&$('#af').elements.remember.checked)});user=r.user;if(r.cart)setCart(r.cart);if(a==='register')await dpAfterSignup('#rph');$('#pw').value='';$('#ad').close();rmode(false);ru();toast('Welcome, '+user.name)}
 catch(x){$('#ae').textContent=x.message}
}
const phones=()=>[SITE.phone,SITE.phone2].filter(Boolean);
const telOf=p=>'tel:'+p.replace(/[^+\d]/g,'');
const C=()=>SITE.content||{};
const waNum=()=>((C().whatsapp||SITE.phone)||'+254721373107').replace(/\D/g,'');
const FAQ={
 contact:['Contact Us',()=>`<p>We’re happy to help with orders, quotations and delivery questions.</p>${phones().map(p=>`<p><b>Call:</b> <a href="${telOf(p)}">${e(p)}</a></p>`).join('')}<p><a target="_blank" rel="noopener" href="https://wa.me/${waNum()}">Chat on WhatsApp</a></p>${SITE.email?`<p><b>Email:</b> <a href="mailto:${e(SITE.email)}">${e(SITE.email)}</a></p>`:''}${SITE.address?`<p><b>Visit:</b> ${e(SITE.address)}</p>`:''}<p><b>Hours:</b> Mon – Fri 8:00 AM – 5:00 PM, Sat 8:00 AM – 2:00 PM, Sunday closed.</p>`],
 about:['About Us',()=>`<p><b>${e(SITE.name)}</b> supplies genuine PPE, workwear, safety footwear, gloves, signs, tools and branding for businesses, institutions and individuals.</p><p>Visit us in Nairobi or order online for delivery.</p>`],
 ship:['Shipping & Delivery',()=>'<p>We deliver within Nairobi and countrywide. You can also choose in-store pickup at checkout. We contact you to confirm the delivery details and timing after you place an order.</p>'],
 ret:['Returns & Refunds',()=>'<p>If an item is wrong or faulty, contact us on the phone number or WhatsApp below as soon as you receive it and we will sort it out with you.</p>'],
 pay:['Payment Methods',()=>'<p>Orders are paid on delivery or on pickup. Choose <b>Cash on delivery</b> or <b>Pay on pickup</b> at checkout.</p>'],
 bulk:['Bulk Orders',()=>'<p>Buying for a team, company or institution? Use <b>Request a Quote</b> and tell us what you need. We can also add custom branding and embroidery on workwear.</p>']
};
const paras=t=>String(t).split(/\n\s*\n|\n/).filter(x=>x.trim()).map(x=>`<p>${e(x.trim())}</p>`).join('');
function faq(k){const f=FAQ[k];if(!f)return;const ov=C()['faq_'+k];$('#fdh').textContent=f[0];$('#fdb').innerHTML=(ov?paras(ov):f[1]())+(k=='contact'?'':`<p class="fc">Call ${phones().map(p=>`<a href="${telOf(p)}">${e(p)}</a>`).join(' or ')} or <a target="_blank" rel="noopener" href="https://wa.me/${waNum()}">chat on WhatsApp</a>.</p>`);$('#fd').showModal()}
document.addEventListener('click',async ev=>{
 const dd=ev.target.closest('[data-dd]');
 $$('.dd.open').forEach(x=>{if(!dd||x!=dd.parentElement)x.classList.remove('open')});
 if(dd)dd.parentElement.classList.toggle('open');
 const fq=ev.target.closest('[data-faq]');
 if(fq){ev.preventDefault();faq(fq.dataset.faq);return}
 if(ev.target.closest('[data-q]')){ev.preventDefault();cl();$('#qd').showModal();return}
 const t=ev.target.closest('[data-a],[data-d],[data-o]');
 if(!t){if(ev.target.closest('#fct')){ev.preventDefault();faq('contact')}return}
 if(t.tagName=='A')ev.preventDefault();
 const{a,d,o,i}=t.dataset;
 if(a){const id=+i,c=qty[id]||0;setQty(id,a=='add'?1:a=='inc'?c+1:a=='dec'?c-1:0);return}
 if(o!='menu'&&o!='cart')cl();
 if(d!=null){dept=d;q='';$('#q').value='';rg();$('#prod').scrollIntoView({behavior:'smooth'})}
 else if(o=='menu')B.classList.add('m-menu');
 else if(o=='cart')B.classList.add('m-cart');
 else if(o=='auth'){if(user){try{await api('logout',{})}catch(x){}user=null;ru();setCart(EMPTY);toast('Signed out')}else{rmode(false);$('#ad').showModal()}}
 else if(o=='reg'){if($('#rf').hidden){rmode(true);$('#rn').focus()}else if($('#af').reportValidity())auth('register')}
 else if(o=='dx')t.closest('dialog').close();
 else if(o=='home'){dept='All';q='';$('#q').value='';rg();scrollTo({top:0,behavior:'smooth'})}
 else if(o=='co'){
  if(!user){rmode(false);$('#ad').showModal();toast('Sign in to check out.')}
  else{$('#ks').textContent=`${cart.count} item${cart.count==1?'':'s'} · ${m(cart.total)}`;$('#kf').elements.name.value=user.name;$('#ke').textContent='';$('#kd').showModal()}
 }
 else if(o=='mp'){$('#od').close();mpesaOpen(+t.dataset.id,+t.dataset.amt,'')}
 else if(o=='orders'){
  if(!user){rmode(false);$('#ad').showModal();return}
  try{const r=await api('orders');$('#ol').innerHTML=r.length?r.map(x=>`<div class="or"><span>#${x.id} · ${e(x.at)} · ${e(x.status)} · ${x.items} item${x.items==1?'':'s'}${x.mpesa_due?`<br><button type="button" class="btn red" data-o="mp" data-id="${x.id}" data-amt="${x.total}">Pay ${m(x.total)} with M-Pesa</button>`:''}${x.status=='confirmed'||x.status=='dispatched'||x.status=='delivered'?(x.paid?' · <b class="pdok">Paid ✓</b>':x.pay_due?'<br><b class="pdue">Please pay '+m(x.total)+' to Till Number '+e(x.till)+'</b><br><small>M-PESA › Lipa na M-PESA › Buy Goods and Services. We dispatch once payment is confirmed.</small>':''):''}</span><b>${m(x.total)}</b></div>`).join(''):'<p>No orders yet.</p>';$('#od').showModal()}catch(x){toast(x.message)}
 }
});
document.addEventListener('keydown',ev=>{if(ev.key=='Escape'){cl();$$('.dd.open').forEach(x=>x.classList.remove('open'))}});
$('#adreg').onclick=()=>{rmode($('#rf').hidden);($('#rf').hidden?$('#em'):$('#rn')).focus()};
$$('dialog').forEach(d=>d.addEventListener('click',ev=>{if(ev.target===d)d.close()}));
$('#sf').onsubmit=ev=>{ev.preventDefault();q=$('#q').value.trim();dept='All';rg();$('#prod').scrollIntoView({behavior:'smooth'})};
$('#af').onsubmit=ev=>{ev.preventDefault();auth($('#rf').hidden?'login':'register')};
$('#kf').onsubmit=async ev=>{
 ev.preventDefault();$('#ke').textContent='';
 try{const f=Object.fromEntries(new FormData(ev.target)),r=await api('checkout',f);$('#kd').close();ev.target.reset();setCart(EMPTY);rg();$('#kmw').hidden=true;
  if(f.payment=='mpesa')mpesaOpen(r.order_id,r.total,f.mpesa_phone||f.phone,true);else toast(`Order #${r.order_id} placed (${m(r.total)}). We’ll contact you to confirm.`)}
 catch(x){$('#ke').textContent=x.message}
};
$('#qf').onsubmit=ev=>{
 ev.preventDefault();const f=Object.fromEntries(new FormData(ev.target));
 const txt=`Quote request from ${f.who}\nPhone: ${f.phone}${f.email?'\nEmail: '+f.email:''}\n\n${f.need}`;
 window.open('https://wa.me/'+waNum()+'?text='+encodeURIComponent(txt),'_blank','noopener');
 api('q_request',{who:f.who,phone:f.phone,email:f.email||'',need:f.need,website:f.website||''}).then(()=>toast('Quote request received. We’ll get back to you soon.')).catch(()=>{});
 $('#qd').close();ev.target.reset();
};
$('#nl').onsubmit=ev=>{
 ev.preventDefault();const em=$('#nle').value.trim();
 if(SITE.email){location.href='mailto:'+SITE.email+'?subject='+encodeURIComponent('Newsletter signup')+'&body='+encodeURIComponent('Please add me to your newsletter: '+em)}
 else toast('Thanks! We’ll be in touch.');ev.target.reset();
};
function applyContent(){
 const c=C(),T=(id,v)=>{if(v)$(id).textContent=v};
 T('#hT1',c.hero_t1);T('#hT2',c.hero_t2);T('#hSub',c.hero_sub);T('#hB1',c.hero_btn1);T('#hB2',c.hero_btn2);T('#hS1',c.hero_s1);T('#hS2',c.hero_s2);
 for(let i=1;i<=4;i++){if(c['hero_img'+i]){let im=$('#hImg'+i);if(!im){im=document.createElement('img');im.id='hImg'+i;im.className='h'+i+'i';im.alt='';$('.hv').prepend(im)}im.src=c['hero_img'+i]}}
 for(let i=1;i<=4;i++){T('#tr'+i+'t',c['trust'+i+'_t']);T('#tr'+i+'s',c['trust'+i+'_s'])}
 T('#bT',c.best_title);T('#qH',c.quote_h);T('#qP',c.quote_p);T('#qBtn',c.quote_btn);for(let i=1;i<=3;i++)T('#qb'+i,c['quote_b'+i]);
 if(c.quote_img)$('#qImg').src=c.quote_img;
 T('#fTag',c.footer_tag);T('#nBlurb',c.news_blurb);if(c.search_ph)$('#q').placeholder=c.search_ph;
 if(c.hours){const rows=c.hours.split(/\n/).map(l=>l.split('|').map(x=>x.trim())).filter(r=>r.length>1&&r[0]);if(rows.length)$('#hrs').innerHTML=rows.map(r=>`<dt>${e(r[0])}</dt><dd>${e(r[1])}</dd>`).join('')}
}
async function init(){
 try{SITE=await api('site')}catch(x){}
 if(SITE.mpesa){const o=$('#kmp');if(o){o.hidden=false;o.disabled=false}}
 D=['All',...SITE.depts];applyContent();
 document.title=SITE.name+' | Safety gear and workwear';
 $('#fn').textContent=SITE.name;
 const tel=SITE.phone?'tel:'+SITE.phone.replace(/[^+\d]/g,''):'';
 if(SITE.phone){$('#ph').href=tel;$('#phn').textContent=SITE.phone;$('#ph').hidden=false;$('#fph').href=tel;$('#fph').textContent=SITE.phone;$('#fwa').href='https://wa.me/'+waNum();$('#wafab').href='https://wa.me/'+waNum()}
 if(SITE.phone2){$('#fph2').href=telOf(SITE.phone2);$('#fph2').textContent=SITE.phone2;$('#fph2').hidden=false}
 if(SITE.email){$('#fem').href='mailto:'+SITE.email;$('#fem').textContent=SITE.email}else $('.em').hidden=true;
 const br=SITE.branches||[];
 if(br.length){
  $('#floc').innerHTML=`<b>${e(br[0].name.replace(/\s*\(.*\)/,''))}</b><br>${e(br[0].address)}`;
  const u='https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(br[0].address);$('#fdir').href=u;$('#fmap').href=u;
  $('#fbr').innerHTML=br.map(b=>`<li><a target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(b.address)}">${e(b.name.replace(/\s*\(.*\)/,''))}</a></li>`).join('');
 }else{$('#fbr').closest('.col').hidden=true}
 $$('.dl').forEach(x=>x.innerHTML=D.map(d=>`<button data-d="${e(d)}">${d=='All'?'All departments':e(LABEL[d]||d)}</button>`).join(''));
 $('#nv2').innerHTML=SITE.depts.map(d=>`<button class="nb" data-d="${e(d)}">${e(d)}</button>`).join('');
 cats();ru();
 try{const r=await api('me');user=r.user;ru();setCart(r.cart)}catch(x){}
 rg();loadBest();
 // Best Sellers follow what the admin picks: re-read when the visitor returns to the tab and every 5 minutes.
 setInterval(()=>{if(!document.hidden)loadBest()},3e5);
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)loadBest()});
}
init();

// ---- M-Pesa STK Push: the prompt appears on the customer's phone; this dialog waits for the result ----
let mpOrder=0,mpTimer=0;
function mpesaStop(){clearTimeout(mpTimer);mpTimer=0}
function mpesaOpen(id,amt,phone,send){
 mpOrder=id;mpesaStop();$('#me').textContent='';$('#mt').textContent=`Order #${id} · ${m(amt)}`;$('#mph').value=phone||'';
 $('#mpw').hidden=!!send;$('#mgo').hidden=!!send;$('#mgo').disabled=false;$('#md').showModal();
 if(send)mpesaSend();
}
async function mpesaSend(){
 $('#me').textContent='';$('#mgo').disabled=true;$('#mt').textContent='Sending the request to your phone…';
 try{const r=await api('m_pay',{order_id:mpOrder,phone:$('#mph').value.trim()});$('#mpw').hidden=true;$('#mgo').hidden=true;$('#mt').textContent=`Check your phone (${r.phone||''}) and enter your M-Pesa PIN. Do not close this window.`;mpesaPoll(0)}
 catch(x){$('#mt').textContent='We could not start the payment.';$('#me').textContent=x.message;$('#mpw').hidden=false;$('#mgo').hidden=false;$('#mgo').disabled=false;$('#mgo').textContent='Try again'}
}
async function mpesaPoll(n){
 if(!$('#md').open)return;
 try{
  const r=await api('m_status',null,'&order_id='+mpOrder);
  if(r.state=='success'){$('#mt').textContent=r.message+(r.receipt?` M-Pesa code: ${r.receipt}.`:'');$('#me').textContent='';toast('Payment received. Thank you!');return}
  if(r.state=='failed'){$('#mt').textContent='Payment not completed.';$('#me').textContent=r.message;$('#mpw').hidden=false;$('#mgo').hidden=false;$('#mgo').disabled=false;$('#mgo').textContent='Try again';return}
 }catch(x){}
 if(n>=60){$('#mt').textContent='Still waiting. If you paid, your order will update shortly; check My orders.';return}
 mpTimer=setTimeout(()=>mpesaPoll(n+1),3000);
}
$('#mgo').onclick=mpesaSend;
$('#mx').onclick=()=>{mpesaStop();$('#md').close()};
$('#md').addEventListener('close',mpesaStop);
$('#kf').elements.payment.addEventListener('change',ev=>{$('#kmw').hidden=ev.target.value!='mpesa'});
