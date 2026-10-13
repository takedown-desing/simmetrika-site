(() => {
  const BASE = document.body.dataset.base || '';
  const KEY = 'simmetrika_lead_v1';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const rub = (n) => Math.round(n).toLocaleString('ru-RU').replace(/ /g, ' ') + ' ₽';
  const MAT = { velyur: 'велюр', rogozhka: 'рогожка', zhakkard: 'жаккард' };
  const COLORS = { seryy: 'серый', bezhevyy: 'бежевый', zelenyy: 'зелёный', siniy: 'синий', belyy: 'белый', korichnevyy: 'коричневый', zheltyy: 'жёлтый', terrakot: 'терракотовый', bordovyy: 'бордовый', rozovyy: 'розовый' };

  // ── storage ──
  const load = () => { try { const d = JSON.parse(localStorage.getItem(KEY)); if (d && Date.now() - d.t < 30 * 864e5) return d.items || []; } catch {} return []; };
  const save = (items) => { try { localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), items })); } catch {} renderCount(); };
  const renderCount = () => { const n = load().length; $$('[data-cart-n]').forEach((e) => { e.textContent = n || ''; e.dataset.n = n; }); };
  const toast = (msg) => { const t = $('#toast'); if (!t) return; t.textContent = msg; t.classList.add('is-on'); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('is-on'), 3200); };
  const addItem = (it) => {
    const items = load();
    const key = [it.id, it.material, it.color, it.side, it.sample].join('|');
    const ex = items.find((x) => x.key === key);
    if (ex) ex.qty = Math.min(9, ex.qty + 1);
    else { if (items.length >= 20) { toast('В заявке уже 20 позиций. Отправьте её или удалите лишнее.'); return; } items.push({ ...it, key, qty: 1 }); }
    save(items);
    toast(it.color ? 'Добавлено в заявку.' : 'Добавлено. Ткань и цвет уточним при звонке.');
  };
  renderCount();

  // ── listing add ──
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-add]');
    if (b) { try { addItem(JSON.parse(b.dataset.add)); } catch {} }
    if (e.target.closest('[data-callback]')) openModal('callback');
    if (e.target.closest('[data-close]') || e.target.id === 'modal') closeModal();
  });

  // ── catalog menu ──
  const cm = $('[data-cmenu]');
  $$('[data-menu]').forEach((b) => b.addEventListener('click', () => { cm.classList.add('is-open'); b.setAttribute('aria-expanded', 'true'); $('[data-menu-close]')?.focus(); }));
  $('[data-menu-close]')?.addEventListener('click', () => { cm.classList.remove('is-open'); $$('[data-menu]').forEach((b) => b.setAttribute('aria-expanded', 'false')); });
  cm?.addEventListener('click', (e) => { if (e.target === cm) cm.classList.remove('is-open'); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { cm?.classList.remove('is-open'); closeModal(); $('.zoom')?.classList.remove('is-open'); } });

  // ── phone mask ──
  const mask = (v) => { let d = v.replace(/\D/g, ''); if (d.startsWith('8')) d = '7' + d.slice(1); if (!d.startsWith('7')) d = '7' + d; d = d.slice(0, 11); const p = [d.slice(1, 4), d.slice(4, 7), d.slice(7, 9), d.slice(9, 11)]; let s = '+7'; if (p[0]) s += ' (' + p[0]; if (p[0].length === 3) s += ')'; if (p[1]) s += ' ' + p[1]; if (p[2]) s += '-' + p[2]; if (p[3]) s += '-' + p[3]; return s; };
  document.addEventListener('input', (e) => { if (e.target.matches('[data-mask]')) e.target.value = mask(e.target.value); });

  // ── forms ──
  const leadNo = () => { const y = new Date().getFullYear(); let n = 0; try { n = Number(localStorage.getItem('simmetrika_lead_seq') || 0) + 1; localStorage.setItem('simmetrika_lead_seq', n); } catch {} return `${y}-${String(n).padStart(6, '0')}`; };
  const utm = () => Object.fromEntries([...new URLSearchParams(location.search)].filter(([k]) => k.startsWith('utm_')));
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('form[data-form]'); if (!f) return;
    e.preventDefault();
    const err = $('[data-err]', f); const phone = $('[name=phone]', f); const consent = $('[name=consent]', f);
    const digits = (phone?.value || '').replace(/\D/g, '');
    let msg = '';
    if (digits.length !== 11) { msg = 'Введите телефон полностью: 10 цифр после +7.'; phone?.setAttribute('aria-invalid', 'true'); phone?.focus(); }
    else if (consent && !consent.checked) { msg = 'Поставьте галочку согласия на обработку данных, без неё заявку отправить нельзя.'; consent.focus(); }
    if (msg) { if (err) err.textContent = msg; return; }
    phone?.removeAttribute('aria-invalid'); if (err) err.textContent = '';
    const type = f.dataset.form;
    const lead = { no: leadNo(), type, data: Object.fromEntries(new FormData(f)), items: type === 'cart' ? load() : f._items || [], source: location.pathname, utm: utm(), at: new Date().toISOString() };
    try { const log = JSON.parse(localStorage.getItem('simmetrika_leads') || '[]'); log.push(lead); localStorage.setItem('simmetrika_leads', JSON.stringify(log.slice(-20))); } catch {}
    try { window.ym && window.ym(0, 'reachGoal', 'lead_' + type); } catch {}
    if (type === 'cart' || type === 'one_click') { if (type === 'cart') save([]); try { sessionStorage.setItem('simmetrika_last_lead', lead.no); } catch {} location.href = BASE + '/zayavka/spasibo/?id=' + encodeURIComponent(lead.no); return; }
    $$('input, textarea, select, button', f).forEach((x) => (x.disabled = true));
    const ok = $('[data-ok]', f); if (ok) ok.hidden = false;
  });

  // ── modal (callback / one click) ──
  const modal = $('#modal');
  function openModal(kind, item) {
    if (!modal) return;
    const body = $('[data-modal-body]', modal);
    const title = kind === 'one_click' ? 'Заказать в один клик' : 'Обратный звонок';
    const lead = kind === 'one_click' && item ? `${item.name}, ${MAT[item.material] || ''}${item.color ? ', ' + COLORS[item.color] : ''}${item.side ? ', ' + (item.side === 'left' ? 'левый угол' : 'правый угол') : ''}. Ориентировочно ${rub(item.price)}.` : 'Оставьте телефон, перезвоним в течение часа в рабочее время 10:00–21:00.';
    body.innerHTML = `<form class="lead-form" data-form="${kind}" novalidate><p class="lf-t">${title}</p><p class="lf-l">${lead}</p><div class="fld-row"><label class="fld"><span>Имя</span><input name="name" autocomplete="name"></label><label class="fld"><span>Телефон *</span><input name="phone" type="tel" required autocomplete="tel" data-mask placeholder="+7 (___) ___-__-__"></label></div><label class="fld-check"><input type="checkbox" name="consent" required> Согласен на обработку персональных данных по <a href="${BASE}/privacy/#soglasie">политике конфиденциальности</a></label><p class="lf-err" data-err role="alert"></p><button class="btn btn-accent" type="submit">Отправить</button><p class="lf-ok" data-ok hidden>Спасибо, заявка принята. Перезвоним в течение 1 часа в рабочее время 10:00–21:00.</p></form>`;
    if (item) $('form', body)._items = [{ ...item, qty: 1 }];
    modal.classList.add('is-open');
    setTimeout(() => $('input[name=phone]', body)?.focus(), 50);
  }
  function closeModal() { modal?.classList.remove('is-open'); }

  // ── product page ──
  const pp = $('[data-product]');
  if (pp) {
    const P = JSON.parse(pp.dataset.product);
    const st = { material: 'rogozhka', color: null, side: P.sides ? 'left' : null };
    const firstColor = $('[data-colors-box] [aria-pressed="true"]'); st.color = firstColor?.dataset.v || null;
    const price = () => Math.round((P.base * (P.k[st.material] || 1)) / 100) * 100;
    const upd = () => { $('[data-price]').textContent = rub(price()); };
    const press = (box, btn) => { $$('button', box).forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false')); };
    const colorsBox = $('[data-colors-box]');
    const bindColors = () => $$('button', colorsBox).forEach((b) => b.addEventListener('click', () => { press(colorsBox, b); st.color = b.dataset.v; $('[data-color-name]').textContent = b.dataset.n; }));
    bindColors();
    const hex = Object.fromEntries($$('button', colorsBox).map((b) => [b.dataset.v, getComputedStyle(b).getPropertyValue('--sw')]));
    const HEX = { seryy: '#8E9096', bezhevyy: '#D8C7AE', zelenyy: '#4E7A5A', siniy: '#2F4A73', belyy: '#F1EFEA', korichnevyy: '#6E4E3A', zheltyy: '#D9A33A', terrakot: '#B65A3A', bordovyy: '#7A1E2B', rozovyy: '#D9A3A6', ...hex };
    $$('[data-material] button').forEach((b) => b.addEventListener('click', () => {
      press($('[data-material]'), b); st.material = b.dataset.v; upd();
      const cs = b.dataset.colors.split(',');
      if (!cs.includes(st.color)) st.color = cs[0];
      colorsBox.innerHTML = cs.map((c) => `<button type="button" style="--sw:${HEX[c]}" data-v="${c}" data-n="${COLORS[c]}" aria-pressed="${c === st.color}" aria-label="${COLORS[c]}"></button>`).join('');
      $('[data-color-name]').textContent = COLORS[st.color]; bindColors();
    }));
    $$('[data-side] button').forEach((b) => b.addEventListener('click', () => { press($('[data-side]'), b); st.side = b.dataset.v; }));
    const item = () => ({ id: P.id, name: P.name, url: P.url, img: P.img, material: st.material, color: st.color, side: st.side, price: price() });
    $$('[data-add-product]').forEach((b) => b.addEventListener('click', () => addItem(item())));
    $$('[data-oneclick]').forEach((b) => b.addEventListener('click', () => openModal('one_click', item())));
    const main = $('[data-zoom] img');
    $$('[data-thumb]').forEach((t) => t.addEventListener('click', () => { if (main) { main.src = t.dataset.thumb; main.removeAttribute('srcset'); } $$('[data-thumb]').forEach((x) => x.setAttribute('aria-current', x === t ? 'true' : 'false')); }));
    $('[data-zoom]')?.addEventListener('click', () => { let z = $('.zoom'); if (!z) { z = document.createElement('div'); z.className = 'zoom'; z.innerHTML = '<button class="round" type="button" aria-label="Закрыть"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"/></svg></button><img alt="">'; z.addEventListener('click', () => z.classList.remove('is-open')); document.body.append(z); } $('img', z).src = main.currentSrc || main.src; $('img', z).alt = main.alt; z.classList.add('is-open'); });
  }

  // ── filters ──
  $$('[data-filters]').forEach((fb) => {
    const grid = fb.parentElement.querySelector('[data-grid]') || fb.nextElementSibling?.matches?.('[data-grid]') && fb.nextElementSibling || $('[data-grid]');
    if (!grid) return;
    const cards = $$('.pc', grid);
    const apply = () => {
      const v = Object.fromEntries($$('[data-f]', fb).map((x) => [x.dataset.f, x.type === 'checkbox' ? x.checked : x.value]));
      let n = 0;
      cards.forEach((c) => {
        let ok = true;
        if (v.cat && c.dataset.cat !== v.cat) ok = false;
        if (v.mech && c.dataset.mech !== v.mech) ok = false;
        if (v.price) { const [a, b] = v.price.split('-').map(Number); const p = Number(c.dataset.price); if (p < a || p > b) ok = false; }
        if (v.len) { const [a, b] = v.len.split('-').map(Number); const l = Number(c.dataset.len); if (l < a || l > b) ok = false; }
        if (v.stock && c.dataset.stock !== '1') ok = false;
        c.hidden = !ok; if (ok) n++;
      });
      const s = $('[data-sort]', fb)?.value || 'pop';
      const sorted = cards.slice().sort((a, b) => s === 'asc' ? a.dataset.price - b.dataset.price : s === 'desc' ? b.dataset.price - a.dataset.price : (b.dataset.hit - a.dataset.hit) || (a.dataset.sort - b.dataset.sort));
      sorted.forEach((c) => grid.append(c));
      const f = $('[data-found]', fb); if (f) f.textContent = `Найдено: ${n}`;
      let em = grid.nextElementSibling?.matches?.('.empty[data-auto]') ? grid.nextElementSibling : null;
      if (!n && !em) { em = document.createElement('div'); em.className = 'empty'; em.dataset.auto = '1'; em.innerHTML = '<p class="empty-t">По этим условиям диванов нет</p><p>Измените фильтры или сбросьте их.</p>'; grid.after(em); }
      if (em) em.hidden = !!n;
    };
    fb.addEventListener('change', apply);
    $('[data-reset]', fb)?.addEventListener('click', () => { $$('[data-f]', fb).forEach((x) => (x.type === 'checkbox' ? (x.checked = false) : (x.value = ''))); const s = $('[data-sort]', fb); if (s) s.value = 'pop'; apply(); });
    apply();
  });

  // ── cart page ──
  const cart = $('[data-cart]');
  if (cart) {
    const list = $('[data-cart-list]');
    const draw = () => {
      const items = load();
      $('[data-cart-empty]').hidden = items.length > 0;
      $('[data-cart-sum]').hidden = items.length === 0;
      list.innerHTML = items.map((it, i) => `<div class="ci"><a href="${BASE}${it.url}">${it.img ? `<img src="${BASE}${it.img}" alt="" width="120" height="90">` : '<span class="ph"></span>'}</a><div><a class="ci-t" href="${BASE}${it.url}">${it.name}</a><p class="ci-v">${[it.material ? MAT[it.material] : '', it.color ? COLORS[it.color] : 'цвет уточним', it.side ? (it.side === 'left' ? 'левый угол' : 'правый угол') : '', it.sample ? 'выставочный образец' : ''].filter(Boolean).join(', ')}</p><div class="ci-q"><button type="button" data-q="-1" data-i="${i}" aria-label="Меньше">−</button><span>${it.qty}</span><button type="button" data-q="1" data-i="${i}" aria-label="Больше">+</button></div><button class="ci-x" type="button" data-rm="${i}">Удалить</button></div><div class="ci-p">${rub(it.price * it.qty)}</div></div>`).join('');
      $('[data-cart-count]').textContent = items.reduce((s, x) => s + x.qty, 0);
      $('[data-cart-total]').textContent = 'около ' + rub(items.reduce((s, x) => s + x.price * x.qty, 0));
    };
    list.addEventListener('click', (e) => {
      const items = load();
      const q = e.target.closest('[data-q]'); const rm = e.target.closest('[data-rm]');
      if (q) { const it = items[q.dataset.i]; it.qty = Math.max(1, Math.min(9, it.qty + Number(q.dataset.q))); save(items); draw(); }
      if (rm) { const [gone] = items.splice(Number(rm.dataset.rm), 1); save(items); draw(); toast(`${gone.name} удалён из заявки.`); }
    });
    draw();
  }
  const th = $('[data-thanks]');
  if (th) { const id = new URLSearchParams(location.search).get('id'); if (!id) location.replace(BASE + '/catalog/'); else $('[data-lead-no]').textContent = id; }
})();
