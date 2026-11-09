// Quotations tab (admin.html and worker.html): create, price, send and track quotations, answer customer requests
// from the store, and turn an accepted quotation into an invoice. Uses page helpers: api, m, S, ME.
(() => {
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const adminTabs = $('#adm .tabs'), workerTabs = $('#app .tabs');
  const tabs = adminTabs || workerTabs;
  if (!tabs) return;
  const root = adminTabs ? $('#adm') : $('#app');

  const ST = { request: 'Request', draft: 'Draft', sent: 'Sent', accepted: 'Accepted', declined: 'Declined', expired: 'Expired', void: 'Void' };
  const FILT = { all: () => true, request: s => s == 'request', draft: s => s == 'draft', sent: s => s == 'sent', accepted: s => s == 'accepted', closed: s => ['declined', 'expired', 'void'].includes(s) };
  let Q = null, F = 'all', QS = '', LN = [], PR = [];

  const today = () => { const d = new Date(); return new Date(d - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10); };
  const add = (s, n) => { const d = new Date(s + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const fd = s => new Date(s + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  const line = i => Math.round((+i.qty || 0) * (+i.unit_price || 0) * 100) / 100;
  const blank = () => ({ product_id: null, description: '', qty: 1, unit_price: 0 });
  const url = (t, x = '') => new URL('quote.php?t=' + t + x, location.href).href;
  const badge = s => `<span class="bd q-${s}">${ST[s] || esc(s)}</span>`;
  const msg = q => `Hello ${q.customer_name},\n\nThank you for your interest. Here is our quotation ${q.number} from ${S.name} for ${m(q.total)}, valid until ${fd(q.valid_until)}.\n\nYou can view, download and accept it here:\n${url(q.token)}\n\nPlease call us if you have any questions.`;

  // ---------- markup ----------
  const btn = document.createElement('button');
  btn.className = 'chip'; btn.dataset.t = 'q';
  btn.innerHTML = 'Quotation <span class="nbd" id="qt-badge" hidden></span>';
  const after = tabs.querySelector('[data-t="v"]');
  after ? after.after(btn) : tabs.append(btn);

  const panel = document.createElement('div');
  panel.id = 'qt-panel'; panel.hidden = true;
  panel.innerHTML = `
<style>
.bd.q-request{background:var(--ye);color:#2e2f32}.bd.q-draft{border:1px solid var(--mu);color:var(--mu)}.bd.q-sent{background:#2563eb;color:#fff}
.bd.q-accepted{background:var(--gr);color:#fff}.bd.q-declined,.bd.q-expired{background:#c4161c;color:#fff}.bd.q-void{border:1px solid var(--mu);color:var(--mu)}
#qt-panel .bd{margin-left:0}#qt-dlg .g2{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}@media(max-width:560px){#qt-dlg .g2{grid-template-columns:1fr}}
.qtreq{background:var(--cd);border:1px solid var(--ye);border-radius:8px;padding:8px 10px;font-size:13px;margin:8px 0;white-space:pre-line}
</style>
<div class="kp" id="qt-kp"></div>
<div class="tl"><label class="sr" for="qt-q">Search quotations</label><input id="qt-q" type="search" placeholder="Search number, customer or company"><span class="chips2" id="qt-f"></span><button class="btn n" id="qt-new">+ New quotation</button></div>
<div class="ow"><table class="tb" id="qt-t"></table></div>
<dialog id="qt-dlg" class="wide"><form id="qt-form"><h2 id="qt-dt">New quotation</h2><input type="hidden" name="id">
 <div id="qt-req" class="qtreq" hidden></div>
 <div class="g2"><label>Customer name<input name="customer_name" required maxlength="120"></label><label>Company (optional)<input name="customer_company" maxlength="120"></label>
 <label>Phone<input name="customer_phone" type="tel" maxlength="40"></label><label>Email<input name="customer_email" type="email" maxlength="120"></label></div>
 <label>Address (optional)<input name="customer_address" maxlength="300"></label>
 <label>Subject / project (optional)<input name="subject" maxlength="160" placeholder="e.g. PPE for the Mombasa Road site"></label>
 <div class="g2"><label>Quotation date<input name="issue_date" type="date" required></label><label>Valid until<input name="valid_until" type="date" required></label></div>
 <div class="ow"><table class="tb" id="qt-items"></table></div>
 <p><button type="button" class="btn" id="qt-addline">+ Add line</button></p>
 <label><select id="qt-prod" aria-label="Add a product"></select></label>
 <div class="g2"><label>Discount (amount)<input name="discount" type="number" step="0.01" min="0" value="0"></label><label><span id="qt-taxn">Tax</span> %<input name="tax_rate" type="number" step="0.01" min="0" max="100" value="0"></label></div>
 <p id="qt-tot" style="font-size:15px"></p>
 <label>Notes to the customer (optional)<textarea name="notes" rows="2" maxlength="1000"></textarea></label>
 <label>Terms &amp; conditions<textarea name="terms" rows="4" maxlength="1500"></textarea></label>
 <p id="qt-err" role="alert" style="color:#de1c24;font-weight:600"></p>
 <button class="btn n" id="qt-s0" data-send="0">Save as draft</button> <button class="btn n" id="qt-s1" data-send="1">Save &amp; mark as sent</button>
 <button type="button" class="lk" id="qt-x">Cancel</button></form></dialog>`;
  root.append(panel);

  // ---------- list ----------
  async function load() {
    try { Q = await api('q_list'); draw(); } catch (x) { $('#qt-t').innerHTML = `<tr><td>${esc(x.message)}</td></tr>`; }
  }
  function draw() {
    if (!Q) return;
    const t = Q.totals, q = QS.toLowerCase();
    $('#qt-badge').hidden = !t.requests; $('#qt-badge').textContent = t.requests;
    $('#qt-kp').innerHTML = [['request', 'New requests', t.requests, t.requests ? 'bad' : ''], ['sent', 'Waiting for answer', m(t.open_value) + (t.open_n ? ' · ' + t.open_n : '')], ['accepted', 'Accepted this month', m(t.won_month) + (t.won_n ? ' · ' + t.won_n : '')], ['draft', 'Drafts', t.drafts]]
      .map(([f, l, v, c]) => `<button class="kc ${c || ''}" data-qf="${f}"><small>${l}</small><b>${esc(v)}</b></button>`).join('');
    $('#qt-f').innerHTML = [['all', 'All'], ['request', 'Requests'], ['draft', 'Draft'], ['sent', 'Sent'], ['accepted', 'Accepted'], ['closed', 'Closed']]
      .map(([k, l]) => `<button class="chip${F == k ? ' on' : ''}" data-qf="${k}">${l}</button>`).join('');
    const L = Q.items.filter(i => FILT[F](i.qstatus) && (!q || (i.number + ' ' + i.customer_name + ' ' + i.customer_company).toLowerCase().includes(q)));
    $('#qt-t').innerHTML = '<tr><th>Quotation</th><th>Customer</th><th>Valid until</th><th>Total</th><th>Status</th><th></th></tr>' + (L.map(i => {
      const editable = !i.invoice_id && !['void', 'accepted'].includes(i.status), live = ['sent', 'accepted'].includes(i.status);
      return `<tr><td><b>${esc(i.number)}</b><br><small>${fd(i.issue_date)}</small></td>
<td>${esc(i.customer_name)}${i.customer_company ? '<br><small>' + esc(i.customer_company) + '</small>' : ''}${i.customer_phone ? '<br><small>' + esc(i.customer_phone) + '</small>' : ''}${i.subject ? '<br><small><i>' + esc(i.subject) + '</i></small>' : ''}</td>
<td>${fd(i.valid_until)}</td><td>${i.total > 0 ? m(i.total) : '–'}</td><td>${badge(i.qstatus)}${i.invoice_number ? '<br><small>Invoice ' + esc(i.invoice_number) + '</small>' : ''}</td>
<td class="vac">${editable ? `<button class="xs" data-qe="${i.id}">${i.status == 'request' ? 'Price it' : 'Edit'}</button> ` : ''}<a class="xs" href="${url(i.token)}" target="_blank" rel="noopener">View</a>
${i.status == 'draft' && i.total > 0 ? ` <button class="xs" data-qs="${i.id}" data-st="sent">Mark as sent</button>` : ''}${i.status == 'sent' ? ` <button class="xs" data-qs="${i.id}" data-st="accepted">Accepted</button> <button class="xs" data-qs="${i.id}" data-st="declined">Declined</button>` : ''}
${!i.invoice_id && ['sent', 'accepted', 'draft'].includes(i.status) && i.total > 0 ? ` <button class="xs" data-qi="${i.id}">Make invoice</button>` : ''}${i.invoice_id ? ' <button class="xs" data-qgo="1">See Invoices</button>' : ''}
 <select class="xs" data-qm="${i.id}" aria-label="More actions for ${esc(i.number)}"><option value="">More…</option>${i.total > 0 ? '<option value="pdf">Download PDF</option>' : ''}${live ? '<option value="email">Send by email</option><option value="wa">Send by WhatsApp</option>' : ''}${i.total > 0 ? '<option value="dup">Duplicate</option>' : ''}${!i.invoice_id && i.status != 'void' && i.status != 'request' ? '<option value="void">Void</option>' : ''}${['draft', 'request', 'void'].includes(i.status) ? '<option value="del">Delete</option>' : ''}</select></td></tr>`;
    }).join('') || '<tr><td colspan="6">No quotations here yet. Press “+ New quotation”, or wait for requests from the store.</td></tr>');
  }

  // ---------- editor ----------
  async function edit(id) {
    const f = $('#qt-form'); f.reset(); $('#qt-err').textContent = '';
    if (!PR.length) { try { PR = await api('a_products'); } catch (x) { PR = []; } }
    const v = id ? await api('q_get', null, '&id=' + id) : null, t = today(), el = f.elements, d = Q ? Q.defaults : { valid_days: 30, terms: '' };
    el.id.value = id || '';
    for (const k of ['customer_name', 'customer_company', 'customer_phone', 'customer_email', 'customer_address', 'subject', 'issue_date', 'valid_until', 'tax_rate', 'discount', 'notes', 'terms'])
      el[k].value = v ? v[k] : k == 'issue_date' ? t : k == 'valid_until' ? add(t, d.valid_days) : k == 'tax_rate' ? (S.default_tax || 0) : k == 'discount' ? 0 : k == 'terms' ? d.terms : '';
    if (v && v.status == 'request') { el.issue_date.value = t; el.valid_until.value = add(t, d.valid_days); el.terms.value = v.terms || d.terms; }
    LN = v && v.items.length ? v.items.map(i => ({ ...i })) : [blank()];
    $('#qt-dt').textContent = v ? (v.status == 'request' ? 'Price request ' : 'Edit quotation ') + v.number : 'New quotation';
    $('#qt-req').hidden = !(v && v.request_text); $('#qt-req').innerHTML = v && v.request_text ? '<b>Customer asked for:</b>\n' + esc(v.request_text) : '';
    $('#qt-taxn').textContent = S.tax_name || 'Tax';
    $('#qt-prod').innerHTML = '<option value="">+ Add a product from your store…</option>' + PR.map(p => `<option value="${p.id}">${esc(p.name)} · ${m(p.price)}</option>`).join('');
    $('#qt-s1').textContent = 'Save & mark as sent';
    rows(); tot(); $('#qt-dlg').showModal();
  }
  function rows() {
    $('#qt-items').innerHTML = '<tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Amount</th><th></th></tr>' + LN.map((it, n) => `<tr>
<td><input data-qn="${n}" data-qk="description" value="${esc(it.description)}" maxlength="200" placeholder="Item or service" aria-label="Description"></td>
<td><input data-qn="${n}" data-qk="qty" type="number" min="0.01" step="0.01" value="${it.qty}" aria-label="Quantity"></td>
<td><input data-qn="${n}" data-qk="unit_price" type="number" min="0" step="0.01" value="${it.unit_price}" aria-label="Unit price"></td>
<td id="qt-a${n}">${m(line(it))}</td><td><button type="button" class="xs" data-qdel="${n}" aria-label="Remove line">✕</button></td></tr>`).join('');
  }
  function tot() {
    const el = $('#qt-form').elements, sub = LN.reduce((a, i) => a + Math.round((+i.qty || 0) * (+i.unit_price || 0) * 100), 0);
    const dc = Math.min(Math.round((+el.discount.value || 0) * 100), sub), net = sub - dc, tx = Math.round(net * (+el.tax_rate.value || 0) / 100);
    $('#qt-tot').innerHTML = `Subtotal ${m(sub / 100)}${dc ? ` · Discount −${m(dc / 100)}` : ''}${tx ? ` · ${esc(S.tax_name || 'Tax')} ${m(tx / 100)}` : ''} · <b>Total ${m((net + tx) / 100)}</b>`;
  }

  // ---------- events ----------
  panel.addEventListener('click', async ev => {
    ev.stopPropagation();
    const t = ev.target.closest('button'); if (!t) return; const d = t.dataset;
    try {
      if (d.qf) { F = d.qf; draw(); }
      else if (t.id == 'qt-new') await edit(null);
      else if (d.qe) await edit(+d.qe);
      else if (d.qs) { await api('q_status', { id: +d.qs, status: d.st }); await load(); }
      else if (d.qi) {
        if (!confirm('Create a draft invoice from this quotation? The quotation will be marked as accepted.')) return;
        await api('q_to_invoice', { id: +d.qi }); await load();
        const iv = tabs.querySelector('[data-t="v"]'); if (iv) iv.click();
      }
      else if (d.qgo) { const iv = tabs.querySelector('[data-t="v"]'); if (iv) iv.click(); }
      else if (d.qdel) { LN.splice(+d.qdel, 1); if (!LN.length) LN.push(blank()); rows(); tot(); }
      else if (t.id == 'qt-addline') { LN.push(blank()); rows(); tot(); const r = panel.querySelectorAll('#qt-items [data-qk=description]'); r[r.length - 1].focus(); }
      else if (t.id == 'qt-x') $('#qt-dlg').close();
    } catch (x) { alert(x.message); }
  });
  panel.addEventListener('input', ev => {
    const t = ev.target;
    if (t.id == 'qt-q') { QS = t.value; draw(); return; }
    if (t.dataset.qk) { const it = LN[+t.dataset.qn]; it[t.dataset.qk] = t.dataset.qk == 'description' ? t.value : (t.value === '' ? 0 : +t.value); $('#qt-a' + t.dataset.qn).textContent = m(line(it)); tot(); return; }
    if (t.name == 'discount' || t.name == 'tax_rate') tot();
  });
  panel.addEventListener('change', async ev => {
    const t = ev.target;
    if (t.id == 'qt-prod') {
      const p = PR.find(x => x.id == t.value); t.value = ''; if (!p) return;
      if (LN.length == 1 && !LN[0].description && !+LN[0].unit_price) LN = [];
      LN.push({ product_id: p.id, description: p.name, qty: 1, unit_price: p.price }); rows(); tot(); return;
    }
    const id = t.dataset.qm; if (!id) return;
    const a = t.value; t.value = ''; const i = Q.items.find(x => x.id == id); if (!i || !a) return;
    try {
      if (a == 'pdf') window.open(url(i.token, '&print=1'), '_blank', 'noopener');
      else if (a == 'email') location.href = 'mailto:' + i.customer_email + '?subject=' + encodeURIComponent('Quotation ' + i.number + ' from ' + S.name) + '&body=' + encodeURIComponent(msg(i));
      else if (a == 'wa') { const ph = i.customer_phone.trim().startsWith('+') ? i.customer_phone.replace(/\D/g, '') : (/^0\d{9}$/.test(i.customer_phone.replace(/\s/g, '')) ? '254' + i.customer_phone.replace(/\s/g, '').slice(1) : ''); window.open('https://wa.me/' + ph + '?text=' + encodeURIComponent(msg(i)), '_blank', 'noopener'); }
      else if (a == 'dup') { const r = await api('q_duplicate', { id: +id }); await load(); await edit(r.id); }
      else if (a == 'void') { if (confirm('Void quotation ' + i.number + '? It stays on record but can’t be reopened.')) { await api('q_status', { id: +id, status: 'void' }); await load(); } }
      else if (a == 'del') { if (confirm('Delete ' + i.number + '?')) { await api('q_delete', { id: +id }); await load(); } }
    } catch (x) { alert(x.message); }
  });
  $('#qt-form').onsubmit = async ev => {
    ev.preventDefault(); $('#qt-err').textContent = '';
    const b = Object.fromEntries(new FormData(ev.target)); b.items = LN; b.send = !!ev.submitter && ev.submitter.dataset.send == '1';
    try { await api('q_save', b); $('#qt-dlg').close(); await load(); } catch (x) { $('#qt-err').textContent = x.message; }
  };

  // show / hide with the tab bar
  tabs.addEventListener('click', ev => {
    const b = ev.target.closest('[data-t]'); if (!b) return;
    panel.hidden = b.dataset.t !== 'q';
    if (b.dataset.t === 'q') load();
  });
  // staff see a count of new customer requests on the tab, checked every minute
  const ping = async () => {
    if (document.hidden || typeof ME === 'undefined' || !ME) return;
    try { const c = await api('q_count'); $('#qt-badge').hidden = !c.requests; $('#qt-badge').textContent = c.requests; } catch (x) { /* ignore */ }
  };
  setInterval(ping, 60000); setTimeout(ping, 5000);
})();
