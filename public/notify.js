// Notification bell + profile pill. Works on the store (customers and visitors), the worker page and the admin page.
// Needs the page's own helpers: api() and, when present, toast(), user (store) or ME (staff pages).
(() => {
  const D = document, $ = s => D.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const store = !!$('header.hd'), admin = !!$('#adm'), mode = store ? 'store' : admin ? 'admin' : 'worker';
  const me = () => { try { return store ? (typeof user !== 'undefined' ? user : null) : (typeof ME !== 'undefined' ? ME : null); } catch (x) { return null; } };
  const ICON = { product: '🆕', mine_order: '📦', mine_pay: '💰', mine_paid: '✅', order_paid: '💰', order: '🛒', stock: '⚠️', mine_assign: '📌', quote: '📝', invoice: '💳', leave: '🌴', mine_leave: '🌴', expense: '🧾', user: '👤', worker: '👷' };
  const icon = k => ICON[k] || ICON[k.split('_')[0]] || '🔔';
  const BELL = '<svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';
  const CHEV = '<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>';
  const USER = '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
  const initials = n => { const w = String(n || '').trim().split(/\s+/).filter(Boolean); return (w.length > 1 ? w[0][0] + w[1][0] : (w[0] || '').slice(0, 2)).toUpperCase(); };
  const avHtml = u => u.photo ? `<img src="${esc(u.photo)}" alt="">` : esc(initials(u.name));
  const ago = ts => { const s = Math.max(0, Math.floor(Date.now() / 1000) - ts); if (s < 60) return 'Just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; if (s < 86400) return Math.floor(s / 3600) + ' h ago'; return Math.floor(s / 86400) + ' d ago'; };

  let items = [], seen = 0, cleared = 0, allTop = 0, lastId = null, key = '', uidNow = undefined;
  let pill, bell, count, panel, list, menu, nameEl, avEl;

  // ---------- build ----------
  function build() {
    const bellHtml = `<button class="nfb" type="button" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">${BELL}<i class="nfc" hidden></i></button><span class="nfd"></span>`;
    const panelHtml = `<div class="nfp" role="dialog" aria-label="Notifications" hidden><div class="nfh"><b>Notifications</b><span class="nfa"><button type="button" class="nfr" data-a="read">Mark all as read</button><button type="button" class="nfr" data-a="clear">Clear all</button></span></div><div class="nfl"></div></div>`;
    if (store) {
      pill = $('.hd .dd.r'); if (!pill) return false;
      const hb = pill.querySelector('.hb');
      pill.classList.add('nfpill');
      hb.innerHTML = `<span class="nfn"><b id="au">Sign in</b>${CHEV}</span><span class="nfav" id="nfav">${USER}</span>`;
      const mn = pill.querySelector('.mn'); if (mn) mn.insertAdjacentHTML('beforeend', '<button type="button" data-dp="chg" hidden>Change profile photo</button><button type="button" data-dp="del" hidden>Remove profile photo</button>');
      pill.insertAdjacentHTML('afterbegin', bellHtml);
      pill.insertAdjacentHTML('beforeend', panelHtml);
      nameEl = $('#au'); avEl = $('#nfav');
    } else {
      const hdr = $('header .hdr'); if (!hdr) return false;
      pill = D.createElement('div'); pill.className = 'nfpill'; pill.id = 'nfpill'; pill.hidden = true;
      pill.innerHTML = bellHtml + `<button type="button" class="nfu" aria-haspopup="true" aria-expanded="false"><span class="nfn"><b></b>${CHEV}</span><span class="nfav"></span></button>` + panelHtml + `<div class="nfm" hidden></div>`;
      const tg = $('#tg'); tg ? tg.after(pill) : hdr.append(pill);
      nameEl = pill.querySelector('.nfn b'); avEl = pill.querySelector('.nfav'); menu = pill.querySelector('.nfm');
    }
    bell = pill.querySelector('.nfb'); count = pill.querySelector('.nfc'); panel = pill.querySelector('.nfp'); list = pill.querySelector('.nfl');
    return true;
  }

  // ---------- state ----------
  const seenKey = () => 'nf-seen:' + (uidNow || 'guest');
  function loadSeen() {
    key = seenKey(); let v = null; try { v = localStorage.getItem(key); } catch (x) { /* private mode */ }
    seen = v === null ? null : +v || 0;
    try { cleared = +localStorage.getItem('nf-clear:' + (uidNow || 'guest')) || 0; } catch (x) { cleared = 0; }
  }
  function saveClear(n) { cleared = n; try { localStorage.setItem('nf-clear:' + (uidNow || 'guest'), String(n)); } catch (x) { /* ignore */ } }
  function saveSeen(n) { seen = n; try { localStorage.setItem(key, String(n)); } catch (x) { /* ignore */ } }
  const maxId = () => items.reduce((a, i) => Math.max(a, i.id), 0);
  const unread = () => items.filter(i => i.id > (seen || 0)).length;

  function paint() {
    const n = unread();
    count.hidden = !n; count.textContent = n > 99 ? '99+' : n;
    bell.setAttribute('aria-label', n ? n + ' unread notifications' : 'Notifications');
    const u = me();
    if (store) { nameEl.textContent = u ? String(u.name).split(' ')[0].slice(0, 14) : 'Sign in'; avEl.innerHTML = u ? avHtml(u) : USER; pill.querySelectorAll('[data-dp]').forEach(b => { b.hidden = !u || (b.dataset.dp == 'del' && !u.photo); }); }
    else { pill.hidden = !u; if (u) { nameEl.textContent = String(u.name).split(' ')[0]; avEl.innerHTML = avHtml(u); } }
    if (!panel.hidden) drawList();
  }
  function drawList() {
    const empty = mode === 'store' ? 'You’re all caught up. We’ll tell you here when new products arrive or your order moves.' : 'No activity yet. New orders and updates will appear here.';
    const cl = pill.querySelector('[data-a=clear]'); if (cl) cl.hidden = !items.length;
    list.innerHTML = items.length ? items.map(i => `<button type="button" class="nfi${i.id > (seen || 0) ? ' un' : ''}" data-id="${i.id}"><span class="nfe">${icon(i.kind)}</span><span class="nft"><b>${esc(i.title)}</b>${i.body ? `<span>${esc(i.body)}</span>` : ''}<small>${ago(i.ts)}</small></span></button>`).join('') : `<div class="nfx">${empty}</div>`;
  }

  // ---------- data ----------
  async function poll() {
    if (D.hidden || !pill) return;
    try {
      const r = await api('notifs'); const all = r.items || []; allTop = all.reduce((a, i) => Math.max(a, i.id), 0); items = all.filter(i => i.id > cleared);
      if (seen === null) { const old = all.filter(i => i.ts < Date.now() / 1000 - 3 * 86400); saveSeen(old.length ? Math.max(...old.map(i => i.id)) : 0); }
      const top = allTop;
      if (lastId !== null && top > lastId && panel.hidden) {
        const fresh = items.filter(i => i.id > lastId); bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring');
        if (typeof toast === 'function' && fresh[0]) toast(fresh[0].title);
      }
      lastId = top; paint();
    } catch (x) { /* offline: keep what we have */ }
  }
  function sync() {
    const u = me(), id = u ? u.id : 0;
    if (id !== uidNow) { uidNow = id; items = []; lastId = null; loadSeen(); closeAll(); paint(); poll(); }
    else if (pill && !store) paint();
  }

  // ---------- open / close ----------
  function closeAll() {
    if (!panel) return;
    if (!panel.hidden) { panel.hidden = true; bell.setAttribute('aria-expanded', 'false'); saveSeen(Math.max(seen || 0, allTop)); paint(); }
    if (menu) { menu.hidden = true; const u = pill.querySelector('.nfu'); if (u) u.setAttribute('aria-expanded', 'false'); }
  }
  function openPanel() {
    if (menu) menu.hidden = true;
    if (store) pill.classList.remove('open');
    panel.hidden = false; bell.setAttribute('aria-expanded', 'true'); drawList(); poll();
  }
  function buildMenu() {
    const u = me(); if (!u) return;
    const row = (id, t, cls) => `<button type="button" data-m="${id}"${cls ? ` class="${cls}"` : ''}>${t}</button>`;
    menu.innerHTML = `<div class="nfw"><b>${esc(u.name)}</b><small>${esc(u.role === 'admin' ? 'Administrator' : 'Worker')}</small></div>`
      + (u.role === 'admin' ? row('settings', 'Settings') : '') + row('photo', u.photo ? 'Change profile photo' : 'Add profile photo') + (u.photo ? row('rmphoto', 'Remove profile photo') : '') + row('pw', 'Change password') + row('store', 'Go to the store') + row('out', 'Sign out', 'nfo');
  }
  function go(link) {
    if (!link) return;
    if (link.startsWith('tab:')) { const b = $('[data-t="' + link.slice(4) + '"]'); if (b) b.click(); }
    else if (link === 'orders') { const b = $('[data-o="orders"]'); if (b) b.click(); }
    else if (link.startsWith('s:')) {
      const q = $('#q'); if (!q) return; q.value = link.slice(2);
      const f = $('#sf'); if (f) f.requestSubmit ? f.requestSubmit() : f.dispatchEvent(new Event('submit', { cancelable: true }));
      const g = $('#bgrid'); (q.closest('header') || q).scrollIntoView({ behavior: 'smooth' });
    }
  }

  function wire() {
    bell.addEventListener('click', ev => { ev.stopPropagation(); panel.hidden ? openPanel() : closeAll(); });
    // "Clear all" hides everything up to now on this device (read state is kept per browser, like the unread count).
    pill.querySelector('.nfa').addEventListener('click', ev => {
      const b = ev.target.closest('[data-a]'); if (!b) return; ev.stopPropagation();
      if (b.dataset.a == 'read') saveSeen(Math.max(seen || 0, allTop));
      else { saveClear(Math.max(cleared, allTop)); saveSeen(Math.max(seen || 0, allTop)); items = []; }
      paint();
    });
    list.addEventListener('click', ev => {
      const b = ev.target.closest('.nfi'); if (!b) return; const it = items.find(i => i.id == b.dataset.id); closeAll(); if (it) go(it.link);
    });
    // Staff pill keeps its clicks to itself. On the store only the bell and panel do, so the store's own Account menu keeps working.
    pill.addEventListener('click', ev => { if (!store || ev.target.closest('.nfb,.nfp')) ev.stopPropagation(); const d = ev.target.closest('[data-dp]'); if (d) { pill.classList.remove('open'); d.dataset.dp == 'chg' ? dpChange() : dpRemove(); } });
    if (menu) {
      pill.querySelector('.nfu').addEventListener('click', () => {
        const open = menu.hidden; closeAll(); if (open) { buildMenu(); menu.hidden = false; pill.querySelector('.nfu').setAttribute('aria-expanded', 'true'); }
      });
      menu.addEventListener('click', ev => {
        const b = ev.target.closest('[data-m]'); if (!b) return; const m = b.dataset.m; menu.hidden = true;
        if (m == 'out') { const x = $('#lgo'); x && x.click(); }
        else if (m == 'photo') dpChange();
        else if (m == 'rmphoto') dpRemove();
        else if (m == 'pw') { const x = $('#pwb'); x && x.click(); }
        else if (m == 'settings') { const x = $('[data-t="s"]'); x && x.click(); }
        else if (m == 'store') location.href = './';
      });
    }
    D.addEventListener('click', closeAll);
    D.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeAll(); });
    D.addEventListener('visibilitychange', () => { if (!D.hidden) poll(); });
    setInterval(poll, 30000); setInterval(sync, 1500);
  }

  if (!build()) return;
  wire(); sync();
  window.nfRefresh = () => { sync(); paint(); };
})();
