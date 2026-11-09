// Leave tab, used on both pages:
//   worker.html: employees see their balances, request leave and cancel requests.
//   admin.html:  the manager approves or rejects, sees who is away, records leave and edits leave types.
// Uses helpers from the page: api, ME. Everything else is self-contained.
(() => {
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const adminTabs = $('#adm .tabs'), workerTabs = $('#app .tabs');
  const tabs = adminTabs || workerTabs;
  if (!tabs) return;
  const IS_ADMIN = !!adminTabs;

  const fd = s => new Date(s + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  const range = (a, b) => a === b ? fd(a) : fd(a) + ' → ' + fd(b);
  const n = x => String(+(+x).toFixed(1));
  const dayWord = x => n(x) + (+x === 1 ? ' day' : ' days');
  const ST = { pending: 'Waiting', approved: 'Approved', rejected: 'Rejected', cancelled: 'Cancelled' };
  const badge = s => `<span class="bd st-${s}">${ST[s] || esc(s)}</span>`;
  // working days between two dates, skipping the off days the server reports (1 = Monday ... 7 = Sunday)
  function countDays(a, b, off) {
    if (!a || !b || b < a) return 0;
    let c = 0;
    for (let d = new Date(a + 'T00:00:00'), e = new Date(b + 'T00:00:00'), i = 0; d <= e && i < 400; d.setDate(d.getDate() + 1), i++) {
      const iso = d.getDay() === 0 ? 7 : d.getDay();
      if (!off.includes(iso)) c++;
    }
    return c;
  }

  // ---------- tab button and panel ----------
  const btn = document.createElement('button');
  btn.className = 'chip'; btn.dataset.t = 'l';
  btn.innerHTML = IS_ADMIN ? 'Leave <span class="nbd" id="lm-badge" hidden></span>' : 'Leave';
  const after = tabs.querySelector(IS_ADMIN ? '[data-t="w"]' : '[data-t="v"]');
  after ? after.after(btn) : tabs.append(btn);

  const panel = document.createElement('div');
  panel.id = 'lm-panel'; panel.hidden = true;
  $(IS_ADMIN ? '#adm' : '#app').append(panel);

  const reqDialog = (who) => `
<dialog id="lm-dlg"><form id="lm-form"><h2 id="lm-dt">${who ? 'Record leave' : 'Request leave'}</h2>
 ${who ? '<label>Employee<select name="user_id" id="lm-emp" required></select></label>' : ''}
 <label>Leave type<select name="type_id" id="lm-type" required></select></label>
 <label>First day<input type="date" name="start" id="lm-start" required></label>
 <label>Last day<input type="date" name="end" id="lm-end" required></label>
 <p class="lvnote" id="lm-prev">Choose your dates.</p>
 <label>${who ? 'Note' : 'Reason (optional)'}<input name="reason" maxlength="300" placeholder="${who ? 'For example: sick, doctor’s note seen' : 'For example: family wedding'}"></label>
 <p id="lm-err" role="alert" style="color:#de1c24;font-weight:600"></p>
 <button class="btn n w">${who ? 'Save as approved' : 'Send request'}</button><button type="button" class="lk" data-close>Cancel</button></form></dialog>`;

  let D = null;   // data from the server (lv_mine for workers, lv_admin for the manager)
  let LF = 'all', LU = 0;

  function preview() {
    const a = $('#lm-start').value, b = $('#lm-end').value, p = $('#lm-prev');
    if (!a || !b) { p.textContent = 'Choose the first and last day.'; return; }
    if (b < a) { p.textContent = 'The last day is before the first day.'; return; }
    const c = countDays(a, b, D.off_days);
    p.textContent = c ? `That is ${dayWord(c)} of leave (days off such as Sundays are not counted).` : 'All of those dates are days off. No leave is needed.';
  }

  async function submitDialog(ev) {
    ev.preventDefault();
    const b = Object.fromEntries(new FormData(ev.target));
    b.type_id = +b.type_id; if (b.user_id) b.user_id = +b.user_id;
    try {
      await api(IS_ADMIN ? 'lv_admin_add' : 'lv_request', b);
      $('#lm-dlg').close(); await load();
    } catch (x) { $('#lm-err').textContent = x.message; }
  }

  // =====================================================================
  // EMPLOYEE VIEW (worker.html)
  // =====================================================================
  function drawMine() {
    panel.innerHTML = `
<div class="tl"><button class="btn n" id="lm-new">+ Request leave</button></div>
<div class="lvc">${D.types.map(t => `<div class="kc"><small>${esc(t.name)}${t.paid ? '' : ' (unpaid)'}</small>
  <b>${t.allowance > 0 ? n(t.left) + ' left' : n(t.used) + ' taken'}</b>
  <em>${t.allowance > 0 ? 'of ' + t.allowance + ' days in ' + D.year : 'no yearly limit'}${t.pending ? ' · ' + n(t.pending) + ' waiting' : ''}</em></div>`).join('')}</div>
<h3 class="h3">My requests</h3>
<div class="ow"><table class="tb" id="lm-mine">
 <tr><th>Dates</th><th>Type</th><th>Days</th><th>Status</th><th></th></tr>
 ${D.requests.length ? D.requests.map(r => `<tr><td>${esc(range(r.start, r.end))}${r.reason ? `<br><small>${esc(r.reason)}</small>` : ''}</td><td>${esc(r.type)}</td><td>${dayWord(r.days)}</td>
   <td>${badge(r.status)}${r.note ? `<br><small>${esc(r.note)}</small>` : ''}</td>
   <td class="ac">${r.can_cancel ? `<button class="xs" data-lc="${r.id}">Cancel</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="5"><small>You have not requested any leave yet.</small></td></tr>'}
</table></div>${reqDialog(false)}`;
    $('#lm-form').addEventListener('submit', submitDialog);
    ['#lm-start', '#lm-end'].forEach(s => $(s).addEventListener('change', preview));
    $('#lm-start').addEventListener('change', () => { if (!$('#lm-end').value || $('#lm-end').value < $('#lm-start').value) $('#lm-end').value = $('#lm-start').value; preview(); });
  }

  function openRequest() {
    $('#lm-form').reset(); $('#lm-err').textContent = '';
    $('#lm-type').innerHTML = D.types.map(t => `<option value="${t.id}">${esc(t.name)}${t.allowance > 0 ? ' (' + n(t.left) + ' left)' : ''}</option>`).join('');
    $('#lm-start').min = $('#lm-end').min = D.today;
    preview(); $('#lm-dlg').showModal();
  }

  // =====================================================================
  // MANAGER VIEW (admin.html)
  // =====================================================================
  function setBadge(c) { const b = $('#lm-badge'); if (b) { b.hidden = !c; b.textContent = c; } }

  function drawAdmin() {
    const away = D.away, now = away.filter(a => a.now);
    setBadge(D.pending.length);
    panel.innerHTML = `
<div class="tl"><button class="btn n" id="lm-add">+ Record leave</button><button class="btn" id="lm-types">Leave types</button></div>
<div class="kp">
 <div class="kc ${D.pending.length ? 'warn' : ''}"><small>Waiting for your decision</small><b>${D.pending.length}</b></div>
 <div class="kc"><small>Away today</small><b>${now.length}</b>${now.length ? `<small>${esc(now.map(a => a.employee).join(', '))}</small>` : ''}</div>
 <div class="kc"><small>Away in the next 60 days</small><b>${away.length}</b></div>
</div>
<h3 class="h3">Requests to approve</h3>
${D.pending.length ? D.pending.map(r => `<div class="lvq"><div><b>${esc(r.employee)}</b> · ${esc(r.type)} · ${esc(range(r.start, r.end))} <b>(${dayWord(r.days)})</b></div>
  ${r.reason ? `<div>${esc(r.reason)}</div>` : ''}<small>Asked on ${esc(r.asked)}${r.left !== null ? ' · ' + n(r.left) + ' days of ' + esc(r.type) + ' left this year' : ''}</small>
  <div class="ac"><button class="btn n" data-la="${r.id}">Approve</button><button class="btn" data-lr="${r.id}">Reject</button></div></div>`).join('') : '<p class="hint">No requests are waiting.</p>'}
<h3 class="h3">Who is away</h3>
<div class="ow"><table class="tb">${away.length ? '<tr><th>Employee</th><th>Dates</th><th>Type</th><th>Days</th></tr>' + away.map(a => `<tr><td>${esc(a.employee)}${a.now ? ' <span class="bd st-pending">away now</span>' : ''}</td><td>${esc(range(a.start_date, a.end_date))}</td><td>${esc(a.type)}</td><td>${dayWord(a.days)}</td></tr>`).join('') : '<tr><td><small>Nobody is on approved leave in the next 60 days.</small></td></tr>'}</table></div>
<h3 class="h3">Leave used in ${D.year}</h3>
<p class="hint">Approved days only. Employees are your worker accounts; add them in the Workers tab.</p>
<div class="ow"><table class="tb">${D.workers.length ? '<tr><th>Employee</th>' + D.types.filter(t => t.active).map(t => `<th>${esc(t.name)}</th>`).join('') + '</tr>' + D.workers.map(w => `<tr><td>${esc(w.name)}</td>${D.types.filter(t => t.active).map(t => {
    const u = (D.balances[w.id] || {})[t.id] || 0, over = t.days_per_year > 0 && u >= t.days_per_year;
    return `<td><b class="${over ? 'dn' : ''}">${n(u)}</b>${t.days_per_year > 0 ? ' <small>/ ' + t.days_per_year + '</small>' : ''}</td>`;
  }).join('')}</tr>`).join('') : '<tr><td><small>No active workers yet.</small></td></tr>'}</table></div>
<h3 class="h3">All requests</h3>
<div class="tl"><span class="chips2">${[['all', 'All'], ['pending', 'Waiting'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['cancelled', 'Cancelled']].map(([k, l]) => `<button class="chip${LF === k ? ' on' : ''}" data-lf="${k}">${l}</button>`).join('')}</span>
 <label class="sr" for="lm-who">Employee</label><select id="lm-who" style="width:auto;margin:0"><option value="0">All employees</option>${D.workers.map(w => `<option value="${w.id}"${LU == w.id ? ' selected' : ''}>${esc(w.name)}</option>`).join('')}</select></div>
<div class="ow"><table class="tb"><tr><th>Employee</th><th>Dates</th><th>Type</th><th>Days</th><th>Status</th><th></th></tr>
 ${D.history.length ? D.history.map(r => `<tr><td>${esc(r.employee)}</td><td>${esc(range(r.start, r.end))}${r.reason ? `<br><small>${esc(r.reason)}</small>` : ''}</td><td>${esc(r.type)}</td><td>${dayWord(r.days)}</td>
  <td>${badge(r.status)}${r.note ? `<br><small>${esc(r.note)}</small>` : ''}</td>
  <td class="ac">${r.status === 'pending' || r.status === 'approved' ? `<button class="xs" data-lx="${r.id}">Cancel</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="6"><small>Nothing to show.</small></td></tr>'}</table></div>
${reqDialog(true)}
<dialog id="lm-td" class="wide"><h2>Leave types</h2>
 <p class="hint">Days per year is each employee’s yearly allowance; 0 means no limit (for example unpaid leave). A request counts in the year its first day falls in. Switch a type off to stop new requests without losing history.</p>
 <div class="ow"><table class="tb" id="lm-tt"></table></div><p id="lm-terr" role="alert" style="color:#de1c24;font-weight:600"></p>
 <button type="button" class="lk" data-close>Close</button></dialog>`;
    $('#lm-form').addEventListener('submit', submitDialog);
    ['#lm-start', '#lm-end'].forEach(s => $(s).addEventListener('change', preview));
    $('#lm-start').addEventListener('change', () => { if (!$('#lm-end').value || $('#lm-end').value < $('#lm-start').value) $('#lm-end').value = $('#lm-start').value; preview(); });
    $('#lm-who').addEventListener('change', ev => { LU = +ev.target.value; load(); });
  }

  function openRecord() {
    if (!D.workers.length) { alert('Add a worker first (Workers tab). Employees are your worker accounts.'); return; }
    $('#lm-form').reset(); $('#lm-err').textContent = '';
    $('#lm-emp').innerHTML = D.workers.map(w => `<option value="${w.id}">${esc(w.name)}</option>`).join('');
    $('#lm-type').innerHTML = D.types.filter(t => t.active).map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
    $('#lm-start').removeAttribute('min'); $('#lm-end').removeAttribute('min');
    preview(); $('#lm-dlg').showModal();
  }

  function drawTypes() {
    const row = t => `<tr data-id="${t.id}"><td><input name="name" value="${esc(t.name)}" maxlength="60" aria-label="Leave type name"></td>
      <td><input name="days_per_year" type="number" min="0" max="365" step="1" value="${t.days_per_year}" aria-label="Days per year"></td>
      <td><input type="checkbox" name="paid" ${t.paid ? 'checked' : ''} aria-label="Paid" style="width:auto;display:inline-block"></td>
      <td><input type="checkbox" name="active" ${t.active ? 'checked' : ''} aria-label="Active" style="width:auto;display:inline-block"></td>
      <td class="ac"><button class="xs" data-ts="${t.id}">${t.id ? 'Save' : 'Add'}</button>${t.id ? ` <button class="xs" data-td="${t.id}">Delete</button>` : ''}</td></tr>`;
    $('#lm-tt').innerHTML = '<tr><th>Name</th><th>Days / year</th><th>Paid</th><th>Active</th><th></th></tr>' + D.types.map(row).join('') + row({ id: 0, name: '', days_per_year: 0, paid: true, active: true }).replace('value=""', 'value="" placeholder="New leave type"');
  }

  // ---------- load and draw ----------
  async function load() {
    try {
      if (IS_ADMIN) { D = await api('lv_admin', null, '&status=' + LF + '&user_id=' + LU); drawAdmin(); }
      else { D = await api('lv_mine'); drawMine(); }
    } catch (x) { alert(x.message); }
  }

  // ---------- clicks ----------
  panel.addEventListener('click', async ev => {
    const t = ev.target.closest('button'); if (!t) return;
    ev.stopPropagation();                      // keep the page's own click handlers out of this panel
    const d = t.dataset;
    try {
      if ('close' in d) { t.closest('dialog').close(); return; }
      if (t.id === 'lm-new') { openRequest(); return; }
      if (t.id === 'lm-add') { openRecord(); return; }
      if (t.id === 'lm-types') { $('#lm-terr').textContent = ''; drawTypes(); $('#lm-td').showModal(); return; }
      if (d.lc) { if (confirm('Cancel this leave request?')) { await api('lv_cancel', { id: +d.lc }); await load(); } return; }
      if (d.la) { await api('lv_decide', { id: +d.la, decision: 'approve' }); await load(); return; }
      if (d.lr) {
        const note = prompt('Why is this being rejected? The employee will see this.', '');
        if (note === null) return;
        await api('lv_decide', { id: +d.lr, decision: 'reject', note }); await load(); return;
      }
      if (d.lx) { if (confirm('Cancel this leave? The employee’s days go back to their balance.')) { await api('lv_admin_cancel', { id: +d.lx }); await load(); } return; }
      if (d.lf) { LF = d.lf; await load(); return; }
      if (d.ts !== undefined) {
        const tr = t.closest('tr'), g = nm => tr.querySelector(`[name="${nm}"]`);
        $('#lm-terr').textContent = '';
        await api('lv_type_save', { id: +d.ts, name: g('name').value.trim(), days_per_year: g('days_per_year').value, paid: g('paid').checked, active: g('active').checked });
        await load(); drawTypes(); $('#lm-td').showModal(); return;
      }
      if (d.td) {
        const ty = D.types.find(x => x.id == d.td);
        if (!confirm(`Delete the leave type “${ty.name}”?`)) return;
        $('#lm-terr').textContent = '';
        await api('lv_type_delete', { id: ty.id }); await load(); drawTypes(); $('#lm-td').showModal(); return;
      }
    } catch (x) {
      if ($('#lm-td') && $('#lm-td').open) $('#lm-terr').textContent = x.message; else alert(x.message);
    }
  });

  // show / hide with the tab bar
  tabs.addEventListener('click', ev => {
    const b = ev.target.closest('[data-t]'); if (!b) return;
    panel.hidden = b.dataset.t !== 'l';
    if (b.dataset.t === 'l') load();
  });

  // the manager sees a count of waiting requests on the tab, checked every minute
  if (IS_ADMIN) {
    const ping = async () => {
      if (document.hidden || typeof ME === 'undefined' || !ME || ME.role !== 'admin') return;
      try { setBadge((await api('lv_count')).pending); } catch (x) { /* ignore */ }
    };
    setInterval(ping, 60000); setTimeout(ping, 4000);
  }
})();
