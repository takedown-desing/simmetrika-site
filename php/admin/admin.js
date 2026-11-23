// Админка SIMMETRIKA: списки пунктов, загрузка фото, поиск, предупреждение о несохранённых правках.
(function () {
  'use strict';
  var B = document.body;
  var BASE = B.dataset.base || '';
  var ADMIN = B.dataset.admin || './';
  var CSRF = B.dataset.csrf || '';
  var seq = 0;
  var dirty = false;
  var form = document.querySelector('form[data-edit]');

  function closest(el, sel) { while (el && el.nodeType === 1) { if (el.matches(sel)) return el; el = el.parentElement; } return null; }
  function count(list) {
    var rows = list.querySelector(':scope > .rows');
    var c = list.querySelector(':scope > .list-head .count');
    if (rows && c) c.textContent = rows.children.length;
  }
  function rowTitle(row) {
    var t = '';
    var inp = row.querySelectorAll(':scope > .row-body input[type=text], :scope > .row-body textarea');
    for (var i = 0; i < inp.length; i++) {
      if (closest(inp[i], '.row') !== row) continue;
      if (inp[i].value.trim()) { t = inp[i].value.trim(); break; }
    }
    var el = row.querySelector(':scope > summary .row-title');
    if (el) el.textContent = t ? (t.length > 90 ? t.slice(0, 89) + '…' : t) : 'Новый пункт';
  }
  function preview(input) {
    var wrap = closest(input, '.img-field');
    if (!wrap) return;
    var img = wrap.querySelector('img');
    var v = input.value.trim();
    if (!v) { img.hidden = true; img.removeAttribute('src'); return; }
    img.hidden = false;
    img.src = v.charAt(0) === '/' ? BASE + v.replace(/-1200\.(webp|jpg)$/, '-600.$1') : v;
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest('[data-menu]')) { B.classList.toggle('nav-open'); return; }
    var add = t.closest('[data-add]');
    if (add) {
      var list = add.parentElement;
      var tpl = list.querySelector(':scope > template');
      var tok = list.dataset.tok;
      var html = tpl.innerHTML.split(tok).join('n' + (++seq) + Date.now().toString(36));
      var box = document.createElement('div');
      box.innerHTML = html;
      var row = box.firstElementChild;
      list.querySelector(':scope > .rows').appendChild(row);
      row.open = true;
      count(list);
      dirty = true;
      var first = row.querySelector('input:not([type=hidden]), textarea, select');
      if (first) first.focus();
      return;
    }
    var ctl = t.closest('.row-ctl button');
    if (ctl) {
      e.preventDefault();
      var r = closest(ctl, '.row');
      var parent = r.parentElement;
      if (ctl.hasAttribute('data-up') && r.previousElementSibling) parent.insertBefore(r, r.previousElementSibling);
      else if (ctl.hasAttribute('data-down') && r.nextElementSibling) parent.insertBefore(r.nextElementSibling, r);
      else if (ctl.hasAttribute('data-del')) {
        var title = (r.querySelector('.row-title') || {}).textContent || 'пункт';
        if (!confirm('Удалить «' + title + '»?')) return;
        var l = closest(parent, '.list');
        r.remove();
        if (l) count(l);
      }
      dirty = true;
      return;
    }
    var up = t.closest('[data-upload]');
    if (up) {
      var field = closest(up, '.img-field');
      var input = field.querySelector('input[data-image]');
      var file = document.createElement('input');
      file.type = 'file';
      file.accept = 'image/jpeg,image/png,image/webp,image/gif';
      file.onchange = function () {
        if (!file.files[0]) return;
        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('csrf', CSRF);
        fd.append('photo', file.files[0]);
        up.disabled = true;
        var old = up.textContent;
        up.textContent = 'Загружаю…';
        fetch(ADMIN, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Сервер не принял файл (возможно, он больше, чем разрешает хостинг)' }; }); })
          .then(function (j) {
            if (!j.ok) throw new Error(j.error || 'Не удалось загрузить фото');
            input.value = j.path;
            var img = field.querySelector('img');
            img.hidden = false;
            img.src = j.preview;
            dirty = true;
            var row = closest(input, '.row');
            if (row) rowTitle(row);
          })
          .catch(function (err) { alert(err.message || 'Не удалось загрузить фото'); })
          .then(function () { up.disabled = false; up.textContent = old; });
      };
      file.click();
    }
  });

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (form && form.contains(t)) dirty = true;
    if (t.matches('[data-color]')) {
      var pick = t.parentElement.querySelector('input[type=color]');
      if (/^#[0-9a-f]{6}$/i.test(t.value.trim())) pick.value = t.value.trim();
    } else if (t.matches('.color input[type=color]')) {
      t.parentElement.querySelector('[data-color]').value = t.value;
    }
    if (t.matches('[data-image]')) preview(t);
    var row = closest(t, '.row');
    if (row) rowTitle(row);
    if (t.matches('[data-filter]')) {
      var q = t.value.trim().toLowerCase();
      var rows = document.querySelectorAll('[data-list] tbody tr');
      var grp = null, shown = 0;
      for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        if (tr.classList.contains('grp')) {
          if (grp) grp.hidden = q !== '' && shown === 0;
          grp = tr; shown = 0;
          continue;
        }
        var ok = q === '' || tr.textContent.toLowerCase().indexOf(q) >= 0 || (tr.querySelector('a') || {}).href && tr.querySelector('a').href.toLowerCase().indexOf(q) >= 0;
        tr.hidden = !ok;
        if (ok) shown++;
      }
      if (grp) grp.hidden = q !== '' && shown === 0;
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target;
    if (form && form.contains(t)) dirty = true;
    if (t.matches('select[data-autosubmit]')) t.form.submit();
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset.confirm && !confirm(f.dataset.confirm)) { e.preventDefault(); return; }
    if (f === form) {
      dirty = false;
      var btn = f.querySelector('.savebar button');
      if (btn) { setTimeout(function () { btn.disabled = true; btn.textContent = 'Сохраняю…'; }, 0); }
    }
  });
  window.addEventListener('beforeunload', function (e) {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = '';
  });
  document.addEventListener('keydown', function (e) {
    if (form && (e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S' || e.key === 'ы' || e.key === 'Ы')) {
      e.preventDefault();
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    }
  });
  // первая ошибка видна сразу
  var err = document.querySelector('main .flash.err');
  if (err) err.scrollIntoView({ block: 'start' });
})();
