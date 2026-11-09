// Website tab (admin only): edit the text, photos and opening hours shown on the store front page. Changes show on the store at once.
// Uses page helpers: api. Blank fields fall back to the built-in text.
(() => {
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => '&#' + c.charCodeAt(0) + ';');
  const tabs = $('#adm .tabs'); if (!tabs) return;
  const G = [
    ['Top banner', [
      ['hero_t1', 'Headline, first line', 't', 'Safety Gear'], ['hero_t2', 'Headline, highlighted line', 't', 'You Can Trust'],
      ['hero_sub', 'Sub-heading', 't', 'Quality PPE and workwear for every jobsite.'], ['hero_btn1', 'Main button', 't', 'Shop Products'], ['hero_btn2', 'Quote button', 't', 'Request a Quote'],
      ['hero_s1', 'Handwritten line 1', 't', 'Work Safe'], ['hero_s2', 'Handwritten line 2', 't', 'Stay Safe'],
      ['hero_img1', 'Banner photo 1', 'i', 'img/safety-helmets.jpg'], ['hero_img2', 'Banner photo 2', 'i', 'img/reflector-vests.jpg'], ['hero_img3', 'Banner photo 3', 'i', 'img/x-safety-boots.jpg'], ['hero_img4', 'Banner photo 4', 'i', 'img/gloves.jpg']]],
    ['Four highlights under the header', [
      ['trust1_t', '1 · Title (opens all products)', 't', 'Genuine PPE'], ['trust1_s', '1 · Small text', 't', 'Original products from trusted brands'],
      ['trust2_t', '2 · Title (opens Shipping & Delivery)', 't', 'Delivery Across Kenya'], ['trust2_s', '2 · Small text', 't', 'Fast & reliable delivery'],
      ['trust3_t', '3 · Title (opens Payment Methods)', 't', 'Pay on Delivery'], ['trust3_s', '3 · Small text', 't', 'Cash on delivery or pay on pickup'],
      ['trust4_t', '4 · Title (opens quote request)', 't', 'Bulk & Corporate Orders'], ['trust4_s', '4 · Small text', 't', 'Special rates for businesses & institutions']]],
    ['Headings and search', [
      ['best_title', 'Best Sellers heading', 't', 'Best Sellers'], ['all_title', 'All products heading', 't', 'All Products'], ['search_ph', 'Search box hint', 't', 'Search helmets, boots, gloves…']]],
    ['Quote banner', [
      ['quote_h', 'Heading', 't', 'Need PPE for your team?'], ['quote_p', 'Text', 'a', 'We supply businesses, institutions and organizations with quality PPE at competitive rates.'], ['quote_btn', 'Button', 't', 'Request a Quote'],
      ['quote_b1', 'Point 1', 't', 'Bulk discounts'], ['quote_b2', 'Point 2', 't', 'Fast delivery'], ['quote_b3', 'Point 3', 't', 'Custom branding'], ['quote_img', 'Banner photo', 'i', 'img/shop-front.jpg']]],
    ['Footer and contact', [
      ['hours', 'Opening hours (one per line: Day | Time)', 'a', 'Mon – Fri: | 8:00 AM - 5:00 PM\nSaturday: | 8:00 AM - 2:00 PM\nSunday: | Closed'],
      ['whatsapp', 'WhatsApp number (blank = the main phone)', 't', '+254 721 373107'], ['footer_tag', 'Footer slogan', 't', 'Quality Safety Gear. Safer Workplaces.'], ['news_blurb', 'Newsletter text', 't', 'Get the latest deals and safety tips.']]],
    ['Pop-up pages (blank lines start a new paragraph)', [
      ['faq_about', 'About Us', 'a', 'We supply genuine PPE, workwear, safety footwear, gloves, signs, tools and branding for businesses, institutions and individuals.\n\nVisit us in Nairobi or order online for delivery.'],
      ['faq_ship', 'Shipping & Delivery', 'a', 'We deliver within Nairobi and countrywide. You can also choose in-store pickup at checkout. We contact you to confirm the delivery details and timing after you place an order.'],
      ['faq_ret', 'Returns & Refunds', 'a', 'If an item is wrong or faulty, contact us on the phone number or WhatsApp below as soon as you receive it and we will sort it out with you.'],
      ['faq_pay', 'Payment Methods', 'a', 'Orders are paid on delivery or on pickup. Choose Cash on delivery or Pay on pickup at checkout.'],
      ['faq_bulk', 'Bulk Orders', 'a', 'Buying for a team, company or institution? Use Request a Quote and tell us what you need. We can also add custom branding and embroidery on workwear.']]]];
  const ALL = G.flatMap(g => g[1]);

  const btn = document.createElement('button');
  btn.className = 'chip'; btn.dataset.t = 'web'; btn.textContent = 'Website';
  const s = tabs.querySelector('[data-t="s"]'); s ? s.before(btn) : tabs.append(btn);

  const panel = document.createElement('div'); panel.id = 'web-panel'; panel.hidden = true;
  panel.innerHTML = `<style>#web-panel form{max-width:760px}#web-panel fieldset{border:1px solid var(--bd);border-radius:12px;margin:12px 0;padding:6px 14px 12px}#web-panel legend{font-weight:800;padding:0 6px}
#web-panel .ir{display:flex;gap:8px;align-items:center}#web-panel .ir input{flex:1}#web-panel .ir img{width:44px;height:44px;object-fit:contain;border:1px solid var(--bd);border-radius:6px;background:#fff}
#web-panel textarea{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--bd);border-radius:8px;background:var(--bg);color:var(--tx)}</style>
<p class="hint">Everything here is shown on the store's front page. Change anything and press <b>Save website</b>: the store shows it on the next visit. Departments are renamed in Products, the second phone and shop locations are in Settings, and Best Sellers are chosen in Products.</p>
<form id="web-form">${G.map(([t, f]) => `<fieldset><legend>${esc(t)}</legend>${f.map(([k, l, ty]) => ty == 'a'
    ? `<label>${esc(l)}<textarea name="${k}" rows="${k.startsWith('faq') ? 4 : 3}"></textarea></label>`
    : ty == 'i' ? `<label>${esc(l)}<span class="ir"><img alt="" data-pv="${k}"><input name="${k}" placeholder="img/photo.jpg"><button type="button" class="btn" data-up="${k}">Upload</button></span></label>`
    : `<label>${esc(l)}<input name="${k}" maxlength="200"></label>`).join('')}</fieldset>`).join('')}
<input type="file" id="web-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
<p id="web-msg" role="alert" style="font-weight:600"></p>
<button class="btn n">Save website</button> <button type="button" class="btn" id="web-reset">Reset all to built-in text</button> <a class="btn" href="./" target="_blank" rel="noopener">Open the store</a></form>`;
  $('#adm').append(panel);

  const f = () => $('#web-form'), pv = k => { const i = panel.querySelector(`[data-pv="${k}"]`); if (i) { const v = f().elements[k].value; i.src = v || ''; i.hidden = !v; } };
  const fill = c => { for (const [k, , , d] of ALL) { f().elements[k].value = (c[k] !== undefined && c[k] !== '') ? c[k] : d; pv(k); } };
  async function load() { try { fill(await api('a_content')); $('#web-msg').textContent = ''; } catch (x) { $('#web-msg').textContent = x.message; } }

  let upKey = '';
  panel.addEventListener('click', async ev => {
    ev.stopPropagation(); const t = ev.target.closest('button'); if (!t) return;
    if (t.dataset.up) { upKey = t.dataset.up; $('#web-file').click(); }
    else if (t.id == 'web-reset') {
      if (!confirm('Clear all your changes and go back to the built-in text and photos?')) return;
      try { await api('a_content_save', {}); await load(); $('#web-msg').style.color = 'var(--gr)'; $('#web-msg').textContent = 'Back to the built-in text.'; } catch (x) { alert(x.message); }
    }
  });
  panel.addEventListener('input', ev => { if (ev.target.name && ev.target.name.includes('img')) pv(ev.target.name); });
  $('#web-file').onchange = async ev => {
    const file = ev.target.files[0]; ev.target.value = ''; if (!file) return;
    const fd = new FormData(); fd.append('photo', file); $('#web-msg').style.color = ''; $('#web-msg').textContent = 'Uploading…';
    try {
      const r = await fetch('api.php?action=a_upload', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
      const j = await r.json().catch(() => ({ error: 'Unexpected response from the server.' })); if (!r.ok) throw new Error(j.error || 'Upload failed.');
      f().elements[upKey].value = j.path; pv(upKey); $('#web-msg').textContent = 'Photo uploaded. Press Save website to use it.';
    } catch (x) { $('#web-msg').style.color = '#de1c24'; $('#web-msg').textContent = x.message; }
  };
  f().onsubmit = async ev => {
    ev.preventDefault(); const m = $('#web-msg'); m.style.color = '';
    const b = {}; for (const [k, , , d] of ALL) { const v = f().elements[k].value.trim(); b[k] = v === d ? '' : v; } // unchanged built-in text is not stored
    try { await api('a_content_save', b); m.style.color = 'var(--gr)'; m.textContent = 'Saved. The store now shows these changes.'; } catch (x) { m.style.color = '#de1c24'; m.textContent = x.message; }
  };
  tabs.addEventListener('click', ev => { const b = ev.target.closest('[data-t]'); if (!b) return; panel.hidden = b.dataset.t !== 'web'; if (b.dataset.t === 'web') load(); });
})();
