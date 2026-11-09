// Expenses tab of admin.html: record expenses, see where the money goes, track budgets.
// Uses the helpers defined in admin.html: api, m (money), S (store settings).
(() => {
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const tabs = $('#adm .tabs');
  if (!tabs) return;

  let X = null;                      // last reply from x_list
  let month = new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 7);

  // ---------- markup (built here so admin.html needs no big edits) ----------
  const btn = document.createElement('button');
  btn.className = 'chip'; btn.dataset.t = 'x'; btn.textContent = 'Expenses';
  const inv = tabs.querySelector('[data-t="v"]');
  inv ? inv.after(btn) : tabs.append(btn);

  const panel = document.createElement('div');
  panel.id = 'ex-panel'; panel.hidden = true;
  panel.innerHTML = `
<div class="tl"><span class="mdate"><label class="sr" for="ex-month">Month</label><input type="month" id="ex-month" value="${month}"></span>
 <button class="btn n" id="ex-add">+ Add expense</button><button class="btn" id="ex-cats">Categories &amp; budgets</button>
 <button class="btn" id="ex-csv">Export this month</button><button class="btn" id="ex-csvall">Export all</button></div>
<div class="al" id="ex-alert" hidden></div>
<div class="kp" id="ex-kp"></div>
<h3 class="h3">Spending by category</h3>
<div class="ow"><table class="tb" id="ex-cat"></table></div>
<h3 class="h3">Last 6 months</h3>
<p class="hint">Sales come from your stock log: online orders and in-store sales, minus cancelled orders. Net is sales minus expenses; it does not subtract the cost of the stock itself.</p>
<div class="ow"><table class="tb" id="ex-trend"></table></div>
<h3 class="h3" id="ex-lh">Expenses</h3>
<div class="ow"><table class="tb" id="ex-list"></table></div>

<dialog id="ex-dlg"><form id="ex-form"><h2 id="ex-dt">Add expense</h2><input type="hidden" name="id">
 <label>Date<input name="spent_on" type="date" required></label>
 <label>Category<select name="category" id="ex-catsel" required></select></label>
 <label>Amount<input name="amount" type="number" step="0.01" min="0.01" required inputmode="decimal"></label>
 <label>What was it for?<input name="description" maxlength="200" placeholder="For example: shop rent for October"></label>
 <label>Paid to (vendor)<input name="vendor" maxlength="120"></label>
 <label>Paid by<select name="method" id="ex-meth"></select></label>
 <label>Receipt or reference number<input name="reference" maxlength="80"></label>
 <p id="ex-err" role="alert" style="color:#de1c24;font-weight:600"></p>
 <button class="btn n w">Save expense</button><button type="button" class="lk" data-close>Cancel</button></form></dialog>

<dialog id="ex-cd" class="wide"><h2>Categories &amp; monthly budgets</h2>
 <p class="hint">Set what you plan to spend per month in each category (0 means no budget). Renaming a category renames it on all its expenses. A category with expenses cannot be deleted.</p>
 <div class="ow"><table class="tb" id="ex-ct"></table></div>
 <p id="ex-cerr" role="alert" style="color:#de1c24;font-weight:600"></p>
 <button type="button" class="lk" data-close>Close</button></dialog>`;
  $('#adm').append(panel);

  // ---------- helpers ----------
  const day = s => new Date(s + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
  const mon = s => new Date(s + '-01T00:00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  const monShort = s => new Date(s + '-01T00:00:00').toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
  const bar = (pct, cls = '') => `<div class="mbar ${cls}"><i style="width:${Math.min(100, Math.max(0, pct))}%"></i></div>`;
  const level = (spent, budget) => budget > 0 ? (spent > budget ? 'o' : spent >= budget * 0.8 ? 'w' : '') : '';

  async function load() {
    try { X = await api('x_list', null, '&month=' + encodeURIComponent(month)); draw(); }
    catch (x) { alert(x.message); }
  }

  function draw() {
    if (!X) return;
    const net = X.sales - X.total;
    $('#ex-kp').innerHTML = [
      ['Spent in ' + mon(X.month), m(X.total), '', ''],
      ['Monthly budget', X.budget > 0 ? m(X.budget) : 'Not set', X.budget > 0 ? (X.total > X.budget ? 'over by ' + m(X.total - X.budget) : m(X.budget - X.total) + ' left') : 'Set budgets in Categories', X.budget > 0 && X.total > X.budget ? 'bad' : ''],
      ['Sales', m(X.sales), 'online + in-store', ''],
      ['Net (sales − expenses)', (net < 0 ? '−' : '') + m(Math.abs(net)), '', net < 0 ? 'bad' : ''],
    ].map(([l, v, note, cls]) => `<div class="kc ${cls}"><small>${esc(l)}</small><b>${esc(v)}</b>${note ? `<small>${esc(note)}</small>` : ''}</div>`).join('');

    const over = X.categories.filter(c => c.budget > 0 && c.spent > c.budget);
    const near = X.categories.filter(c => c.budget > 0 && c.spent <= c.budget && c.spent >= c.budget * 0.8);
    const al = $('#ex-alert');
    al.hidden = !(over.length || near.length);
    al.textContent = [over.length ? 'Over budget: ' + over.map(c => `${c.name} (+${m(c.spent - c.budget)})`).join(', ') + '.' : '',
      near.length ? 'Close to the limit: ' + near.map(c => c.name).join(', ') + '.' : ''].filter(Boolean).join(' ');

    const rows = X.categories.filter(c => c.spent > 0 || c.budget > 0).sort((a, b) => b.spent - a.spent || a.name.localeCompare(b.name));
    $('#ex-cat').innerHTML = '<tr><th>Category</th><th>Spent</th><th>Budget</th><th>Used</th></tr>' + (rows.length ? rows.map(c => {
      const p = c.budget > 0 ? c.spent / c.budget * 100 : 0, lv = level(c.spent, c.budget);
      const share = X.total > 0 ? Math.round(c.spent / X.total * 100) : 0;
      return `<tr><td>${esc(c.name)}<br><small>${c.n} expense${c.n === 1 ? '' : 's'}${X.total > 0 && c.spent > 0 ? ' · ' + share + '% of spending' : ''}</small></td><td><b>${m(c.spent)}</b></td><td>${c.budget > 0 ? m(c.budget) : '<small>no budget</small>'}</td>
        <td><div class="mb">${bar(c.budget > 0 ? p : (X.total > 0 ? c.spent / X.total * 100 : 0), lv)}${c.budget > 0 ? `<span class="mpct ${lv}">${Math.round(p)}%</span>` : ''}</div></td></tr>`;
    }).join('') : '<tr><td colspan="4"><small>No expenses or budgets yet for this month. Press “+ Add expense”.</small></td></tr>');

    const mx = Math.max(1, ...X.trend.map(t => Math.max(t.expenses, t.sales)));
    $('#ex-trend').innerHTML = '<tr><th>Month</th><th>Expenses</th><th>Sales</th><th>Net</th><th></th></tr>' + X.trend.map(t => {
      const n = t.sales - t.expenses;
      return `<tr class="mtrow"><td>${esc(monShort(t.month))}</td><td>${m(t.expenses)}</td><td>${m(t.sales)}</td><td><b class="${n < 0 ? 'dn' : 'up'}">${n < 0 ? '−' : ''}${m(Math.abs(n))}</b></td><td style="width:30%"><div class="mb">${bar(t.expenses / mx * 100, 'w')}</div></td></tr>`;
    }).join('');

    $('#ex-lh').textContent = 'Expenses in ' + mon(X.month) + ' (' + X.items.length + ')';
    $('#ex-list').innerHTML = '<tr><th>Date</th><th>Category</th><th>Details</th><th>Paid by</th><th>Amount</th><th></th></tr>' + (X.items.length ? X.items.map(i => `<tr>
      <td>${esc(day(i.spent_on))}</td><td>${esc(i.category)}</td>
      <td>${esc(i.description) || '<small>—</small>'}${i.vendor ? `<br><small>${esc(i.vendor)}</small>` : ''}</td>
      <td>${esc(i.method)}${i.reference ? `<br><small>${esc(i.reference)}</small>` : ''}</td>
      <td><b>${m(i.amount)}</b></td>
      <td class="ac"><button class="xs" data-xe="${i.id}">Edit</button> <button class="xs" data-xd="${i.id}">Delete</button></td></tr>`).join('')
      : '<tr><td colspan="6"><small>Nothing recorded this month.</small></td></tr>');
  }

  // ---------- add / edit an expense ----------
  function openForm(x) {
    const f = $('#ex-form');
    f.reset(); $('#ex-err').textContent = '';
    $('#ex-catsel').innerHTML = X.categories.map(c => `<option>${esc(c.name)}</option>`).join('');
    $('#ex-meth').innerHTML = X.methods.map(c => `<option>${esc(c)}</option>`).join('');
    f.elements.id.value = x ? x.id : '';
    $('#ex-dt').textContent = x ? 'Edit expense' : 'Add expense';
    // a new expense defaults to today if we are looking at the current month, otherwise to the first day of the month being viewed
    f.elements.spent_on.value = x ? x.spent_on : (X.today.slice(0, 7) === X.month ? X.today : X.month + '-01');
    if (x) {
      f.elements.category.value = x.category; f.elements.amount.value = x.amount;
      f.elements.description.value = x.description; f.elements.vendor.value = x.vendor;
      f.elements.method.value = x.method; f.elements.reference.value = x.reference;
    }
    $('#ex-dlg').showModal();
  }

  $('#ex-form').addEventListener('submit', async ev => {
    ev.preventDefault();
    const f = ev.target, b = Object.fromEntries(new FormData(f));
    b.id = +b.id || 0;
    try { await api('x_save', b); $('#ex-dlg').close(); await load(); }
    catch (x) { $('#ex-err').textContent = x.message; }
  });

  // ---------- categories and budgets ----------
  function drawCats() {
    $('#ex-ct').innerHTML = '<tr><th>Category</th><th>Monthly budget</th><th></th></tr>' + X.categories.map(c => `<tr>
      <td><input data-cn="${c.id}" value="${esc(c.name)}" maxlength="60" aria-label="Category name"></td>
      <td><input data-cb="${c.id}" type="number" min="0" step="0.01" value="${c.budget}" aria-label="Monthly budget"></td>
      <td class="ac"><button class="xs" data-cs="${c.id}">Save</button> <button class="xs" data-cx="${c.id}">Delete</button></td></tr>`).join('')
      + `<tr><td><input data-cn="0" placeholder="New category" maxlength="60" aria-label="New category name"></td><td><input data-cb="0" type="number" min="0" step="0.01" placeholder="0" aria-label="Budget"></td><td class="ac"><button class="xs" data-cs="0">Add</button></td></tr>`;
  }

  // ---------- one click handler for the whole panel ----------
  panel.addEventListener('click', async ev => {
    const t = ev.target.closest('button'); if (!t) return;
    ev.stopPropagation();                        // keep admin.html's own handlers out of this panel
    const d = t.dataset;
    try {
      if ('close' in d) { t.closest('dialog').close(); return; }
      if (t.id === 'ex-add') { openForm(null); return; }
      if (t.id === 'ex-cats') { $('#ex-cerr').textContent = ''; drawCats(); $('#ex-cd').showModal(); return; }
      if (t.id === 'ex-csv') { location.href = 'api.php?action=x_export&month=' + encodeURIComponent(month); return; }
      if (t.id === 'ex-csvall') { location.href = 'api.php?action=x_export&month=all'; return; }
      if (d.xe) { openForm(X.items.find(i => i.id == d.xe)); return; }
      if (d.xd) {
        const i = X.items.find(i => i.id == d.xd);
        if (!confirm(`Delete this expense (${i.category}, ${m(i.amount)})? This cannot be undone.`)) return;
        await api('x_delete', { id: i.id }); await load(); return;
      }
      if (d.cs !== undefined) {
        const id = +d.cs, name = $(`[data-cn="${id}"]`).value.trim(), budget = $(`[data-cb="${id}"]`).value;
        $('#ex-cerr').textContent = '';
        await api('x_cat_save', { id, name, budget }); await load(); drawCats(); return;
      }
      if (d.cx) {
        const c = X.categories.find(c => c.id == d.cx);
        if (!confirm(`Delete the category “${c.name}”?`)) return;
        $('#ex-cerr').textContent = '';
        await api('x_cat_delete', { id: c.id }); await load(); drawCats(); return;
      }
    } catch (x) {
      if ($('#ex-cd').open) $('#ex-cerr').textContent = x.message; else alert(x.message);
    }
  });

  $('#ex-month').addEventListener('change', ev => { if (ev.target.value) { month = ev.target.value; load(); } });

  // show / hide with the tab bar (admin.html hides its own panels; this one is ours)
  tabs.addEventListener('click', ev => {
    const b = ev.target.closest('[data-t]'); if (!b) return;
    panel.hidden = b.dataset.t !== 'x';
    if (b.dataset.t === 'x') load();
  });
})();
