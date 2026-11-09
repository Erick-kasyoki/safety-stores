// Profile photo (dp): pick one when signing up, change or remove it later. Used by the store, worker and admin pages.
(() => {
  const D = document, MAX = 8 * 1024 * 1024;
  const cur = () => { try { return typeof user !== 'undefined' && user ? user : (typeof ME !== 'undefined' && ME ? ME : null); } catch (x) { return null; } };
  const say = t => (typeof toast === 'function' ? toast(t) : alert(t));
  const TYPES = 'image/jpeg,image/png,image/webp,image/gif';

  // Uploads a photo for the signed-in person and updates the page's copy of the user.
  window.dpSend = async file => {
    if (file.size > MAX) throw new Error('The photo is bigger than 8 MB.');
    const fd = new FormData(); fd.append('photo', file);
    const r = await fetch('api.php?action=u_photo', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
    const j = await r.json().catch(() => ({ error: 'Unexpected response from the server.' }));
    if (!r.ok) throw new Error(j.error || 'The upload failed. Try again.');
    const u = cur(); if (u) u.photo = j.photo;
    if (window.nfRefresh) nfRefresh();
    return j;
  };
  // After sign-up: sends the photo chosen in the form (if any) and clears the field. A failed photo never blocks the new account.
  window.dpAfterSignup = async sel => {
    const i = D.querySelector(sel); if (!i || !i.files[0]) return;
    const f = i.files[0]; i.value = ''; i.dispatchEvent(new Event('change', { bubbles: true }));
    try { await dpSend(f); } catch (x) { say('Your account is ready, but the photo was not saved: ' + x.message); }
  };
  window.dpChange = () => {
    const i = D.createElement('input'); i.type = 'file'; i.accept = TYPES;
    i.onchange = async () => { const f = i.files[0]; if (!f) return; try { await dpSend(f); say('Profile photo updated'); } catch (x) { say(x.message); } };
    i.click();
  };
  window.dpRemove = async () => {
    try {
      const r = await fetch('api.php?action=u_photo_remove', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || 'Could not remove the photo.');
      const u = cur(); if (u) u.photo = ''; if (window.nfRefresh) nfRefresh(); say('Profile photo removed');
    } catch (x) { say(x.message); }
  };

  // The round preview inside sign-up forms: <div class="dpw"><span class="dpv"></span><label>…<input type="file"></label></div>
  D.addEventListener('change', ev => {
    const i = ev.target; if (!i.matches || !i.matches('.dpw input[type=file]')) return;
    const v = i.closest('.dpw').querySelector('.dpv'), f = i.files[0];
    if (f && f.size > MAX) { i.value = ''; say('The photo is bigger than 8 MB.'); }
    const g = i.files[0];
    v.style.backgroundImage = g ? 'url(' + URL.createObjectURL(g) + ')' : ''; v.classList.toggle('has', !!g);
  });
})();
