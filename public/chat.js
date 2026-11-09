// Staff live chat build 8 (admin.html and worker.html): a chat button at the bottom right. Pick any active admin or worker and talk one to one.
// Look and behaviour follow the support chat design: navy panel, the sender's name above every message, System notes, Attach button,
// "Write a message..." box (Enter or Send to send) and a Send button that stays dim until there is something to send.
// Uses helpers from the page: ME, toast. Messages are fetched every few seconds while the chat is open.
(() => {
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const MAXFILE = 5 * 1024 * 1024, EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
  let open = false, peer = null, users = [], lastId = 0, unread = 0, poll = null, sending = false, shown = new Set(), busy = null, replyTo = null, readUpto = 0, lastDay = '', file = null, fileUrl = '', stickUntil = 0;
  const muteKey = () => 'ch-mute-' + ((typeof ME !== 'undefined' && ME && (ME.id || ME.email)) || 'x');
  const isMuted = () => { try { return localStorage.getItem(muteKey()) === '1'; } catch (x) { return false; } };
  // Names come from the server (see srvName). A login mix-up between tabs is only logged to the console: the chat never hides or reloads itself.
  let stale = false;
  const checkWho = id => { if (id && typeof ME !== 'undefined' && ME && ME.id && +id !== +ME.id) console.warn('Staff chat: server session id', id, 'differs from the page user id', ME.id); return false; };
  const roleName = r => r === 'admin' ? 'Admin' : 'Worker';
  let srvName = ''; // the signed-in person's name as the server sees it (the page's ME can be stale if someone signed in as another user in a second tab)
  const myName = () => srvName || (typeof ME !== 'undefined' && ME && ME.name) || 'You';

  const root = document.createElement('div');
  root.id = 'ch-root'; root.hidden = true;
  root.innerHTML = `<button id="ch-fab" aria-label="Open staff chat" title="Staff chat">💬<span id="ch-bd" class="bdg" hidden></span></button>
<section id="ch-box" hidden aria-label="Staff chat">
 <header><button id="ch-back" class="ch-ic" aria-label="Back to people" hidden>←</button><div class="ch-hd"><div class="ch-t"><b id="ch-title">Staff chat</b><span id="ch-pill" class="ch-pill" hidden></span></div><small id="ch-sub">Choose someone to message</small></div><button id="ch-mute" class="ch-ic" aria-label="Mute chat notifications" title="Mute chat notifications">🔔</button><button id="ch-clr" class="ch-ic" aria-label="Clear this chat" title="Clear this chat (only on your side)" hidden>🗑</button><button id="ch-x" class="ch-ic" aria-label="Close chat">✕</button></header>
 <div id="ch-list"></div>
 <div id="ch-conv" hidden><div id="ch-msgs" aria-live="polite"></div>
  <div id="ch-rp" hidden><div><b></b><span></span></div><button id="ch-rpx" class="ch-ic" aria-label="Cancel reply">✕</button></div>
  <div id="ch-fp" hidden><img id="ch-fpi" alt="" hidden><div><b id="ch-fpn"></b><small id="ch-fps"></small></div><button id="ch-fpx" class="ch-ic" aria-label="Remove attachment">✕</button></div>
  <p id="ch-err" role="alert"></p>
  <div class="ch-in"><button id="ch-att" class="ch-btn" type="button">Attach</button><input id="ch-pick" type="file" hidden accept=".${EXTS.join(',.')}"><input id="ch-text" maxlength="2000" placeholder="Write a message... (Press Enter or click Send)" autocomplete="off" aria-label="Message"><button id="ch-send" class="ch-btn" disabled>Send</button></div></div>
</section>`;
  document.body.append(root);

  const signedIn = () => typeof ME !== 'undefined' && ME && (ME.role === 'admin' || ME.role === 'worker');
  const setBadge = n => {
    const grew = n > unread; unread = n;
    $('#ch-bd').hidden = !n; $('#ch-bd').textContent = n > 99 ? '99+' : n;
    $('#ch-fab').classList.toggle('has-new', n > 0);
    $('#ch-fab').title = n ? n + ' new message' + (n > 1 ? 's' : '') : 'Staff chat';
    if (grew && !isMuted()) { const f = $('#ch-fab'); f.classList.remove('ping'); void f.offsetWidth; f.classList.add('ping'); }
  };
  // The chat uses its own request helper: the page's api() reloads the whole page on a 401, and a failed poll must never hide or reload the chat.
  // A FormData body (a message with an attachment) is sent as multipart; anything else as JSON.
  async function chApi(a, body, x) {
    const multi = body instanceof FormData;
    const r = await fetch('api.php?action=' + a + (x || ''), body ? { method: 'POST', headers: multi ? { 'X-Requested-With': 'fetch' } : { 'Content-Type': 'application/json' }, body: multi ? body : JSON.stringify(body), credentials: 'same-origin' } : { credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ error: 'Unexpected response from the server.' }));
    if (!r.ok) throw new Error(j.error || 'Request failed.');
    return j;
  }
  const get = (a, x) => chApi(a, null, x);
  const size = n => n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

  async function loadUsers() {
    const d = await get('ch_users'); if (checkWho(d.me)) return; users = d.users; if (d.me_name) srvName = d.me_name;
    setBadge(users.reduce((n, u) => n + u.unread, 0));
    if (!open || peer) return;
    $('#ch-list').innerHTML = users.map(u => `<button class="ch-u" data-u="${u.id}"><span><b>${esc(u.name)}</b> <small>${roleName(u.role)}</small><br><small class="ch-l">${u.last_file ? '📎 ' : ''}${esc(u.last) || 'No messages yet'}</small></span>${u.unread ? `<i>${u.unread}</i>` : ''}</button>`).join('')
      || '<p class="hint" style="padding:16px">No other staff accounts yet. Workers appear here after they sign up.</p>';
  }
  const today = () => { const d = new Date(), z = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()); };
  const dayLabel = d => d === today() ? 'Today' : d;
  const ticks = m => m.mine ? `<i class="tk${m.id <= readUpto ? ' rd' : ''}" data-t="${m.id}">${m.id <= readUpto ? '✓✓' : '✓'}</i>` : '';
  // a note from "System" (same look as a message)
  const sys = (t, tmp) => `<div class="ch-m sys${tmp ? ' tmp' : ''}"><div class="ch-n">System</div><div class="ch-bw"><div class="ch-bb"><span class="ch-b">${esc(t)}</span></div></div></div>`;
  const fileUrlOf = (m, dl) => 'api.php?action=ch_file&id=' + m.id + (dl ? '&dl=1' : '');
  function attach(m) {
    if (!m.file) return '';
    return m.file.img
      ? `<a class="ch-img" href="${fileUrlOf(m)}" target="_blank" rel="noopener"><img loading="lazy" alt="${esc(m.file.name)}" src="${fileUrlOf(m)}"></a>`
      : `<a class="ch-file" href="${fileUrlOf(m, 1)}" download><span class="fi">📄</span><span><b>${esc(m.file.name)}</b><small>${size(m.file.size)} · Download</small></span></a>`;
  }
  function bubble(m) {
    const day = m.at.slice(0, 10); let h = '';
    if (day !== lastDay) { lastDay = day; h += `<div class="ch-day"><span>${esc(dayLabel(day))}</span></div>`; }
    const q = m.reply ? `<div class="ch-q" data-go="${m.reply.id}"><b>${m.reply.mine ? 'You' : esc(peer ? peer.name : '')}</b><span>${esc(m.reply.body)}</span></div>` : '';
    const text = m.file && m.body === m.file.name ? '' : `<span class="ch-b">${esc(m.body)}</span>`; // an attachment sent without a caption carries its file name as the text
    return h + `<div class="ch-m ${m.mine ? 'me' : 'them'}" data-id="${m.id}"><div class="ch-n">${esc(m.mine ? myName() : (peer ? peer.name : ''))}</div><div class="ch-bw"><div class="ch-bb">${q}${attach(m)}${text}<small class="ch-tm">${esc(m.at.slice(11))}${ticks(m)}</small></div><button class="ch-rb" data-r="${m.id}" aria-label="Reply" title="Reply">↩</button></div></div>`;
  }
  function paintTicks() { document.querySelectorAll('#ch-msgs i.tk:not(.rd)').forEach(i => { if (+i.dataset.t <= readUpto) { i.classList.add('rd'); i.textContent = '✓✓'; } }); }
  function setReply(id) {
    const el = document.querySelector('#ch-msgs .ch-m[data-id="' + id + '"]'); if (!el) return;
    const b = el.querySelector('.ch-b');
    replyTo = { id, body: b ? b.textContent : '📎 Attachment', mine: el.classList.contains('me') };
    $('#ch-rp b').textContent = replyTo.mine ? 'You' : peer.name; $('#ch-rp span').textContent = replyTo.body.slice(0, 100); $('#ch-rp').hidden = false; $('#ch-text').focus();
  }
  function clearReply() { replyTo = null; $('#ch-rp').hidden = true; }

  // the Send button stays dim until there is text or an attachment
  const canSend = () => !!peer && !sending && !!($('#ch-text').value.trim() || file);
  const paintSend = () => { $('#ch-send').disabled = !canSend(); };
  function setFile(f) {
    if (fileUrl) { URL.revokeObjectURL(fileUrl); fileUrl = ''; }
    file = f; const i = $('#ch-fpi');
    $('#ch-fp').hidden = !f; i.hidden = true; i.removeAttribute('src');
    if (f) { $('#ch-fpn').textContent = f.name; $('#ch-fps').textContent = size(f.size); if (/^image\//.test(f.type)) { fileUrl = URL.createObjectURL(f); i.src = fileUrl; i.hidden = false; } }
    paintSend();
  }
  function pickFile(f) {
    $('#ch-err').textContent = ''; if (!f) return;
    const ext = (f.name.split('.').pop() || '').toLowerCase();
    if (!EXTS.includes(ext)) { $('#ch-err').textContent = 'That file type is not allowed. Use an image, PDF, Word, Excel, CSV or text file.'; return; }
    if (f.size > MAXFILE) { $('#ch-err').textContent = 'Files can be up to 5 MB.'; return; }
    setFile(f); $('#ch-text').focus();
  }
  const toEnd = () => { const b = $('#ch-msgs'); b.scrollTop = b.scrollHeight; stickUntil = Date.now() + 4000; };

  // one fetch at a time (poll + send can no longer run together), and a message id is only ever drawn once
  function loadMsgs(first) {
    const run = async () => {
      if (!peer) return;
      const d = await get('ch_msgs', '&with=' + peer.id + (first ? '' : '&after=' + lastId));
      if (!peer || d.with.id !== peer.id) return;
      if (d.me && checkWho(d.me.id)) return;
      if (d.me && d.me.name) { srvName = d.me.name; const sb = $('#ch-sub'); if (sb && peer) sb.textContent = 'Signed in as ' + srvName; }
      const box = $('#ch-msgs'), near = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
      if (first) { shown.clear(); lastDay = ''; readUpto = 0; box.innerHTML = sys('Chat with ' + peer.name + ' (' + roleName(peer.role) + ').'); }
      readUpto = Math.max(readUpto, d.read_upto || 0);
      const fresh = d.messages.filter(m => !shown.has(m.id));
      d.messages.forEach(m => shown.add(m.id));
      if (fresh.length) { box.querySelectorAll('.ch-m.tmp').forEach(e => e.remove()); box.insertAdjacentHTML('beforeend', fresh.map(bubble).join('')); }
      if (d.messages.length) { lastId = Math.max(lastId, d.messages[d.messages.length - 1].id); if (fresh.length && (first || near)) toEnd(); }
      else if (first) box.insertAdjacentHTML('beforeend', sys('No messages yet. Say hello.', 1));
      paintTicks();
      if (!first && fresh.some(m => !m.mine)) loadUsers().catch(() => {});
    };
    busy = (busy || Promise.resolve()).catch(() => {}).then(run);
    return busy;
  }
  async function choose(id) {
    peer = users.find(u => u.id === id); if (!peer) return;
    lastId = 0; shown.clear(); clearReply(); setFile(null); $('#ch-text').value = '';
    $('#ch-list').hidden = true; $('#ch-conv').hidden = false; $('#ch-back').hidden = false; $('#ch-clr').hidden = false;
    $('#ch-title').textContent = peer.name; $('#ch-pill').textContent = roleName(peer.role); $('#ch-pill').hidden = false; $('#ch-sub').textContent = 'Signed in as ' + myName(); $('#ch-err').textContent = '';
    paintSend();
    await loadMsgs(true); await loadUsers(); $('#ch-text').focus();
  }
  function showList() {
    peer = null; setFile(null); clearReply(); $('#ch-list').hidden = false; $('#ch-conv').hidden = true; $('#ch-back').hidden = true; $('#ch-clr').hidden = true;
    $('#ch-title').textContent = 'Staff chat'; $('#ch-pill').hidden = true; $('#ch-sub').textContent = 'Choose someone to message'; loadUsers().catch(() => {});
  }
  function toggle(v) {
    open = v; $('#ch-box').hidden = !v; clearInterval(poll);
    if (v) { showList(); poll = setInterval(tick, 3000); } else { peer = null; setFile(null); }
  }
  async function tick() {
    if (document.hidden || !signedIn()) return;
    try { if (peer) await loadMsgs(false); else await loadUsers(); } catch (x) { /* ignore a failed poll */ }
  }
  async function send() {
    const t = $('#ch-text'), body = t.value.trim(); if ((!body && !file) || !peer || sending) return;
    const f = file, rt = replyTo ? replyTo.id : 0;
    sending = true; t.value = ''; $('#ch-err').textContent = ''; paintSend();
    try {
      let payload = { to: peer.id, body, reply_to: rt };
      if (f) { payload = new FormData(); payload.append('to', peer.id); payload.append('body', body); payload.append('reply_to', rt); payload.append('file', f, f.name); }
      await chApi('ch_send', payload);
      clearReply(); setFile(null); await loadMsgs(false); toEnd();
    }
    catch (x) { t.value = body; $('#ch-err').textContent = x.message; }
    finally { sending = false; paintSend(); t.focus(); }
  }

  async function clearChat() {
    if (!peer || !confirm('Clear this chat from your side? ' + peer.name + ' will still see it.')) return;
    try { await chApi('ch_clear', { to: peer.id }); lastId = 0; shown.clear(); lastDay = ''; clearReply(); $('#ch-msgs').innerHTML = sys('Chat cleared. New messages will appear here.', 1); loadUsers().catch(() => {}); }
    catch (x) { $('#ch-err').textContent = x.message; }
  }

  function paintMute() { const m = isMuted(), b = $('#ch-mute'); b.textContent = m ? '🔕' : '🔔'; b.title = m ? 'Notifications muted: tap to unmute' : 'Mute chat notifications'; b.setAttribute('aria-label', b.title); $('#ch-fab').classList.toggle('muted', m); }
  function toggleMute() { try { localStorage.setItem(muteKey(), isMuted() ? '0' : '1'); } catch (x) { /* storage blocked */ } paintMute(); toast(isMuted() ? 'Chat notifications muted. Unread count still shows.' : 'Chat notifications on'); }
  paintMute();

  root.addEventListener('click', ev => {
    const q = ev.target.closest('.ch-q');
    if (q) { const el = document.querySelector('#ch-msgs .ch-m[data-id="' + q.dataset.go + '"]'); if (el) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.classList.add('flash'); setTimeout(() => el.classList.remove('flash'), 1200); } return; }
    const t = ev.target.closest('button'); if (!t) return;
    ev.stopPropagation();
    if (t.id === 'ch-fab') toggle(!open);
    else if (t.id === 'ch-x') toggle(false);
    else if (t.id === 'ch-back') showList();
    else if (t.id === 'ch-send') send();
    else if (t.id === 'ch-att') $('#ch-pick').click();
    else if (t.id === 'ch-fpx') setFile(null);
    else if (t.id === 'ch-clr') clearChat();
    else if (t.id === 'ch-mute') toggleMute();
    else if (t.id === 'ch-rpx') clearReply();
    else if (t.dataset.r) setReply(+t.dataset.r);
    else if (t.dataset.u) choose(+t.dataset.u).catch(x => { $('#ch-err').textContent = x.message; });
  });
  root.addEventListener('keydown', ev => { if (ev.target.id === 'ch-text' && ev.key === 'Enter' && !ev.isComposing) { ev.preventDefault(); send(); } });
  root.addEventListener('input', ev => { if (ev.target.id === 'ch-text') paintSend(); });
  root.addEventListener('change', ev => { if (ev.target.id === 'ch-pick') { pickFile(ev.target.files[0]); ev.target.value = ''; } });
  // a photo that finishes loading just after a new message must not push the newest message out of view
  $('#ch-msgs').addEventListener('load', ev => { if (ev.target.tagName === 'IMG' && Date.now() < stickUntil) { const b = $('#ch-msgs'); b.scrollTop = b.scrollHeight; } }, true);

  // the button shows once staff are signed in; unread count is checked every 12 seconds (and a toast appears for new messages)
  async function badgeTick() {
    if (stale) return;
    if (!signedIn()) { root.hidden = true; return; }
    root.hidden = false; paintMute();
    if (document.hidden) return;
    try {
      const before = unread; const d = await get('ch_unread');
      if (d.unread > before && !open && !isMuted()) toast('New chat message');
      if (!open || !peer) setBadge(d.unread); else if (d.unread > 0) loadUsers().catch(() => {});
    } catch (x) { /* a failed check (network blip, chat tables not installed yet) is ignored: the button stays where it is */ }
  }
  setInterval(badgeTick, 5000); setTimeout(badgeTick, 1500);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) badgeTick(); });
})();