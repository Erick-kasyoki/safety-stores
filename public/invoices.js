// Invoices tab of admin.html. Uses the helpers defined there: $, e, api, m, S, P.
const PS={draft:'Draft',unpaid:'Unpaid',partial:'Part paid',overdue:'Overdue',paid:'Paid',void:'Void'};
const FQ={weekly:'week',monthly:'month',quarterly:'quarter (3 months)',yearly:'year'};
const OPEN=['unpaid','partial','overdue'];
const VM={all:()=>true,unpaid:p=>OPEN.includes(p),overdue:p=>p=='overdue',paid:p=>p=='paid',draft:p=>p=='draft',void:p=>p=='void'};
let V=null,VF='all',VQ='',EI=[];

const vtoday=()=>{const d=new Date();return new Date(d-d.getTimezoneOffset()*6e4).toISOString().slice(0,10)};
const vadd=(s,n)=>{const d=new Date(s+'T00:00:00Z');d.setUTCDate(d.getUTCDate()+n);return d.toISOString().slice(0,10)};
const vdate=s=>new Date(s+'T00:00:00').toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'});
const vbd=s=>`<span class="bd v-${s}">${PS[s]}</span>`;
const vurl=(t,x='')=>new URL('invoice.php?t='+t+x,location.href).href;
const vblank=()=>({product_id:null,description:'',qty:1,unit_price:0});
const vline=i=>Math.round((+i.qty||0)*(+i.unit_price||0)*100)/100;
const vmsg=i=>`Hello ${i.customer_name},\n\nHere is invoice ${i.number} from ${S.name} for ${m(i.total)}`+(i.paid>0?` (balance due ${m(i.balance)})`:'')+`, due ${vdate(i.due_date)}.\n\nYou can view and download it here:\n${vurl(i.token)}\n\nThank you.`;

async function lv(){V=await api('a_invoices');dv()}

function dv(){
 if(!V)return;const t=V.totals,q=VQ.toLowerCase();
 $('#vk').innerHTML=[['unpaid','Outstanding',m(t.outstanding)],['overdue','Overdue',m(t.overdue)+(t.overdue_n?' · '+t.overdue_n:''),t.overdue_n?'bad':''],['paid','Collected this month',m(t.collected_month)],['draft','Drafts',t.drafts]]
  .map(([f,l,v,c])=>`<button class="kc ${c||''}" data-vf="${f}"><small>${l}</small><b>${e(v)}</b></button>`).join('');
 $('#vc').innerHTML=[['all','All'],['unpaid','Unpaid'],['overdue','Overdue'],['paid','Paid'],['draft','Draft'],['void','Void']]
  .map(([k,l])=>`<button class="chip${VF==k?' on':''}" data-vf="${k}">${l}</button>`).join('');
 const L=V.items.filter(i=>VM[VF](i.pstatus)&&(!q||(i.number+' '+i.customer_name).toLowerCase().includes(q)));
 $('#vt').innerHTML='<tr><th>Invoice</th><th>Customer</th><th>Due</th><th>Total</th><th>Balance</th><th>Status</th><th></th></tr>'+(L.map(i=>{
  const open=OPEN.includes(i.pstatus),live=i.pstatus!='draft'&&i.pstatus!='void';
  return `<tr><td><b>${e(i.number)}</b><br><small>${vdate(i.issue_date)}${i.recurring_id?' · repeating':''}${i.order_id?' · order #'+i.order_id:''}</small></td>
<td>${e(i.customer_name)}</td><td>${vdate(i.due_date)}</td><td>${m(i.total)}</td><td>${live?m(i.balance):'–'}</td><td>${vbd(i.pstatus)}</td>
<td class="vac"><a class="xs" href="${vurl(i.token)}" target="_blank" rel="noopener">View</a>
${i.pstatus!='void'?` <button class="xs" data-ve="${i.id}">Edit</button>`:''}${open?` <button class="xs" data-vp="${i.id}">Record payment</button>`:''}${i.pstatus=='draft'?` <button class="xs" data-vs="${i.id}">Mark as sent</button>`:''}
 <select class="xs" data-vm="${i.id}" aria-label="More actions for ${e(i.number)}"><option value="">More…</option><option value="pdf">Download PDF</option>${live?'<option value="email">Send by email</option><option value="wa">Send by WhatsApp</option>':''}${live||i.pstatus=='draft'?'<option value="void">Void invoice</option>':''}${i.pstatus=='draft'?'<option value="del">Delete draft</option>':''}</select></td></tr>`}).join('')||'<tr><td colspan="7">No invoices here yet.</td></tr>');
 $('#vr').innerHTML=V.recurring.length?'<tr><th>Customer</th><th>Repeats</th><th>Amount</th><th>Next invoice</th><th></th></tr>'+V.recurring.map(r=>`<tr><td>${e(r.customer_name)}</td><td>Every ${FQ[r.frequency]}</td><td>${m(r.amount)}</td><td>${r.active?vdate(r.next_date):'<i>Paused</i>'}</td><td><button class="xs" data-rt="${r.id}" data-on="${r.active?0:1}">${r.active?'Pause':'Resume'}</button> <button class="xs" data-rd="${r.id}">Delete</button></td></tr>`).join(''):'<tr><td>No repeating invoices yet. Tick “Repeat this invoice automatically” when you create one.</td></tr>';
}

// ---- invoice editor ----
async function vedit(id){
 const f=$('#vf');f.reset();$('#ve').textContent='';
 const v=id?await api('a_invoice',null,'&id='+id):null,t=vtoday(),el=f.elements;
 el.id.value=id||'';
 for(const k of ['customer_name','customer_email','customer_phone','customer_address','issue_date','due_date','tax_rate','discount','notes'])
  el[k].value=v?v[k]:k=='issue_date'?t:k=='due_date'?vadd(t,S.due_days??14):k=='tax_rate'?(S.default_tax||0):k=='discount'?0:'';
 EI=v?v.items.map(i=>({...i})):[vblank()];
 $('#vdt').textContent=v?'Edit invoice '+v.number:'New invoice';
 $('#vtn').textContent=S.tax_name||'Tax';
 $('#vrep').hidden=!!v;$('#vrc').checked=false;$('#vrf').hidden=true;
 $('#vp').innerHTML='<option value="">+ Add a product from your store…</option>'+P.map(p=>`<option value="${p.id}">${e(p.name)} · ${m(p.price)}</option>`).join('');
 $('#vs0').hidden=!!v&&v.status!='draft';$('#vs1').textContent=!v||v.status=='draft'?'Save & mark as sent':'Save changes';
 vrows();vtot();$('#vd').showModal();
}
function vrows(){
 $('#vi').innerHTML='<tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Amount</th><th></th></tr>'+EI.map((it,n)=>`<tr>
<td><input data-ln="${n}" data-lk="description" value="${e(it.description)}" maxlength="200" placeholder="Item or service" aria-label="Description"></td>
<td><input data-ln="${n}" data-lk="qty" type="number" min="0.01" step="0.01" value="${it.qty}" aria-label="Quantity"></td>
<td><input data-ln="${n}" data-lk="unit_price" type="number" min="0" step="0.01" value="${it.unit_price}" aria-label="Unit price"></td>
<td class="vam" id="va${n}">${m(vline(it))}</td><td><button type="button" class="xs" data-vdel="${n}" aria-label="Remove line">✕</button></td></tr>`).join('');
}
function vtot(){
 const el=$('#vf').elements,sub=EI.reduce((a,i)=>a+Math.round((+i.qty||0)*(+i.unit_price||0)*100),0);
 const dc=Math.min(Math.round((+el.discount.value||0)*100),sub),net=sub-dc,tx=Math.round(net*(+el.tax_rate.value||0)/100);
 $('#vtot').innerHTML=`Subtotal ${m(sub/100)}${dc?` · Discount −${m(dc/100)}`:''}${tx?` · ${e(S.tax_name||'Tax')} ${m(tx/100)}`:''} · <b>Total ${m((net+tx)/100)}</b>`;
}

// ---- payments ----
async function vpay(id){
 const i=V.items.find(x=>x.id==id),f=$('#yf');f.reset();$('#ye').textContent='';
 const v=await api('a_invoice',null,'&id='+id);
 $('#ym').innerHTML=V.methods.map(x=>`<option>${e(x)}</option>`).join('');
 f.elements.id.value=id;f.elements.amount.value=v.balance>0?v.balance.toFixed(2):'';f.elements.amount.max=v.balance;f.elements.paid_on.value=vtoday();
 $('#yt').textContent='Record payment · '+v.number;
 $('#yi').textContent=`${v.customer_name}. Total ${m(v.total)}, paid so far ${m(v.paid)}, balance ${m(v.balance)}.`;
 $('#yh').innerHTML=v.payments.length?'<table class="tb"><tr><th>Received</th><th>Method</th><th>Amount</th><th></th></tr>'+v.payments.map(p=>`<tr><td>${vdate(p.paid_on)}</td><td>${e(p.method)}${p.note?'<br><small>'+e(p.note)+'</small>':''}</td><td>${m(p.amount)}</td><td><button type="button" class="xs" data-yd="${p.id}" aria-label="Remove this payment">Remove</button></td></tr>`).join('')+'</table>':'';
 if(!$('#yd').open)$('#yd').showModal();
 f.elements.amount.focus();
}

// ---- events ----
document.addEventListener('click',async ev=>{
 const t=ev.target.closest('button');if(!t)return;const d=t.dataset;
 try{
  if(d.vf){VF=d.vf;dv()}
  else if(t.id=='vn')await vedit(null);
  else if(d.ve)await vedit(+d.ve);
  else if(d.vp)await vpay(+d.vp);
  else if(d.vs){await api('a_inv_status',{id:+d.vs,status:'sent'});await lv()}
  else if(d.rt){await api('a_rec_toggle',{id:+d.rt,active:d.on=='1'});await lv()}
  else if(d.rd){if(confirm('Stop and delete this repeating invoice? Invoices already created are kept.')){await api('a_rec_delete',{id:+d.rd});await lv()}}
  else if(d.oi){const r=await api('a_inv_from_order',{order_id:+d.oi});document.querySelector('.tabs [data-t=v]').click();await vedit(r.id)}
  else if(d.vdel){EI.splice(+d.vdel,1);if(!EI.length)EI.push(vblank());vrows();vtot()}
  else if(d.yd){if(confirm('Remove this payment?')){const id=$('#yf').elements.id.value;await api('a_inv_unpay',{id:+d.yd});await lv();await vpay(+id)}}
  else if(t.id=='vl'){EI.push(vblank());vrows();vtot();const r=document.querySelectorAll('#vi [data-lk=description]');r[r.length-1].focus()}
  else if(t.id=='vx')$('#vd').close();
  else if(t.id=='yx')$('#yd').close();
 }catch(x){alert(x.message)}
});
document.addEventListener('input',ev=>{
 const t=ev.target;
 if(t.id=='vq'){VQ=t.value;dv();return}
 if(t.dataset.lk){const it=EI[+t.dataset.ln];it[t.dataset.lk]=t.dataset.lk=='description'?t.value:(t.value===''?0:+t.value);$('#va'+t.dataset.ln).textContent=m(vline(it));vtot();return}
 if(t.name=='discount'||t.name=='tax_rate')vtot();
});
document.addEventListener('change',async ev=>{
 const t=ev.target;
 if(t.id=='vrc'){$('#vrf').hidden=!t.checked;return}
 if(t.id=='vp'){
  const p=P.find(x=>x.id==t.value);t.value='';if(!p)return;
  if(EI.length==1&&!EI[0].description&&!+EI[0].unit_price)EI=[];
  EI.push({product_id:p.id,description:p.name,qty:1,unit_price:p.price});vrows();vtot();return}
 const id=t.dataset.vm;if(!id)return;
 const a=t.value;t.value='';const i=V.items.find(x=>x.id==id);if(!i||!a)return;
 try{
  if(a=='pdf')window.open(vurl(i.token,'&print=1'),'_blank','noopener');
  else if(a=='email')location.href='mailto:'+i.customer_email+'?subject='+encodeURIComponent('Invoice '+i.number+' from '+S.name)+'&body='+encodeURIComponent(vmsg(i));
  else if(a=='wa'){const ph=i.customer_phone.trim().startsWith('+')?i.customer_phone.replace(/\D/g,''):'';window.open('https://wa.me/'+ph+'?text='+encodeURIComponent(vmsg(i)),'_blank','noopener')}
  else if(a=='void'){if(confirm('Void invoice '+i.number+'? It stays on record but no longer counts as money owed, and can’t be reopened.')){await api('a_inv_status',{id:+id,status:'void'});await lv()}}
  else if(a=='del'){if(confirm('Delete draft '+i.number+'?')){await api('a_inv_delete',{id:+id});await lv()}}
 }catch(x){alert(x.message)}
});
$('#vf').onsubmit=async ev=>{
 ev.preventDefault();$('#ve').textContent='';
 const b=Object.fromEntries(new FormData(ev.target));
 b.items=EI;b.send=!!ev.submitter&&ev.submitter.dataset.send=='1';b.repeat=!$('#vrep').hidden&&$('#vrc').checked?b.frequency:'';
 try{await api('a_inv_save',b);$('#vd').close();await lv()}catch(x){$('#ve').textContent=x.message}
};
$('#yf').onsubmit=async ev=>{
 ev.preventDefault();$('#ye').textContent='';
 try{await api('a_inv_pay',Object.fromEntries(new FormData(ev.target)));$('#yd').close();await lv()}catch(x){$('#ye').textContent=x.message}
};
