// Sales tab (admin.html and worker.html): new sale, history, reports, receipts and invoices, plus the dashboard cards.
// Uses helpers from the page: api, m (money), toast, ME. Everything else is self-contained.
(() => {
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const adminTabs = $('#adm .tabs'), workerTabs = $('#app .tabs');
  const tabs = adminTabs || workerTabs;
  if (!tabs) return;
  const IS_ADMIN = !!adminTabs;
  const cur = () => (typeof S !== 'undefined' && S.currency) || 'KSh';
  const money = n => cur() + ' ' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const c100 = v => Math.round((parseFloat(v) || 0) * 100);
  const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 10);
  const addDays = (iso, n) => { const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : (Date.now().toString(16) + Math.random().toString(16).slice(2) + '0000000000000000')).slice(0, 36);
  const qs = o => Object.entries(o).filter(([, v]) => v !== '' && v != null).map(([k, v]) => '&' + k + '=' + encodeURIComponent(v)).join('');
  const STB = { paid: ['ok', 'Paid'], partial: ['low', 'Part paid'], unpaid: ['out', 'Unpaid'], cancelled: ['out', 'Cancelled'], returned: ['low', 'Returned'] };
  const badge = x => { const k = x.status === 'cancelled' ? 'cancelled' : x.status === 'returned' ? 'returned' : x.pstatus; const [c, t] = STB[k]; return `<span class="bd ${c}">${t}</span>`; };

  let CFG = { tax_rate: 0, tax_name: 'VAT', methods: ['M-Pesa', 'Cash', 'Bank transfer', 'Card'], can_oversell: false };
  let view = 'new', cart = [], key = newKey(), busy = false, results = [], searchT = null;
  let hist = [], cashiers = new Map(), report = null, period = 'today', cur_sale = null, receiptMode = 'receipt';

  // ---------- tab button, panel, dashboard cards ----------
  const btn = document.createElement('button');
  btn.className = 'chip'; btn.dataset.t = 'sl'; btn.textContent = 'Sales';
  const after = tabs.querySelector('[data-t="i"]') || tabs.querySelector('[data-t="s"]');
  after ? after.after(btn) : tabs.append(btn);

  const dash = document.createElement('div');
  dash.className = 'slk-row'; dash.id = 'sl-dash'; dash.hidden = true;
  tabs.before(dash);

  const panel = document.createElement('div');
  panel.id = 'sl-panel'; panel.hidden = true;
  panel.innerHTML = `
<div class="tl"><span class="chips2" id="sl-nav"></span></div>
<div id="sl-new"></div><div id="sl-hist" hidden></div><div id="sl-rep" hidden></div>

<dialog id="sl-rv" class="wide"><h2>Review sale</h2><div id="sl-rvb"></div><p id="sl-rve" role="alert" class="sl-err"></p>
 <button class="btn n w" id="sl-ok">Confirm &amp; complete sale</button><button type="button" class="lk" data-close>Back</button></dialog>

<dialog id="sl-rc" class="wide sl-rcd"><div class="tl"><span class="chips2"><button class="chip on" data-rm="receipt">Receipt</button><button class="chip" data-rm="invoice">Invoice</button></span>
 <button class="btn n" id="sl-print">Print / Save as PDF</button><button class="btn" id="sl-wa">Share on WhatsApp</button><button class="btn" id="sl-newsale" hidden>New sale</button><button class="btn" data-close>Close</button></div>
 <iframe id="sl-fr" title="Receipt" class="sl-fr"></iframe></dialog>

<dialog id="sl-dt" class="wide"><div id="sl-dtb"></div><button type="button" class="lk" data-close>Close</button></dialog>
<dialog id="sl-act"><div id="sl-actb"></div></dialog>`;
  $(IS_ADMIN ? '#adm' : '#app').append(panel);

  // ---------- small helpers ----------
  async function call(a, b, x) { return api(a, b, x || ''); }
  function downloadCsv(extra) {
    const url = 'api.php?action=s_export' + qs(extra);
    fetch(url, { credentials: 'same-origin' }).then(async r => {
      if (!r.ok || !/csv/.test(r.headers.get('content-type') || '')) { const j = await r.json().catch(() => ({})); throw new Error(j.error || 'Download failed.'); }
      const b = await r.blob(), a = document.createElement('a'); a.href = URL.createObjectURL(b);
      a.download = 'sales-' + today() + (extra.detail === 'items' ? '-items' : '') + '.csv'; document.body.append(a); a.click(); a.remove();
    }).catch(x => alert(x.message));
  }
  function nav() {
    $('#sl-nav').innerHTML = [['new', 'New sale'], ['hist', 'Sales history'], ['rep', 'Reports']].map(([k, l]) => `<button class="chip${view === k ? ' on' : ''}" data-v="${k}">${l}</button>`).join('');
    $('#sl-new').hidden = view !== 'new'; $('#sl-hist').hidden = view !== 'hist'; $('#sl-rep').hidden = view !== 'rep';
  }
  async function go(v) {
    view = v; nav();
    if (v === 'new') { drawPos(); searchNow(); }
    if (v === 'hist') { drawHist(); await loadHist(); }
    if (v === 'rep') { drawRep(); await loadRep(); }
  }

  // ---------- dashboard cards ----------
  async function loadDash() {
    try {
      const d = await call('s_summary');
      const mine = d.scope === 'mine';
      const top = d.top.length ? d.top.map(t => `${esc(t.name)} (${t.units})`).join(', ') : 'No sales yet this month';
      dash.hidden = false;
      dash.innerHTML = [
        [mine ? 'My sales today' : 'Today’s sales', money(d.today.net), d.today.n + ' transaction' + (d.today.n === 1 ? '' : 's')],
        [mine ? 'My sales this month' : 'Monthly revenue', money(d.month.net), d.month.units + ' unit' + (d.month.units === 1 ? '' : 's') + ' sold'],
        ['Transactions this month', d.month.n, d.outstanding > 0 ? 'Unpaid balances: ' + money(d.outstanding) : 'No unpaid balances'],
        ['Top-selling products', '', top]
      ].map(([l, v, s], i) => `<button class="slk${i === 3 ? ' wide2' : ''}" data-sl-go="1"><small>${l}</small>${v === '' ? '' : `<b>${esc(v)}</b>`}<em>${i === 3 ? top : esc(s)}</em></button>`).join('');
    } catch (x) { /* not signed in yet, or sales tables not installed */ }
  }
  dash.addEventListener('click', ev => { if (ev.target.closest('[data-sl-go]')) { ev.stopPropagation(); btn.click(); } });
  const ping = () => { if (!document.hidden && typeof ME !== 'undefined' && ME && (ME.role === 'admin' || ME.role === 'worker')) loadDash(); };
  setInterval(ping, 60000); setTimeout(ping, 2500);

  // ---------- NEW SALE ----------
  function drawPos() {
    if ($('#sl-new').dataset.ready) { drawCart(); return; }
    $('#sl-new').dataset.ready = '1';
    $('#sl-new').innerHTML = `
<div class="sl-pos">
 <div class="sl-find">
  <label class="sr" for="sl-q">Find a product</label>
  <input id="sl-q" type="search" placeholder="Search name or SKU, or scan a barcode and press Enter" autocomplete="off">
  <div id="sl-res" class="sl-res"></div>
 </div>
 <div class="sl-cartbox">
  <h3 class="h3" style="margin-top:0">Items in this sale</h3>
  <div class="ow"><table class="tb" id="sl-ct"></table></div>
  <div class="sl-grid">
   <label>Extra discount on the whole sale<input id="sl-ed" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00"></label>
   <label><span id="sl-taxl">Tax</span> %<input id="sl-tx" type="number" min="0" max="100" step="0.01" inputmode="decimal"></label>
   <label>Customer name<input id="sl-cn" maxlength="120" placeholder="Needed if not fully paid"></label>
   <label>Customer phone<input id="sl-cp" maxlength="40" inputmode="tel"></label>
  </div>
  <div class="sl-tot" id="sl-tot"></div>
  <fieldset class="sl-pay"><legend>Payment</legend>
   <div class="sl-pm" role="group"><label><input type="radio" name="slpm" value="paid" checked> Fully paid</label><label><input type="radio" name="slpm" value="partial"> Part paid</label><label><input type="radio" name="slpm" value="unpaid"> Unpaid (pay later)</label></div>
   <div class="sl-grid">
    <label id="sl-methw">Payment method<select id="sl-meth"></select></label>
    <label id="sl-amtw" hidden>Amount paid now<input id="sl-amt" type="number" min="0" step="0.01" inputmode="decimal"></label>
    <label id="sl-refw">Reference (M-Pesa code, bank ref…)<input id="sl-ref" maxlength="80"></label>
    <label id="sl-tenw" hidden>Cash received<input id="sl-ten" type="number" min="0" step="0.01" inputmode="decimal"></label>
   </div>
   <p class="hint" id="sl-chg"></p>
  </fieldset>
  <label>Note (optional)<input id="sl-note" maxlength="300"></label>
  <label class="ck" id="sl-ovw" hidden><input type="checkbox" id="sl-ov"> Administrator override: allow selling more than the stock</label>
  <p id="sl-err" role="alert" class="sl-err"></p>
  <button class="btn n w" id="sl-review">Review sale</button><button type="button" class="lk" id="sl-clear">Clear sale</button>
 </div>
</div>`;
    $('#sl-meth').innerHTML = CFG.methods.map(x => `<option>${esc(x)}</option>`).join('');
    $('#sl-tx').value = CFG.tax_rate || 0; $('#sl-taxl').textContent = CFG.tax_name || 'Tax';
    drawCart();
  }
  async function searchNow(enter) {
    const q = ($('#sl-q') || { value: '' }).value.trim();
    try {
      const d = await call('s_products', null, qs({ q }));
      CFG = { ...CFG, ...d, items: undefined }; results = d.items;
      if ($('#sl-meth') && !$('#sl-meth').options.length) $('#sl-meth').innerHTML = CFG.methods.map(x => `<option>${esc(x)}</option>`).join('');
      if (!$('#sl-tx').dataset.set) { $('#sl-tx').value = CFG.tax_rate || 0; $('#sl-tx').dataset.set = '1'; $('#sl-taxl').textContent = CFG.tax_name || 'Tax'; }
      if (enter && q) {
        const exact = results.find(p => p.sku && p.sku.toLowerCase() === q.toLowerCase()) || (results.length === 1 ? results[0] : null);
        if (exact) { addToCart(exact.id); $('#sl-q').value = ''; return searchNow(); }
      }
      drawRes();
    } catch (x) { $('#sl-res').innerHTML = `<p class="sl-err">${esc(x.message)}</p>`; }
  }
  function drawRes() {
    $('#sl-res').innerHTML = results.map(p => {
      const inC = cart.find(c => c.id === p.id), left = p.stock - (inC ? inC.qty : 0);
      return `<button class="sl-p${p.stock <= 0 ? ' out' : ''}" data-add="${p.id}"><span class="sl-pn">${p.emoji ? esc(p.emoji) + ' ' : ''}${esc(p.name)}</span>
       <small>${p.sku ? esc(p.sku) + ' · ' : ''}${esc(p.dept)}</small><b>${money(p.price)}</b>
       <span class="bd ${p.stock <= 0 ? 'out' : p.stock <= p.reorder_level ? 'low' : 'ok'}">${p.stock <= 0 ? 'Out of stock' : left + ' in stock'}</span></button>`;
    }).join('') || '<p class="hint">No products match.</p>';
  }
  function addToCart(id) {
    const p = results.find(x => x.id === id) || cart.find(x => x.id === id); if (!p) return;
    const c = cart.find(x => x.id === id);
    if (c) c.qty++; else cart.push({ id: p.id, name: p.name, sku: p.sku, price: p.price, stock: p.stock, qty: 1, disc: '' });
    key = newKey(); drawCart(); drawRes();
  }
  function calc() {
    let sub = 0, ld = 0, units = 0;
    cart.forEach(c => { sub += c100(c.price) * c.qty; ld += Math.min(c100(c.disc), c100(c.price) * c.qty); units += c.qty; });
    const extra = Math.min(c100($('#sl-ed') && $('#sl-ed').value), sub - ld), disc = ld + extra, taxable = sub - disc;
    const rate = Math.min(100, Math.max(0, parseFloat($('#sl-tx') && $('#sl-tx').value) || 0)), tax = Math.round(taxable * rate / 100);
    return { sub, ld, extra, disc, tax, rate, total: taxable + tax, units };
  }
  const mode = () => (document.querySelector('input[name="slpm"]:checked') || {}).value || 'paid';
  function paidNow() { const t = calc().total, md = mode(); return md === 'paid' ? t : md === 'unpaid' ? 0 : Math.min(c100($('#sl-amt').value), t); }
  function drawCart() {
    if (!$('#sl-ct')) return;
    $('#sl-ct').innerHTML = '<tr><th>Item</th><th>Price</th><th>Qty</th><th>Discount</th><th>Subtotal</th><th></th></tr>' + (cart.map(c => {
      const over = c.qty > c.stock;
      return `<tr><td>${esc(c.name)}${c.sku ? `<br><small>${esc(c.sku)}</small>` : ''}<br><small class="${over ? 'sl-err' : ''}">${c.stock} in stock${over ? ' · not enough' : ''}</small></td><td>${money(c.price)}</td>
       <td><input class="sl-n" type="number" min="1" step="1" value="${c.qty}" data-sl-q="${c.id}" aria-label="Quantity of ${esc(c.name)}"></td>
       <td><input class="sl-n" type="number" min="0" step="0.01" value="${esc(c.disc)}" placeholder="0.00" data-sl-d="${c.id}" aria-label="Discount on ${esc(c.name)}"></td>
       <td id="sl-st${c.id}">${money(rowSub(c))}</td><td><button class="xs" data-sl-x="${c.id}" aria-label="Remove ${esc(c.name)}">✕</button></td></tr>`;
    }).join('') || '<tr><td colspan="6">Search for a product and press it to add it to the sale.</td></tr>');
    drawTotals();
  }
  const rowSub = c => (c100(c.price) * c.qty - Math.min(c100(c.disc), c100(c.price) * c.qty)) / 100;
  function drawTotals() {
    if (!$('#sl-tot')) return;
    const t = calc(), over = cart.some(c => c.qty > c.stock), paid = paidNow();
    $('#sl-tot').innerHTML = `<div><span>Subtotal (${t.units} item${t.units === 1 ? '' : 's'})</span><b>${money(t.sub / 100)}</b></div><div><span>Discounts</span><b>− ${money(t.disc / 100)}</b></div>
     <div><span>${esc(CFG.tax_name || 'Tax')} ${t.rate}%</span><b>${money(t.tax / 100)}</b></div><div class="g"><span>Total</span><b>${money(t.total / 100)}</b></div>
     <div><span>Paid now</span><b>${money(paid / 100)}</b></div><div><span>Balance</span><b>${money((t.total - paid) / 100)}</b></div>`;
    const md = mode();
    $('#sl-amtw').hidden = md !== 'partial'; $('#sl-methw').hidden = md === 'unpaid'; $('#sl-refw').hidden = md === 'unpaid';
    const cash = $('#sl-meth').value === 'Cash' && md !== 'unpaid';
    $('#sl-tenw').hidden = !cash;
    const ten = c100($('#sl-ten').value);
    $('#sl-chg').textContent = cash && ten ? (ten >= paid ? 'Change to give back: ' + money((ten - paid) / 100) : 'Cash received is less than the amount due.') : '';
    $('#sl-ovw').hidden = !(CFG.can_oversell && over);
    cart.forEach(c => { const e = $('#sl-st' + c.id); if (e) e.textContent = money(rowSub(c)); });
  }
  function problem() {
    const t = calc(), over = cart.filter(c => c.qty > c.stock);
    if (!cart.length) return 'Add at least one product.';
    if (cart.some(c => !(c.qty >= 1) || c.qty !== Math.floor(c.qty))) return 'Quantities must be whole numbers of 1 or more.';
    if (cart.some(c => c100(c.disc) > c100(c.price) * c.qty)) return 'A discount is more than the price of that item.';
    if (c100($('#sl-ed').value) > t.sub - t.ld) return 'The extra discount is more than the sale total.';
    if (over.length && !($('#sl-ov').checked && CFG.can_oversell)) return 'Not enough stock for ' + over.map(c => c.name).join(', ') + (CFG.can_oversell ? '. Tick the administrator override to sell anyway.' : '. Ask an administrator to approve this sale.');
    const md = mode(), paid = paidNow();
    if (md === 'partial' && (paid <= 0 || paid >= t.total)) return 'For a part payment, enter an amount above 0 and below the total.';
    if (paid < t.total && !$('#sl-cn').value.trim()) return 'Enter the customer’s name for a part-paid or unpaid sale.';
    return '';
  }
  function review() {
    const e = problem(); $('#sl-err').textContent = e; if (e) return;
    const t = calc(), paid = paidNow(), md = mode();
    $('#sl-rve').textContent = '';
    $('#sl-rvb').innerHTML = `<div class="ow"><table class="tb"><tr><th>Item</th><th>Qty</th><th>Price</th><th>Disc.</th><th>Subtotal</th></tr>${cart.map(c => `<tr><td>${esc(c.name)}</td><td>${c.qty}</td><td>${money(c.price)}</td><td>${money(Math.min(c100(c.disc), c100(c.price) * c.qty) / 100)}</td><td>${money(rowSub(c))}</td></tr>`).join('')}</table></div>
     <div class="sl-tot">${$('#sl-tot').innerHTML}</div>
     <p><b>${md === 'unpaid' ? 'Unpaid: to be paid later' : money(paid / 100) + ' by ' + esc($('#sl-meth').value)}</b>${$('#sl-cn').value.trim() ? ' · Customer: ' + esc($('#sl-cn').value.trim()) : ''}</p>
     <p class="hint">Stock is deducted as soon as you confirm.</p>`;
    $('#sl-ok').disabled = false; $('#sl-rv').showModal();
  }
  async function confirmSale() {
    if (busy) return; busy = true; $('#sl-ok').disabled = true;
    const t = calc(), md = mode();
    const body = { key, items: cart.map(c => ({ product_id: c.id, qty: c.qty, discount: c.disc || 0 })), discount: $('#sl-ed').value || 0, tax_rate: t.rate,
      customer_name: $('#sl-cn').value, customer_phone: $('#sl-cp').value, note: $('#sl-note').value, method: md === 'unpaid' ? '' : $('#sl-meth').value,
      reference: $('#sl-ref').value, amount_paid: paidNow() / 100, allow_oversell: !!($('#sl-ov').checked && CFG.can_oversell) };
    try {
      const r = await call('s_create', body);
      key = newKey(); cart = []; ['sl-ed', 'sl-cn', 'sl-cp', 'sl-note', 'sl-ref', 'sl-ten', 'sl-amt'].forEach(i => $('#' + i).value = ''); $('#sl-ov').checked = false;
      document.querySelector('input[name="slpm"][value="paid"]').checked = true;
      $('#sl-rv').close(); drawCart(); searchNow(); loadDash();
      toast(r.duplicate ? 'That sale was already recorded.' : 'Sale completed.');
      await showReceipt(r.id, true);
    } catch (x) {
      // a reply from the server means nothing was recorded, so a fresh key is safe; a lost connection keeps the key so a retry can never double-sell
      if (!(x instanceof TypeError)) key = newKey();
      $('#sl-rve').textContent = x.message; $('#sl-ok').disabled = false;
    } finally { busy = false; }
  }

  // ---------- RECEIPT / INVOICE ----------
  function docHtml(x, kind) {
    const st = x.store, f = n => st.currency + ' ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const logo = st.logo ? new URL(st.logo, location.href).href : '', col = /^#[0-9a-f]{6}$/i.test(st.color) ? st.color : '#ED152A';
    const inv = kind === 'invoice', bal = x.status === 'cancelled' ? 0 : x.balance;
    const head = `<div class="h">${logo ? `<img src="${esc(logo)}" alt="">` : ''}<h1>${esc(st.name)}</h1><p>${esc(st.address)}<br>${esc(st.phone)}${st.phone2 ? ' / ' + esc(st.phone2) : ''}<br>${esc(st.email)}</p></div>`;
    const items = x.items.map((i, n) => `<tr><td>${inv ? n + 1 + '</td><td>' : ''}${esc(i.name)}${i.returned_qty ? `<br><small>${i.returned_qty} returned</small>` : ''}</td><td class="r">${i.qty}</td><td class="r">${f(i.unit_price)}</td>${inv ? `<td class="r">${f(i.discount)}</td>` : ''}<td class="r">${f(i.net)}</td></tr>`).join('');
    const pays = x.payments.map(p => `<tr><td>${esc(p.at)} · ${esc(p.method)}${p.reference ? ' (' + esc(p.reference) + ')' : ''}</td><td class="r">${f(p.amount)}</td></tr>`).join('');
    const css = `body{font:13px/1.45 Arial,Helvetica,sans-serif;color:#222;margin:0;padding:18px;${inv ? 'max-width:760px' : 'max-width:360px'};margin:auto}.h{text-align:center;border-bottom:2px solid ${col};padding-bottom:8px;margin-bottom:10px}.h img{max-height:54px;max-width:200px}.h h1{margin:4px 0;font-size:${inv ? 22 : 17}px;color:${col}}.h p{margin:0;font-size:12px;color:#555}
     h2{font-size:${inv ? 20 : 15}px;margin:8px 0;text-align:${inv ? 'left' : 'center'};letter-spacing:1px}table{width:100%;border-collapse:collapse}th{text-align:left;border-bottom:1px solid #999;padding:5px 4px;font-size:12px}td{padding:4px;vertical-align:top;border-bottom:1px dotted #ccc}.r{text-align:right;white-space:nowrap}
     .t td{border:0;padding:2px 4px}.t .g td{font-weight:700;font-size:15px;border-top:2px solid #222}.m{display:flex;justify-content:space-between;gap:12px;font-size:12px;margin-bottom:8px}.s{margin-top:10px;text-align:center;font-size:12px;color:#555}.st{display:inline-block;border:2px solid ${bal <= 0 ? '#2a8703' : '#c4161c'};color:${bal <= 0 ? '#2a8703' : '#c4161c'};padding:2px 10px;font-weight:700;transform:rotate(-4deg)}small{color:#777}@media print{body{padding:0}}`;
    return `<!doctype html><html><head><meta charset="utf-8"><title>${inv ? 'Invoice' : 'Receipt'} ${esc(x.receipt_no)}</title><style>${css}</style></head><body>${head}
     <h2>${inv ? 'INVOICE' : 'SALES RECEIPT'}</h2>
     <div class="m"><div><b>${inv ? 'Invoice' : 'Receipt'} no:</b> ${esc(x.receipt_no)}<br><b>Date:</b> ${esc(x.at)}<br><b>Served by:</b> ${esc(x.cashier || '-')}</div>${x.customer_name || x.customer_phone ? `<div style="text-align:right"><b>${inv ? 'Bill to' : 'Customer'}:</b><br>${esc(x.customer_name)}<br>${esc(x.customer_phone)}</div>` : ''}</div>
     <table><tr>${inv ? '<th>#</th>' : ''}<th>Item</th><th class="r">Qty</th><th class="r">Price</th>${inv ? '<th class="r">Disc.</th>' : ''}<th class="r">Amount</th></tr>${items}</table>
     <table class="t"><tr><td>Subtotal</td><td class="r">${f(x.subtotal)}</td></tr>${x.discount > 0 ? `<tr><td>Discounts</td><td class="r">− ${f(x.discount)}</td></tr>` : ''}${x.tax > 0 ? `<tr><td>${esc(st.tax_name)} ${x.tax_rate}%</td><td class="r">${f(x.tax)}</td></tr>` : ''}
     <tr class="g"><td>TOTAL</td><td class="r">${f(x.total)}</td></tr>${x.returned > 0 ? `<tr><td>Returned goods</td><td class="r">− ${f(x.returned)}</td></tr>` : ''}
     <tr><td>Amount paid${x.method ? ' (' + esc(x.method) + ')' : ''}</td><td class="r">${f(x.paid)}</td></tr><tr><td><b>${x.status === 'cancelled' ? 'CANCELLED' : 'Balance'}</b></td><td class="r"><b>${f(bal < 0 ? 0 : bal)}</b></td></tr></table>
     ${pays ? `<h2 style="font-size:13px;text-align:left">Payments</h2><table class="t">${pays}</table>` : ''}
     <div class="s">${x.status === 'cancelled' ? '<span class="st">CANCELLED</span>' : `<span class="st">${bal <= 0 ? 'PAID' : 'BALANCE DUE ' + f(bal)}</span>`}<p>Thank you for shopping with ${esc(st.name)}.<br>${inv ? 'Please quote the invoice number when paying.' : 'Goods sold are not returnable without this receipt.'}</p></div></body></html>`;
  }
  function drawReceipt() {
    $('#sl-fr').srcdoc = docHtml(cur_sale, receiptMode);
    document.querySelectorAll('#sl-rc [data-rm]').forEach(b => b.classList.toggle('on', b.dataset.rm === receiptMode));
  }
  async function showReceipt(id, fresh) {
    cur_sale = await call('s_get', null, qs({ id })); receiptMode = 'receipt';
    $('#sl-newsale').hidden = !fresh; drawReceipt(); if (!$('#sl-rc').open) $('#sl-rc').showModal();
  }
  function whatsApp() {
    const x = cur_sale, f = n => money(n);
    const txt = `${x.store.name}\n${receiptMode === 'invoice' ? 'Invoice' : 'Receipt'} ${x.receipt_no} (${x.at})\n` + x.items.map(i => `${i.qty} x ${i.name} = ${f(i.net)}`).join('\n') + `\nTotal: ${f(x.total)}\nPaid: ${f(x.paid)}\nBalance: ${f(Math.max(0, x.balance))}\nThank you!`;
    let ph = String(x.customer_phone || '').replace(/[^\d+]/g, ''); ph = ph.startsWith('+') ? ph.slice(1) : ph.startsWith('0') ? '254' + ph.slice(1) : ph;
    window.open('https://wa.me/' + (ph.length >= 9 ? ph : '') + '?text=' + encodeURIComponent(txt), '_blank', 'noopener');
  }

  // ---------- HISTORY ----------
  function drawHist() {
    if ($('#sl-hist').dataset.ready) return; $('#sl-hist').dataset.ready = '1';
    $('#sl-hist').innerHTML = `<div class="tl"><input id="sh-q" type="search" placeholder="Search receipt, customer or product" aria-label="Search sales">
 <label class="sr" for="sh-f">From</label><input id="sh-f" type="date" class="sl-d"><label class="sr" for="sh-t">To</label><input id="sh-t" type="date" class="sl-d">
 <select id="sh-m" class="xs" aria-label="Payment method"><option value="">Any method</option>${CFG.methods.map(x => `<option>${esc(x)}</option>`).join('')}</select>
 <select id="sh-s" class="xs" aria-label="Status"><option value="">Any status</option><option value="paid">Paid</option><option value="partial">Part paid</option><option value="unpaid">Unpaid</option><option value="returned">Returned</option><option value="cancelled">Cancelled</option></select>
 ${IS_ADMIN ? '<select id="sh-c" class="xs" aria-label="Cashier"><option value="">All cashiers</option></select>' : ''}
 <button class="btn" id="sh-go">Filter</button><button class="btn" id="sh-csv">Download CSV</button></div>
 <p class="hint" id="sh-h"></p><div class="ow"><table class="tb" id="sh-t2"></table></div>`;
  }
  const histFilter = () => ({ q: $('#sh-q').value.trim(), from: $('#sh-f').value, to: $('#sh-t').value, method: $('#sh-m').value, status: $('#sh-s').value, cashier: IS_ADMIN ? $('#sh-c').value : '' });
  async function loadHist() {
    try {
      const d = await call('s_list', null, qs(histFilter())); hist = d.sales;
      if (IS_ADMIN) { const sel = $('#sh-c'), v = sel.value; hist.forEach(s => s.cashier_id && cashiers.set(s.cashier_id, s.cashier)); sel.innerHTML = '<option value="">All cashiers</option>' + [...cashiers].map(([i, n]) => `<option value="${i}">${esc(n)}</option>`).join(''); sel.value = v; }
      $('#sh-h').textContent = d.limited ? 'Showing the latest 300 sales. Narrow the dates to see others; the CSV has everything that matches.' : hist.length + ' sale' + (hist.length === 1 ? '' : 's') + (IS_ADMIN ? '' : ' (your own)');
      $('#sh-t2').innerHTML = `<tr><th>Receipt</th><th>Date</th>${IS_ADMIN ? '<th>Cashier</th>' : ''}<th>Customer</th><th>Items</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr>` +
        (hist.map(s => `<tr><td><b>${esc(s.receipt_no)}</b></td><td class="nw">${esc(s.at)}</td>${IS_ADMIN ? `<td>${esc(s.cashier)}</td>` : ''}<td>${esc(s.customer_name) || '–'}</td><td>${s.units}</td>
         <td>${money(s.total)}${s.returned > 0 ? `<br><small>returned ${money(s.returned)}</small>` : ''}</td><td>${money(s.paid)}<br><small>${esc(s.method)}</small></td><td>${s.balance > 0 ? '<b class="dn">' + money(s.balance) + '</b>' : money(0)}</td><td>${badge(s)}</td>
         <td class="ac"><button class="xs" data-sl-view="${s.id}">View</button> <button class="xs" data-sl-rc="${s.id}">Receipt</button></td></tr>`).join('') || `<tr><td colspan="${IS_ADMIN ? 10 : 9}">No sales found.</td></tr>`);
    } catch (x) { $('#sh-h').textContent = x.message; }
  }
  async function viewSale(id) {
    const x = await call('s_get', null, qs({ id })); cur_sale = x;
    const canAdmin = IS_ADMIN && x.status !== 'cancelled', hasLeft = x.items.some(i => i.qty > i.returned_qty);
    $('#sl-dtb').innerHTML = `<h2>${esc(x.receipt_no)} ${badge(x)}</h2>
 <p>${esc(x.at)} · served by ${esc(x.cashier || '–')}${x.customer_name ? ' · ' + esc(x.customer_name) : ''}${x.customer_phone ? ' · ' + esc(x.customer_phone) : ''}${x.oversold ? ' · <b class="dn">sold beyond stock (admin override)</b>' : ''}</p>
 <div class="ow"><table class="tb"><tr><th>Item</th><th>Qty</th><th>Price</th><th>Disc.</th><th>Amount</th><th>Returned</th></tr>${x.items.map(i => `<tr><td>${esc(i.name)}</td><td>${i.qty}</td><td>${money(i.unit_price)}</td><td>${money(i.discount)}</td><td>${money(i.net)}</td><td>${i.returned_qty || ''}</td></tr>`).join('')}</table></div>
 <div class="sl-tot"><div><span>Subtotal</span><b>${money(x.subtotal)}</b></div><div><span>Discounts</span><b>− ${money(x.discount)}</b></div><div><span>${esc(x.store.tax_name)} ${x.tax_rate}%</span><b>${money(x.tax)}</b></div><div class="g"><span>Total</span><b>${money(x.total)}</b></div>
 ${x.returned > 0 ? `<div><span>Returned goods</span><b>− ${money(x.returned)}</b></div>` : ''}<div><span>Paid (net of refunds)</span><b>${money(x.paid)}</b></div><div><span>Balance</span><b>${money(Math.max(0, x.balance))}</b></div>${x.balance < 0 && x.status !== 'cancelled' ? `<div><span>Store credit owed to customer</span><b>${money(-x.balance)}</b></div>` : ''}</div>
 ${x.cancel_reason ? `<p class="al">Cancelled: ${esc(x.cancel_reason)}</p>` : ''}
 <h3 class="h3">Payments</h3><div class="ow"><table class="tb">${x.payments.map(p => `<tr><td>${esc(p.at)}</td><td>${money(p.amount)}</td><td>${esc(p.method)}${p.reference ? ' · ' + esc(p.reference) : ''}</td><td>${esc(p.by || '')}</td></tr>`).join('') || '<tr><td>No payment recorded.</td></tr>'}</table></div>
 ${x.returns.length ? `<h3 class="h3">Returns and refunds</h3><div class="ow"><table class="tb">${x.returns.map(r => `<tr><td>${esc(r.at)}</td><td>${esc(r.items || '')}</td><td>Value ${money(r.value)}<br>Refunded ${money(r.refund)}${r.refund_method ? ' (' + esc(r.refund_method) + ')' : ''}</td><td>${r.restock ? 'Back in stock' : 'Not restocked'}</td><td>${esc(r.reason)}<br><small>${esc(r.by || '')}</small></td></tr>`).join('')}</table></div>` : ''}
 <details><summary>Audit trail</summary><div class="ow"><table class="tb">${x.audit.map(a => `<tr><td class="nw">${esc(a.at)}</td><td>${esc(a.by || '')}</td><td><b>${esc(a.action)}</b> ${esc(a.detail)}</td></tr>`).join('')}</table></div></details>
 <div class="sl-btns"><button class="btn n" data-sl-rc="${x.id}">Receipt / Invoice</button>${x.status !== 'cancelled' && x.balance > 0 ? `<button class="btn" data-sl-pay="${x.id}">Add payment</button>` : ''}
 ${canAdmin && hasLeft ? `<button class="btn" data-sl-ret="${x.id}">Return / refund</button>` : ''}${canAdmin ? `<button class="btn dgo" data-sl-cx="${x.id}">Cancel sale</button>` : ''}</div>`;
    if (!$('#sl-dt').open) $('#sl-dt').showModal();
  }
  function actDialog(html) { $('#sl-actb').innerHTML = html + '<p id="sl-ae" role="alert" class="sl-err"></p>'; if (!$('#sl-act').open) $('#sl-act').showModal(); }
  const methodOpts = () => CFG.methods.map(x => `<option>${esc(x)}</option>`).join('');
  function payForm() {
    const x = cur_sale;
    actDialog(`<h2>Add payment</h2><p>${esc(x.receipt_no)} · balance ${money(x.balance)}</p>
     <label>Amount received<input id="sa-amt" type="number" min="0.01" step="0.01" max="${x.balance}" value="${x.balance}" inputmode="decimal"></label>
     <label>Method<select id="sa-m">${methodOpts()}</select></label><label>Reference<input id="sa-ref" maxlength="80"></label>
     <button class="btn n w" data-sl-dopay="${x.id}">Record payment</button><button type="button" class="lk" data-close>Cancel</button>`);
  }
  function retForm() {
    const x = cur_sale;
    actDialog(`<h2>Return / refund</h2><p>${esc(x.receipt_no)} · choose what the customer is bringing back.</p>
     <div class="ow"><table class="tb">${x.items.filter(i => i.qty > i.returned_qty).map(i => `<tr><td>${esc(i.name)}<br><small>${i.qty - i.returned_qty} can be returned</small></td><td><input class="sl-n" type="number" min="0" max="${i.qty - i.returned_qty}" step="1" value="0" data-sl-rq="${i.id}" aria-label="Return quantity of ${esc(i.name)}"></td></tr>`).join('')}</table></div>
     <label class="ck"><input type="checkbox" id="sr-rs" checked> Put the returned items back in stock (untick for damaged goods)</label>
     <label>Refund to customer<input id="sr-amt" type="number" min="0" step="0.01" value="0" inputmode="decimal"></label><p class="hint" id="sr-h">The refund is limited to what the customer has paid beyond what they still owe.</p>
     <label>Refund method<select id="sr-m">${methodOpts()}</select></label><label>Reason (required)<input id="sr-why" maxlength="300"></label>
     <button class="btn n w" data-sl-doret="${x.id}">Record return</button><button type="button" class="lk" data-close>Cancel</button>`);
    retPreview();
  }
  function retPreview() {
    const x = cur_sale; let ex = 0;
    document.querySelectorAll('[data-sl-rq]').forEach(i => {
      const it = x.items.find(z => z.id === +i.dataset.slRq), q = Math.max(0, Math.min(+i.value || 0, it.qty - it.returned_qty));
      if (q) ex += c100(it.net) * q / it.qty;
    });
    const value = Math.round(ex * (1 + x.tax_rate / 100)), max = Math.max(0, c100(x.paid) - (c100(x.total) - c100(x.returned) - value));
    $('#sr-amt').value = (max / 100).toFixed(2); $('#sr-h').textContent = 'Value of returned goods about ' + money(value / 100) + '. The refund can be up to ' + money(max / 100) + ' (what was paid beyond what is still owed).';
  }
  function cxForm() {
    const x = cur_sale;
    actDialog(`<h2>Cancel sale ${esc(x.receipt_no)}</h2><p>Everything not yet returned goes back into stock and the ${money(x.paid)} paid is refunded. The sale stays in the records as cancelled.</p>
     <label>Reason (required)<input id="sc-why" maxlength="300"></label>
     <button class="btn dg w" data-sl-docx="${x.id}">Cancel this sale</button><button type="button" class="lk" data-close>Keep the sale</button>`);
  }

  // ---------- REPORTS ----------
  function range(p) {
    const t = today(), d = new Date(t + 'T00:00:00Z'), dow = (d.getUTCDay() + 6) % 7;
    if (p === 'today') return [t, t]; if (p === 'yesterday') return [addDays(t, -1), addDays(t, -1)];
    if (p === 'week') return [addDays(t, -dow), t]; if (p === 'month') return [t.slice(0, 8) + '01', t];
    return [$('#sr-f').value || t, $('#sr-t').value || t];
  }
  function drawRep() {
    if ($('#sl-rep').dataset.ready) return; $('#sl-rep').dataset.ready = '1';
    $('#sl-rep').innerHTML = `<div class="tl"><span class="chips2" id="sr-p"></span><label class="sr" for="sr-f">From</label><input type="date" id="sr-f" class="sl-d"><label class="sr" for="sr-t">To</label><input type="date" id="sr-t" class="sl-d">
 <button class="btn" id="sr-go">Show</button><button class="btn" id="sr-csv">Download sales (CSV)</button><button class="btn" id="sr-csv2">Download items (CSV)</button></div>
 <p class="hint" id="sr-h"></p><div class="slk-row" id="sr-k"></div><div id="sr-b"></div>`;
    $('#sr-p').innerHTML = [['today', 'Today'], ['yesterday', 'Yesterday'], ['week', 'This week'], ['month', 'This month'], ['custom', 'Custom']].map(([k, l]) => `<button class="chip${period === k ? ' on' : ''}" data-sp="${k}">${l}</button>`).join('');
  }
  async function loadRep() {
    drawRep(); const [from, to] = range(period); $('#sr-f').value = from; $('#sr-t').value = to;
    document.querySelectorAll('#sr-p [data-sp]').forEach(b => b.classList.toggle('on', b.dataset.sp === period));
    try {
      report = await call('s_report', null, qs({ from, to })); const t = report.totals;
      $('#sr-h').textContent = (report.scope === 'mine' ? 'Your own sales' : 'All sales') + ', ' + from + (from === to ? '' : ' to ' + to) + '. Net sales = total charged (incl. tax) minus the value of returned goods. Cancelled sales count as fully returned.';
      $('#sr-k').innerHTML = [['Gross sales', money(t.gross)], ['Discounts', '− ' + money(t.discounts)], ['Tax', money(t.tax)], ['Returns', '− ' + money(t.returned)], ['Net sales', money(t.net), 'g'], ['Refunds paid out', money(t.refunds)],
        ['Transactions', t.n], ['Units sold', t.units], ['Average sale', money(t.avg)], ['Outstanding balances', money(t.outstanding), t.outstanding > 0 ? 'w' : '']]
        .map(([l, v, c]) => `<div class="slk ${c || ''}"><small>${l}</small><b>${esc(v)}</b></div>`).join('');
      const max = Math.max(1, ...report.days.map(d => d.net)), mu = Math.max(1, ...report.top.map(p => p.units));
      const bars = report.days.length ? `<div class="sl-chart" role="img" aria-label="Net sales per day">${report.days.map(d => `<div class="sl-bar" title="${esc(d.day)}: ${money(d.net)} (${d.n} sales)"><i style="height:${Math.max(2, d.net / max * 100)}%"></i><small>${esc(d.day.slice(5))}</small></div>`).join('')}</div>` : '<p class="hint">No sales in this period.</p>';
      $('#sr-b').innerHTML = `<h3 class="h3">Net sales per day</h3>${bars}
       <h3 class="h3">Top-selling products</h3><div class="ow"><table class="tb"><tr><th>Product</th><th>Units</th><th></th><th>Revenue</th></tr>${report.top.map(p => `<tr><td>${esc(p.name)}</td><td>${p.units}</td><td><div class="mbar"><i style="width:${p.units / mu * 100}%"></i></div></td><td>${money(p.revenue)}</td></tr>`).join('') || '<tr><td colspan="4">No sales.</td></tr>'}</table></div>
       <h3 class="h3">Money received by payment method</h3><div class="ow"><table class="tb"><tr><th>Method</th><th>Sales</th><th>Collected</th></tr>${report.methods.map(p => `<tr><td>${esc(p.method)}</td><td>${p.n}</td><td>${money(p.paid)}</td></tr>`).join('') || '<tr><td colspan="3">None.</td></tr>'}</table></div>
       ${report.cashiers.length ? `<h3 class="h3">By cashier</h3><div class="ow"><table class="tb"><tr><th>Cashier</th><th>Sales</th><th>Net sales</th></tr>${report.cashiers.map(p => `<tr><td>${esc(p.name)}</td><td>${p.n}</td><td>${money(p.net)}</td></tr>`).join('')}</table></div>` : ''}`;
    } catch (x) { $('#sr-h').textContent = x.message; }
  }

  // ---------- events ----------
  panel.addEventListener('input', ev => {
    const t = ev.target, d = t.dataset;
    if (t.id === 'sl-q') { clearTimeout(searchT); searchT = setTimeout(() => searchNow(), 220); return; }
    if (d.slQ) { const c = cart.find(x => x.id === +d.slQ); c.qty = Math.max(0, Math.floor(+t.value || 0)); key = newKey(); drawTotals(); drawRes(); const row = t.closest('tr'); if (row) row.querySelector('small:last-child').className = c.qty > c.stock ? 'sl-err' : ''; return; }
    if (d.slD) { cart.find(x => x.id === +d.slD).disc = t.value; key = newKey(); drawTotals(); return; }
    if (d.slRq) { retPreview(); return; }
    if (['sl-ed', 'sl-tx', 'sl-amt', 'sl-ten'].includes(t.id)) { key = newKey(); drawTotals(); }
  });
  panel.addEventListener('change', ev => {
    const t = ev.target;
    if (t.name === 'slpm' || t.id === 'sl-meth' || t.id === 'sl-ov') { key = newKey(); drawTotals(); }
  });
  panel.addEventListener('keydown', ev => { if (ev.target.id === 'sl-q' && ev.key === 'Enter') { ev.preventDefault(); clearTimeout(searchT); searchNow(true); } });
  panel.addEventListener('click', async ev => {
    const t = ev.target.closest('button'); if (!t) return;
    ev.stopPropagation();   // keep the page's own click handlers out of this panel
    const d = t.dataset;
    try {
      if ('close' in d) { t.closest('dialog').close(); return; }
      if (d.v) { await go(d.v); return; }
      if (d.add) { addToCart(+d.add); return; }
      if (d.slX) { cart = cart.filter(c => c.id !== +d.slX); key = newKey(); drawCart(); drawRes(); return; }
      if (t.id === 'sl-clear') { if (!cart.length || confirm('Clear this sale?')) { cart = []; key = newKey(); drawCart(); drawRes(); } return; }
      if (t.id === 'sl-review') { review(); return; }
      if (t.id === 'sl-ok') { await confirmSale(); return; }
      if (t.id === 'sl-print') { const w = $('#sl-fr').contentWindow; w.focus(); w.print(); return; }
      if (t.id === 'sl-wa') { whatsApp(); return; }
      if (t.id === 'sl-newsale') { $('#sl-rc').close(); await go('new'); return; }
      if (d.rm) { receiptMode = d.rm; drawReceipt(); return; }
      if (d.slRc) { await showReceipt(+d.slRc, false); return; }
      if (d.slView) { await viewSale(+d.slView); return; }
      if (t.id === 'sh-go') { await loadHist(); return; }
      if (t.id === 'sh-csv') { downloadCsv(histFilter()); return; }
      if (d.slPay) { payForm(); return; }
      if (d.slRet) { retForm(); return; }
      if (d.slCx) { cxForm(); return; }
      if (d.slDopay) {
        await call('s_pay', { id: +d.slDopay, amount: $('#sa-amt').value, method: $('#sa-m').value, reference: $('#sa-ref').value });
        $('#sl-act').close(); toast('Payment recorded.'); await viewSale(+d.slDopay); loadHist(); loadDash(); return;
      }
      if (d.slDoret) {
        const items = [...document.querySelectorAll('[data-sl-rq]')].map(i => ({ id: +i.dataset.slRq, qty: +i.value || 0 })).filter(i => i.qty > 0);
        await call('s_return', { id: +d.slDoret, items, restock: $('#sr-rs').checked, refund: $('#sr-amt').value, refund_method: $('#sr-m').value, reason: $('#sr-why').value });
        $('#sl-act').close(); toast('Return recorded.'); await viewSale(+d.slDoret); loadHist(); loadDash(); searchNow(); return;
      }
      if (d.slDocx) {
        await call('s_cancel', { id: +d.slDocx, reason: $('#sc-why').value });
        $('#sl-act').close(); toast('Sale cancelled.'); await viewSale(+d.slDocx); loadHist(); loadDash(); searchNow(); return;
      }
      if (d.sp) { period = d.sp; if (period !== 'custom') await loadRep(); else document.querySelectorAll('#sr-p [data-sp]').forEach(b => b.classList.toggle('on', b.dataset.sp === 'custom')); return; }
      if (t.id === 'sr-go') { period = 'custom'; await loadRep(); return; }
      if (t.id === 'sr-csv') { downloadCsv({ from: $('#sr-f').value, to: $('#sr-t').value }); return; }
      if (t.id === 'sr-csv2') { downloadCsv({ from: $('#sr-f').value, to: $('#sr-t').value, detail: 'items' }); return; }
    } catch (x) {
      const ae = $('#sl-act').open && $('#sl-ae'); if (ae) ae.textContent = x.message;
      else if ($('#sl-rv').open) $('#sl-rve').textContent = x.message; else alert(x.message);
    }
  });

  // show / hide with the tab bar
  tabs.addEventListener('click', ev => {
    const b = ev.target.closest('[data-t]'); if (!b) return;
    panel.hidden = b.dataset.t !== 'sl';
    if (b.dataset.t === 'sl') { nav(); go(view); loadDash(); }
  });
  nav();
})();
