(function () {
  'use strict';

  var CSRF = document.querySelector('meta[name="csrf"]').content;
  var MAX_MB = parseInt(document.body.dataset.maxMb, 10) || 20;
  var MAX = MAX_MB * 1024 * 1024;

  var PALETTE = [['Coral', '#D9482F'], ['Orange', '#C2570C'], ['Amber', '#A16207'], ['Green', '#15803D'], ['Teal', '#0F766E'],
    ['Blue', '#1D4ED8'], ['Indigo', '#4338CA'], ['Purple', '#7E22CE'], ['Pink', '#BE185D'], ['Slate', '#475569']];
  var ICON = {
    grip: '<svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="5.5" cy="3.5" r="1.3"/><circle cx="10.5" cy="3.5" r="1.3"/><circle cx="5.5" cy="8" r="1.3"/><circle cx="10.5" cy="8" r="1.3"/><circle cx="5.5" cy="12.5" r="1.3"/><circle cx="10.5" cy="12.5" r="1.3"/></svg>',
    x: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
    chev: '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10l5-5 5 5"/></svg>',
    menu: '<svg viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14"/></svg>'
  };

  var state = { me: null, projects: [], tray: [], current: null, sections: [], loading: false };
  var drag = null;

  /* ---------- helpers ---------- */
  function $(s) { return document.querySelector(s); }
  function h(tag, props) {
    var e = document.createElement(tag);
    props = props || {};
    Object.keys(props).forEach(function (k) {
      var v = props[k];
      if (k === 'class') e.className = v;
      else if (k === 'html') e.innerHTML = v;
      else if (k === 'text') e.textContent = v;
      else if (k === 'style' || k.indexOf('-') > -1 || k === 'tabindex' || k === 'role') e.setAttribute(k, v);
      else if (k in e) e[k] = v;
      else e.setAttribute(k, v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var kids = [].concat(arguments[i]);
      for (var j = 0; j < kids.length; j++) { if (kids[j] != null) e.append(kids[j]); }
    }
    return e;
  }
  function fmt(n) { return n < 1024 ? n + ' B' : n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; }
  function ext(name) { var m = /\.([A-Za-z0-9]{1,5})$/.exec(name); return m ? m[1].toUpperCase() : 'FILE'; }
  function cur() { return state.projects.find(function (p) { return p.id === state.current; }) || null; }

  var toastTimer;
  function toast(msg) {
    var t = $('#toast');
    t.textContent = msg; t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, 5000);
  }

  /* ---------- network ---------- */
  function handle(r) {
    if (r.status === 401) { location.href = 'login.php'; throw new Error('Signed out'); }
    return r.text().then(function (txt) {
      var j = null;
      try { j = JSON.parse(txt); } catch (e) { /* not JSON */ }
      if (!r.ok) throw new Error((j && j.error) || 'The server could not do that (' + r.status + ').');
      if (j === null) throw new Error('The server sent something unexpected.');
      return j;
    });
  }
  function api(a, data, opts) {
    var body = JSON.stringify(Object.assign({ a: a }, data || {}));
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: body,
      credentials: 'same-origin',
      keepalive: !!(opts && opts.keepalive) && body.length < 60000
    }).then(handle);
  }
  function get(q) {
    return fetch('api.php?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(handle);
  }

  /* ---------- autosave ---------- */
  var dirty = {}, busy = {}, saveTimer = null, failing = false;
  function setStatus(text, bad) {
    var el = $('#saveState');
    el.textContent = text; el.className = 'save-state' + (bad ? ' bad' : '');
  }
  function queueSave(kind, id, fields) {
    var k = kind + ':' + id;
    var d = dirty[k] || (dirty[k] = { kind: kind, id: id, fields: {} });
    Object.assign(d.fields, fields);
    setStatus('Saving…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushAll, 700);
  }
  function requeue(job, delay) {
    var k = job.kind + ':' + job.id;
    var d = dirty[k] || (dirty[k] = { kind: job.kind, id: job.id, fields: {} });
    Object.keys(job.fields).forEach(function (f) { if (!(f in d.fields)) d.fields[f] = job.fields[f]; });
    clearTimeout(saveTimer);
    saveTimer = setTimeout(flushAll, delay);
  }
  function flushAll() {
    clearTimeout(saveTimer);
    var jobs = dirty; dirty = {};
    Object.keys(jobs).forEach(function (k) {
      var j = jobs[k];
      if (busy[k]) { requeue(j, 300); return; }
      busy[k] = true;
      api(j.kind + '.update', Object.assign({ id: j.id }, j.fields), { keepalive: true }).then(function () {
        busy[k] = false; failing = false;
        if (!Object.keys(dirty).length && !Object.keys(busy).some(function (b) { return busy[b]; })) setStatus('Saved');
      }).catch(function (err) {
        busy[k] = false;
        failing = true;
        setStatus('Not saved. Retrying…', true);
        requeue(j, 4000);
      });
    });
  }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushAll(); });
  window.addEventListener('pagehide', flushAll);
  window.addEventListener('online', flushAll);

  function armDelete(btn, fn) {
    var t;
    btn.addEventListener('click', function (e) {
      e.stopPropagation(); e.preventDefault();
      if (btn.dataset.armed) { clearTimeout(t); fn(); return; }
      btn.dataset.armed = '1';
      var old = btn.innerHTML;
      btn.classList.add('armed');
      btn.textContent = 'Delete?';
      t = setTimeout(function () { delete btn.dataset.armed; btn.classList.remove('armed'); btn.innerHTML = old; }, 2500);
    });
  }

  /* ---------- colour palette popover ---------- */
  var pal = $('#palette'), palAnchor = null;
  function closePalette() { pal.hidden = true; palAnchor = null; }
  function openPalette(anchor, current, cb) {
    if (!pal.hidden && palAnchor === anchor) { closePalette(); return; }
    pal.innerHTML = '';
    PALETTE.forEach(function (p) {
      var b = h('button', { type: 'button', class: 'sw' + (p[1] === current ? ' on' : ''), title: p[0], 'aria-label': p[0], style: 'background:' + p[1] });
      b.addEventListener('click', function () { cb(p[1]); closePalette(); });
      pal.append(b);
    });
    pal.hidden = false; palAnchor = anchor;
    var r = anchor.getBoundingClientRect(), w = pal.offsetWidth, hh = pal.offsetHeight;
    var x = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
    var y = r.bottom + 6; if (y + hh > window.innerHeight - 8) y = Math.max(8, r.top - hh - 6);
    pal.style.left = x + 'px'; pal.style.top = y + 'px';
  }
  document.addEventListener('pointerdown', function (e) {
    if (pal.hidden) return;
    if (pal.contains(e.target) || e.target.closest('[data-swatch]')) return;
    closePalette();
  });
  $('#scroll').addEventListener('scroll', closePalette, { passive: true });

  /* ---------- rail: project list ---------- */
  function renderRail() {
    var ul = $('#plist'); ul.innerHTML = '';
    state.projects.forEach(function (p) {
      var open = h('button', { class: 'p-open', type: 'button' },
        h('span', { class: 'p-name', text: p.name || 'Untitled' }),
        h('span', { class: 'p-count', text: String(p.count) }));
      open.addEventListener('click', function () { selectProject(p.id); });
      var del = h('button', { class: 'p-del', type: 'button', html: ICON.x, 'aria-label': 'Delete project ' + p.name });
      armDelete(del, function () { deleteProject(p); });
      ul.append(h('li', { class: 'pitem' + (p.id === state.current ? ' active' : '') }, open, del));
    });
    if (!state.projects.length) ul.append(h('li', { class: 'note', text: 'No projects yet. Create one above.', style: 'padding:8px 10px' }));
  }
  function selectProject(id) {
    if (id === state.current) { closeRail(); return; }
    flushAll();
    closeRail();
    openProject(id);
  }
  function openProject(id) {
    state.current = id; state.sections = []; state.loading = true;
    try { localStorage.setItem('pt.current', String(id)); } catch (e) { /* ignore */ }
    renderRail(); renderProject();
    return get('a=project&id=' + id).then(function (d) {
      if (state.current !== id) return;
      state.sections = d.sections; state.loading = false;
      renderProject();
    }).catch(function (err) {
      state.loading = false; renderProject(); toast(err.message);
    });
  }
  function deleteProject(p) {
    api('project.delete', { id: p.id }).then(function () {
      state.projects = state.projects.filter(function (x) { return x.id !== p.id; });
      if (state.current === p.id) {
        state.current = null; state.sections = [];
        if (state.projects.length) openProject(state.projects[0].id); else { renderRail(); renderProject(); }
      } else renderRail();
    }).catch(function (e) { toast(e.message); });
  }

  /* ---------- project window ---------- */
  function fit(ta) { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; }
  function fitAll() { document.querySelectorAll('.s-text').forEach(fit); }
  function urls(text) { return (text.match(/https?:\/\/[^\s<>"')]+/g) || []).filter(function (u, i, a) { return a.indexOf(u) === i; }); }
  function shortUrl(u) { return u.replace(/^https?:\/\//, '').replace(/\/$/, ''); }
  function hasFiles(e) { return !!(e.dataTransfer && [].indexOf.call(e.dataTransfer.types || [], 'Files') > -1); }

  /* Shrinks big photos before upload: max 1600px on the long side. */
  function prepare(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var long = Math.max(bmp.width, bmp.height);
      if (long <= 1600 && file.size < 2 * 1048576) { bmp.close && bmp.close(); return file; }
      var k = Math.min(1, 1600 / long);
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      bmp.close && bmp.close();
      var type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      return new Promise(function (res) {
        c.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { res(file); return; }
          var name = file.name;
          if (type === 'image/jpeg' && !/\.jpe?g$/i.test(name)) name = name.replace(/\.[^.]+$/, '') + '.jpg';
          res(new File([blob], name, { type: type }));
        }, type, 0.85);
      });
    }).catch(function () { return file; });
  }
  function pastedName(f) {
    if (f.name && f.name !== 'image.png') return f.name;
    var d = new Date(), p = function (n) { return String(n).padStart(2, '0'); };
    var e = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
    return 'pasted-' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + '.' + e;
  }
  function uploadOne(s, file) {
    var fd = new FormData();
    fd.append('a', 'file.upload'); fd.append('section_id', s.id); fd.append('file', file, file.name);
    return fetch('api.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd, credentials: 'same-origin' }).then(handle);
  }

  function sectionEl(s) {
    var card = h('article', { class: 'section', style: '--c:' + s.color, 'data-id': s.id });
    var grip = h('button', { class: 'grip', type: 'button', html: ICON.grip, title: 'Drag to reorder', 'aria-label': 'Drag to reorder' });
    grip.addEventListener('mousedown', function () { card.draggable = true; });
    grip.addEventListener('mouseup', function () { card.draggable = false; });
    var name = h('input', { class: 's-name', value: s.title, 'aria-label': 'Section name', autocomplete: 'off', maxLength: 120 });
    name.addEventListener('input', function () { s.title = name.value; queueSave('section', s.id, { title: name.value }); });
    var dot = h('button', { class: 's-btn', type: 'button', 'data-swatch': '1', title: 'Change colour', 'aria-label': 'Change colour' }, h('span', { class: 'dot' }));
    dot.addEventListener('click', function (e) {
      e.stopPropagation();
      openPalette(dot, s.color, function (c) { s.color = c; card.style.setProperty('--c', c); queueSave('section', s.id, { color: c }); });
    });
    var del = h('button', { class: 's-btn s-del', type: 'button', html: ICON.x, title: 'Delete section', 'aria-label': 'Delete section' });
    armDelete(del, function () {
      api('section.delete', { id: s.id }).then(function () {
        state.sections = state.sections.filter(function (x) { return x.id !== s.id; });
        var p = cur(); if (p) p.count = state.sections.length;
        renderRail(); renderProject();
      }).catch(function (e) { toast(e.message); });
    });

    var ta = h('textarea', { class: 's-text', rows: 3, value: s.body, placeholder: 'Type or paste links, notes, anything. This box grows as you write.', 'aria-label': s.title + ' text', spellcheck: false });
    var links = h('div', { class: 'links' });
    var thumbs = h('div', { class: 'thumbs' }), docs = h('div', { class: 'docs' });
    var note = h('span', { class: 'f-note', text: 'Drop, paste or attach images and documents' });
    var pick = h('input', { type: 'file', multiple: true, hidden: true });
    var attach = h('button', { class: 'attach', type: 'button', text: '+ Attach image or file' });
    var foot = h('div', { class: 's-foot' }, attach, note, pick);

    function paintLinks() {
      links.innerHTML = '';
      urls(ta.value).forEach(function (u) {
        links.append(h('a', { class: 'link-chip', href: u, target: '_blank', rel: 'noopener noreferrer', text: shortUrl(u), title: u }));
      });
    }
    function paintFiles() {
      thumbs.innerHTML = ''; docs.innerHTML = '';
      s.files.forEach(function (f) {
        var rm = h('button', { class: 'f-x', type: 'button', html: ICON.x, title: 'Remove', 'aria-label': 'Remove ' + f.name });
        armDelete(rm, function () {
          api('file.delete', { id: f.id }).then(function () {
            s.files = s.files.filter(function (x) { return x.id !== f.id; }); paintFiles();
          }).catch(function (e) { toast(e.message); });
        });
        if (f.image) {
          var b = h('button', { class: 'thumb', type: 'button', title: f.name + ' (' + fmt(f.size) + ')' }, h('img', { src: 'file.php?id=' + f.id, alt: f.name, loading: 'lazy', draggable: false }));
          b.addEventListener('click', function () { openLightbox(f); });
          thumbs.append(h('div', { class: 'thumb-wrap' }, b, rm));
        } else {
          docs.append(h('div', { class: 'doc-chip' },
            h('span', { class: 'ext', text: ext(f.name) }),
            h('a', { class: 'd-name', href: 'file.php?id=' + f.id + '&dl=1', text: f.name, title: 'Download ' + f.name }),
            h('span', { class: 'd-size', text: fmt(f.size) }), rm));
        }
      });
    }
    function setNote(text, cls) { note.className = 'f-note' + (cls ? ' ' + cls : ''); note.textContent = text; }
    function take(list) {
      var files = [].slice.call(list), skipped = 0;
      files = files.filter(function (f) { if (f.size > MAX) { skipped++; return false; } return true; });
      var chain = Promise.resolve(), failed = 0, lastErr = '';
      files.forEach(function (f0) {
        chain = chain.then(function () {
          setNote('Uploading ' + (f0.name || 'image') + '…', 'uploading');
          return prepare(new File([f0], pastedName(f0), { type: f0.type })).then(function (f) { return uploadOne(s, f); })
            .then(function (meta) { s.files.push(meta); paintFiles(); })
            .catch(function (e) { failed++; lastErr = e.message; });
        });
      });
      chain.then(function () {
        var msg = '';
        if (skipped) msg += skipped + (skipped === 1 ? ' file is' : ' files are') + ' over ' + MAX_MB + ' MB and was skipped. ';
        if (failed) msg += lastErr;
        if (msg) setNote(msg.trim(), 'bad'); else setNote('Drop, paste or attach images and documents');
      });
    }
    attach.addEventListener('click', function () { pick.click(); });
    pick.addEventListener('change', function () { take(pick.files); pick.value = ''; });

    ta.addEventListener('input', function () { s.body = ta.value; fit(ta); paintLinks(); queueSave('section', s.id, { body: ta.value }); });
    ta.addEventListener('paste', function (e) {
      var fs = e.clipboardData && e.clipboardData.files;
      if (fs && fs.length) { e.preventDefault(); take(fs); }
    });
    paintLinks(); paintFiles();

    card.addEventListener('dragover', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; card.classList.add('file-over');
    });
    card.addEventListener('dragleave', function (e) { if (!card.contains(e.relatedTarget)) card.classList.remove('file-over'); });
    card.addEventListener('drop', function (e) {
      if (!hasFiles(e)) return;
      e.preventDefault(); card.classList.remove('file-over'); take(e.dataTransfer.files);
    });
    card.addEventListener('dragstart', function (e) {
      if (!card.draggable) return;
      drag = { type: 'section', id: s.id };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(s.id));
      setTimeout(function () { card.classList.add('dragging'); }, 0);
    });
    card.addEventListener('dragend', function () { card.draggable = false; card.classList.remove('dragging'); drag = null; clearMarker(); });

    card.append(h('div', { class: 's-head' }, grip, name, dot, del), ta, links, thumbs, docs, foot);
    return card;
  }

  function renderProject() {
    var p = cur(), list = $('#sections'), name = $('#projName'), hint = $('#dropHint');
    list.innerHTML = '';
    if (!p) {
      name.value = ''; name.disabled = true; name.placeholder = 'No project selected';
      $('#projMeta').textContent = '';
      hint.className = 'drop-hint big';
      hint.innerHTML = '<strong>' + (state.projects.length ? 'Pick a project' : 'No projects yet') + '</strong>' +
        (state.projects.length ? 'Choose one from the list, or create a new one.' : 'Create your first project on the left to get started.');
      return;
    }
    name.disabled = false; name.value = p.name;
    if (state.loading) {
      $('#projMeta').textContent = '';
      hint.className = 'drop-hint big'; hint.innerHTML = '<strong>Loading…</strong>';
      return;
    }
    var n = state.sections.length;
    $('#projMeta').textContent = n + (n === 1 ? ' section' : ' sections');
    state.sections.forEach(function (s) { list.append(sectionEl(s)); });
    if (n) {
      hint.className = 'drop-hint';
      hint.innerHTML = '<strong>Drop a title here</strong>Drag from the Tabs tray below, or tap a title to add it to the end.';
    } else {
      hint.className = 'drop-hint big';
      hint.innerHTML = '<strong>This project is empty</strong>Drag a title from the Tabs tray below, or tap one, to add your first section.';
    }
    requestAnimationFrame(fitAll);
  }

  $('#projName').addEventListener('input', function () {
    var p = cur(); if (!p) return;
    p.name = this.value; renderRail();
    queueSave('project', p.id, { name: this.value });
  });

  function addSection(t, index) {
    var p = cur(); if (!p || state.loading) return;
    if (index == null || index > state.sections.length) index = state.sections.length;
    api('section.add', { project_id: p.id, title: t.title, color: t.color, index: index }).then(function (s) {
      if (state.current !== p.id) return;
      state.sections.splice(index, 0, s);
      p.count = state.sections.length;
      renderRail(); renderProject();
      var card = document.querySelector('.section[data-id="' + s.id + '"]');
      if (card) {
        card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        var ta = card.querySelector('.s-text'); if (ta) ta.focus({ preventScroll: true });
      }
    }).catch(function (e) { toast(e.message); });
  }

  /* ---------- drag and drop (mouse) ---------- */
  var marker = h('div', { class: 'drop-marker', text: 'Drop here' });
  function clearMarker() { if (marker.parentNode) marker.remove(); }
  function placeMarker(y) {
    var list = $('#sections');
    var cards = [].slice.call(list.querySelectorAll('.section:not(.dragging)'));
    var before = null;
    for (var i = 0; i < cards.length; i++) {
      var r = cards[i].getBoundingClientRect();
      if (y < r.top + r.height / 2) { before = cards[i]; break; }
    }
    if (marker.parentNode === list && marker.nextElementSibling === before) return;
    list.insertBefore(marker, before);
  }
  function markerIndex() {
    var n = 0, ch = $('#sections').children;
    for (var i = 0; i < ch.length; i++) {
      if (ch[i] === marker) break;
      if (ch[i].classList.contains('section')) n++;
    }
    return n;
  }
  var scroller = $('#scroll');
  scroller.addEventListener('dragover', function (e) {
    if (!drag || !cur()) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = drag.type === 'tray' ? 'copy' : 'move';
    placeMarker(e.clientY);
  });
  scroller.addEventListener('dragleave', function (e) { if (!scroller.contains(e.relatedTarget)) clearMarker(); });
  scroller.addEventListener('drop', function (e) {
    if (!drag || !cur()) return;
    e.preventDefault();
    var idx = markerIndex(), p = cur(), d = drag;
    clearMarker(); drag = null;
    if (d.type === 'tray') {
      var t = state.tray.find(function (x) { return x.id === d.id; });
      if (t) addSection(t, idx);
    } else {
      var from = state.sections.findIndex(function (x) { return x.id === d.id; });
      if (from < 0) return;
      var item = state.sections.splice(from, 1)[0];
      if (from < idx) idx--;
      state.sections.splice(idx, 0, item);
      renderProject();
      api('section.reorder', { project_id: p.id, ids: state.sections.map(function (x) { return x.id; }) })
        .catch(function (err) { toast(err.message); openProject(p.id); });
    }
  });
  document.addEventListener('dragover', function (e) { if (hasFiles(e)) e.preventDefault(); });
  document.addEventListener('drop', function (e) { if (hasFiles(e)) e.preventDefault(); });

  /* ---------- Tabs tray ---------- */
  function renderTray() {
    var box = $('#trayList'); box.innerHTML = '';
    state.tray.forEach(function (t) {
      var chip = h('div', { class: 'chip', draggable: true, style: '--c:' + t.color, 'data-id': t.id });
      var dot = h('button', { class: 'c-dot', type: 'button', 'data-swatch': '1', title: 'Change colour', 'aria-label': 'Change colour of ' + t.title });
      dot.addEventListener('click', function (e) {
        e.stopPropagation();
        openPalette(dot, t.color, function (c) {
          t.color = c; chip.style.setProperty('--c', c);
          api('tray.update', { id: t.id, color: c }).catch(function (e2) { toast(e2.message); });
        });
      });
      var label = h('button', { class: 'c-add', type: 'button', text: t.title, title: 'Tap to add to project. Double-click to rename.' });
      label.addEventListener('click', function () { addSection(t, null); });
      label.addEventListener('dblclick', function (e) {
        e.preventDefault();
        var inp = h('input', { value: t.title, 'aria-label': 'Rename title', maxLength: 120 });
        label.replaceWith(inp); inp.focus(); inp.select();
        var done = false;
        function commit() {
          if (done) return; done = true;
          var v = inp.value.trim();
          if (v && v !== t.title) {
            t.title = v;
            api('tray.update', { id: t.id, title: v }).catch(function (e2) { toast(e2.message); });
          }
          renderTray();
        }
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') commit(); if (ev.key === 'Escape') { done = true; renderTray(); } });
        inp.addEventListener('blur', commit);
      });
      var x = h('button', { class: 'c-x', type: 'button', html: ICON.x, title: 'Delete title', 'aria-label': 'Delete title ' + t.title });
      armDelete(x, function () {
        api('tray.delete', { id: t.id }).then(function () {
          state.tray = state.tray.filter(function (a) { return a.id !== t.id; }); renderTray();
        }).catch(function (e) { toast(e.message); });
      });
      chip.addEventListener('dragstart', function (e) {
        drag = { type: 'tray', id: t.id };
        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('text/plain', t.title);
      });
      chip.addEventListener('dragend', function () { drag = null; clearMarker(); });
      chip.append(dot, label, x);
      box.append(chip);
    });
    $('#trayCount').textContent = String(state.tray.length);
  }
  $('#trayForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var inp = $('#trayNew'), v = inp.value.trim(); if (!v) return;
    var color = PALETTE[state.tray.length % PALETTE.length][1];
    api('tray.add', { title: v, color: color }).then(function (t) {
      state.tray.push(t); inp.value = ''; renderTray();
    }).catch(function (err) { toast(err.message); });
  });
  $('#chev').innerHTML = ICON.chev;
  $('#trayToggle').addEventListener('click', function () {
    var open = $('#tray').classList.toggle('open');
    this.setAttribute('aria-expanded', String(open));
    try { localStorage.setItem('pt.tray', open ? '1' : '0'); } catch (e) { /* ignore */ }
    closePalette();
  });
  try { var savedTray = localStorage.getItem('pt.tray'); if (savedTray === '0' || (savedTray === null && window.innerWidth < 820)) { $('#tray').classList.remove('open'); $('#trayToggle').setAttribute('aria-expanded', 'false'); } } catch (e) { /* ignore */ }

  /* ---------- lightbox ---------- */
  var lb = $('#lightbox');
  function openLightbox(f) {
    var im = lb.querySelector('img'); im.src = 'file.php?id=' + f.id; im.alt = f.name;
    lb.querySelector('.lb-cap').textContent = f.name + ' · ' + fmt(f.size);
    var dl = lb.querySelector('.lb-dl'); dl.href = 'file.php?id=' + f.id + '&dl=1'; dl.setAttribute('download', f.name);
    lb.hidden = false; lb.querySelector('.lb-close').focus();
  }
  function closeLightbox() { lb.hidden = true; lb.querySelector('img').removeAttribute('src'); }
  lb.addEventListener('click', function (e) { if (e.target === lb || e.target.closest('.lb-close')) closeLightbox(); });

  /* ---------- mobile drawer, new project, sign out ---------- */
  $('#menuBtn').innerHTML = ICON.menu;
  function closeRail() { $('#appView').classList.remove('rail-open'); }
  $('#menuBtn').addEventListener('click', function () { $('#appView').classList.add('rail-open'); });
  $('#scrim').addEventListener('click', closeRail);

  $('#newProjectForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var inp = $('#newProject'), v = inp.value.trim(); if (!v) return;
    flushAll();
    api('project.create', { name: v }).then(function (p) {
      state.projects.unshift({ id: p.id, name: p.name, count: 0 });
      inp.value = ''; closeRail();
      state.current = p.id; state.sections = []; state.loading = false;
      try { localStorage.setItem('pt.current', String(p.id)); } catch (e2) { /* ignore */ }
      renderRail(); renderProject();
    }).catch(function (err) { toast(err.message); });
  });
  $('#signOut').addEventListener('click', function () {
    flushAll();
    api('logout').catch(function () { /* ignore */ }).then(function () { location.href = 'login.php'; });
  });

  /* ---------- settings (password and two-factor) ---------- */
  var settings = $('#settings');
  $('#settingsClose').innerHTML = ICON.x;
  function openSettings() { settings.hidden = false; renderSettings(); $('#settingsClose').focus(); }
  function closeSettings() { settings.hidden = true; $('#settingsBody').innerHTML = ''; }
  $('#settingsBtn').addEventListener('click', function () { closeRail(); openSettings(); });
  $('#settingsClose').addEventListener('click', closeSettings);
  settings.addEventListener('click', function (e) { if (e.target === settings) closeSettings(); });

  function copyText(text, done) {
    function fallback() {
      var ta = h('textarea', { value: text, style: 'position:fixed;opacity:0' });
      document.body.append(ta); ta.select();
      try { document.execCommand('copy'); done(true); } catch (e) { done(false); }
      ta.remove();
    }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
    else fallback();
  }
  function field(id, label, type, autocomplete) {
    return h('div', { class: 'field' }, h('label', { for: id, text: label }),
      h('input', { id: id, type: type || 'text', autocomplete: autocomplete || 'off' }));
  }
  function showCodes(box, codes) {
    box.innerHTML = '';
    box.append(
      h('p', { class: 'msg-ok', text: 'Save these recovery codes now. Each one works once if you lose your phone.' }),
      h('div', { class: 'codes' }, codes.map(function (c) { return h('span', { text: c }); })));
    var copy = h('button', { class: 'btn small alt', type: 'button', text: 'Copy codes' });
    copy.addEventListener('click', function () { copyText(codes.join('\n'), function (ok) { copy.textContent = ok ? 'Copied' : 'Select the codes and copy them'; }); });
    box.append(copy);
  }

  /* ---------- backup: download, and automatic copy to Google Drive ---------- */
  function localTime(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return iso;
    return d.toLocaleString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function copyBtn(text) {
    var b = h('button', { class: 'btn small alt', type: 'button', text: 'Copy' });
    b.addEventListener('click', function () { copyText(text, function (ok) { b.textContent = ok ? 'Copied' : 'Select it and copy'; }); });
    return b;
  }
  function isPhone() {
    return /iPhone|iPad|Android/i.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
  }

  function downloadBackup(btn, msg) {
    var idle = 'Download backup';
    btn.disabled = true; btn.textContent = 'Preparing…'; msg.className = 'note'; msg.textContent = '';
    fetch('export.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { location.href = 'login.php'; throw new Error('Signed out'); }
      if (!r.ok) return r.json().then(function (j) { throw new Error(j.error || 'The backup could not be built.'); });
      var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
      return r.blob().then(function (b) { return new File([b], m ? m[1] : 'project-tabs-backup.zip', { type: 'application/zip' }); });
    }).then(function (file) {
      function save() {
        if (isPhone() && navigator.canShare && navigator.canShare({ files: [file] })) {
          return navigator.share({ files: [file], title: 'Project Tabs backup' });
        }
        var url = URL.createObjectURL(file), a = h('a', { href: url, download: file.name });
        document.body.append(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 15000);
        return Promise.resolve();
      }
      return save().then(function () {
        msg.className = 'msg-ok'; msg.textContent = 'Backup ready: ' + file.name + ' (' + fmt(file.size) + ')';
      }).catch(function (e) {
        if (e && e.name === 'AbortError') return;
        // Some phones only allow sharing straight after a tap, so offer a second tap.
        var again = h('button', { class: 'btn small', type: 'button', text: 'Tap to save the backup (' + fmt(file.size) + ')' });
        again.addEventListener('click', function () {
          save().then(function () { again.remove(); }).catch(function (e2) {
            if (!e2 || e2.name !== 'AbortError') toast('This device would not save the file. Try from a computer.');
          });
        });
        msg.textContent = ''; msg.append(again);
      });
    }).catch(function (e) { msg.className = 'err'; msg.textContent = e.message; })
      .then(function () { btn.disabled = false; btn.textContent = idle; });
  }

  function driveSetupForm(box, d, redraw) {
    var idIn = field('gClientId', 'Client ID', 'text', 'off');
    var secIn = field('gClientSecret', 'Client secret', 'password', 'off');
    idIn.querySelector('input').value = d.client_id || '';
    var err = h('p', { class: 'err', role: 'alert' });
    var save = h('button', { class: 'btn small', type: 'button', text: 'Save keys' });
    save.addEventListener('click', function () {
      api('drive.client', { client_id: idIn.querySelector('input').value, client_secret: secIn.querySelector('input').value })
        .then(redraw).catch(function (e) { err.textContent = e.message; });
    });
    box.append(
      h('p', { text: 'Sends a fresh backup to your own Google Drive every night and keeps the latest 14. It needs a one-time setup in Google Cloud (about 10 minutes, free). The full steps are in INSTALL.md.' }),
      h('ol', {},
        h('li', { text: 'In Google Cloud Console, create a project and turn on the Google Drive API.' }),
        h('li', { text: 'Set up the OAuth consent screen with the scope .../auth/drive.file, then publish the app. If you leave it in Testing, Google ends the connection after 7 days.' }),
        h('li', { text: 'Create an OAuth client ID of type "Web application" and add this as its redirect address:' })),
      h('div', { class: 'secret', text: d.redirect_uri }), h('div', { class: 'set-row' }, copyBtn(d.redirect_uri)),
      h('p', { text: 'Then paste the Client ID and Client secret Google shows you:' }),
      idIn, secIn, err, h('div', { class: 'set-row' }, save));
  }

  function drawDrive(box) {
    while (box.children.length > 1) box.lastChild.remove();
    var loading = h('p', { class: 'note', text: 'Loading…' });
    box.append(loading);
    get('a=drive').then(function (d) {
      loading.remove();
      function redraw() { drawDrive(box); }
      if (d.last_error) box.append(h('p', { class: 'err', text: d.last_error }));

      if (!d.client_saved) { driveSetupForm(box, d, redraw); return; }

      if (!d.connected) {
        var go = h('button', { class: 'btn small', type: 'button', text: 'Connect Google Drive' });
        go.addEventListener('click', function () { location.href = 'gdrive.php?go=1'; });
        var change = h('button', { class: 'link-btn', type: 'button', text: 'Change Google keys' });
        change.addEventListener('click', function () { while (box.children.length > 1) box.lastChild.remove(); driveSetupForm(box, d, redraw); });
        box.append(h('p', { text: 'Your keys are saved. Next, sign in to Google and allow Project Tabs to create files in your Drive.' }),
          h('div', { class: 'set-row' }, go), change);
        return;
      }

      var now = h('button', { class: 'btn small', type: 'button', text: 'Back up now' });
      now.addEventListener('click', function () {
        now.disabled = true; now.textContent = 'Backing up…';
        api('drive.backup').then(function (r) { toast('Saved to Google Drive: ' + r.name); })
          .catch(function (e) { toast(e.message); }).then(redraw);
      });
      var off = h('button', { class: 'btn small alt', type: 'button', text: 'Disconnect' }), armed = false, t;
      off.addEventListener('click', function () {
        if (!armed) {
          armed = true; off.textContent = 'Really disconnect?';
          t = setTimeout(function () { armed = false; off.textContent = 'Disconnect'; }, 3000); return;
        }
        clearTimeout(t);
        api('drive.disconnect').then(redraw).catch(function (e) { toast(e.message); });
      });
      box.append(
        h('p', { class: 'msg-ok', text: 'Connected to Google Drive.' }),
        h('p', { text: d.last_ok ? 'Last backup: ' + localTime(d.last_ok) + ' · ' + d.last_name + ' (' + fmt(d.last_bytes) + ')' : 'No backup yet. Tap "Back up now" to try it.' }),
        h('div', { class: 'set-row' }, now, off),
        h('p', { text: 'For automatic nightly backups, add this command in cPanel > Cron Jobs, set to once a day:' }),
        h('div', { class: 'secret', text: d.cron_command }), h('div', { class: 'set-row' }, copyBtn(d.cron_command)),
        h('p', { class: 'note', text: 'Backups go into a folder called "Project Tabs backups" in your Drive. Only the newest 14 are kept.' }));
    }).catch(function (e) { loading.textContent = e.message; });
  }

  function renderBackup(into) {
    var dl = h('button', { class: 'btn small', type: 'button', text: 'Download backup' });
    var dlMsg = h('p', { class: 'note' });
    dl.addEventListener('click', function () { downloadBackup(dl, dlMsg); });
    var driveBox = h('section', { class: 'set-block' }, h('h3', { text: 'Automatic backup to Google Drive' }));
    into.append(
      h('section', { class: 'set-block' }, h('h3', { text: 'Backup' }),
        h('p', { text: 'Download everything as one zip: your text, links, images and documents. On a phone you can save it straight to iCloud Drive, Google Drive or Files.' }),
        h('div', { class: 'set-row' }, dl), dlMsg),
      driveBox);
    drawDrive(driveBox);
  }

  function renderSettings() {
    var body = $('#settingsBody'); body.innerHTML = '';
    var me = state.me;

    // Two-factor
    var tf = h('section', { class: 'set-block' }, h('h3', { text: 'Two-factor sign-in' }));
    var tfBody = h('div', { class: 'set-block' });
    tf.append(tfBody);
    function drawTf() {
      tfBody.innerHTML = '';
      if (!me.totp) {
        tfBody.append(h('p', { text: 'Off. Turn it on to ask for a 6-digit code from an authenticator app (Google Authenticator, Authy, 1Password) every time you sign in.' }));
        var start = h('button', { class: 'btn small', type: 'button', text: 'Set up' });
        start.addEventListener('click', function () {
          start.disabled = true;
          api('2fa.begin').then(function (d) {
            tfBody.innerHTML = '';
            var qr = qrcode(0, 'M'); qr.addData(d.uri); qr.make();
            var qrBox = h('div', { class: 'qr', 'aria-label': 'QR code' });
            qrBox.innerHTML = qr.createSvgTag(5, 0);
            var err = h('p', { class: 'err', role: 'alert' });
            var code = field('tfCode', 'Code from the app', 'text', 'one-time-code');
            code.querySelector('input').setAttribute('inputmode', 'numeric');
            code.querySelector('input').maxLength = 7;
            var on = h('button', { class: 'btn small', type: 'button', text: 'Turn on' });
            on.addEventListener('click', function () {
              api('2fa.enable', { code: code.querySelector('input').value }).then(function (r) {
                me.totp = true; tfBody.innerHTML = '';
                var box = h('div', { class: 'set-block' }); tfBody.append(box); showCodes(box, r.recovery);
              }).catch(function (e) { err.textContent = e.message; });
            });
            tfBody.append(
              h('p', { text: '1. Scan this QR code with your authenticator app.' }), qrBox,
              h('p', { text: 'Cannot scan? Type this key into the app instead:' }), h('div', { class: 'secret', text: d.secret }),
              h('p', { text: '2. Enter the 6-digit code the app shows.' }), code, err, on);
          }).catch(function (e) { start.disabled = false; toast(e.message); });
        });
        tfBody.append(start);
      } else {
        tfBody.append(h('p', { class: 'msg-ok', text: 'On. You will be asked for a code when you sign in.' }));
        var pw = field('tfPw', 'Your password (to confirm)', 'password', 'current-password');
        var err2 = h('p', { class: 'err', role: 'alert' });
        var out = h('div', { class: 'set-block' });
        var again = h('button', { class: 'btn small alt', type: 'button', text: 'New recovery codes' });
        var off = h('button', { class: 'btn small alt', type: 'button', text: 'Turn off' });
        again.addEventListener('click', function () {
          api('2fa.recovery', { password: pw.querySelector('input').value }).then(function (r) { err2.textContent = ''; showCodes(out, r.recovery); })
            .catch(function (e) { err2.textContent = e.message; });
        });
        off.addEventListener('click', function () {
          api('2fa.disable', { password: pw.querySelector('input').value }).then(function () { me.totp = false; drawTf(); })
            .catch(function (e) { err2.textContent = e.message; });
        });
        tfBody.append(pw, err2, h('div', { class: 'set-row' }, again, off), out);
      }
    }
    drawTf();

    // Password
    var pwBlock = h('section', { class: 'set-block' }, h('h3', { text: 'Change password' }));
    var cp = field('pwCur', 'Current password', 'password', 'current-password');
    var np = field('pwNew', 'New password (10+ characters)', 'password', 'new-password');
    var np2 = field('pwNew2', 'Repeat new password', 'password', 'new-password');
    var pErr = h('p', { class: 'err', role: 'alert' });
    var pBtn = h('button', { class: 'btn small', type: 'button', text: 'Change password' });
    pBtn.addEventListener('click', function () {
      var a = np.querySelector('input').value;
      if (a !== np2.querySelector('input').value) { pErr.className = 'err'; pErr.textContent = 'The new passwords do not match.'; return; }
      api('password.change', { current: cp.querySelector('input').value, new: a }).then(function () {
        pErr.className = 'msg-ok'; pErr.textContent = 'Password changed.';
        [cp, np, np2].forEach(function (f) { f.querySelector('input').value = ''; });
      }).catch(function (e) { pErr.className = 'err'; pErr.textContent = e.message; });
    });
    pwBlock.append(cp, np, np2, pErr, pBtn);

    body.append(h('p', { class: 'note', text: 'Signed in as ' + me.username }));
    renderBackup(body);
    body.append(tf, pwBlock);
  }

  /* ---------- keyboard ---------- */
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (!pal.hidden) closePalette();
    else if (!lb.hidden) closeLightbox();
    else if (!settings.hidden) closeSettings();
    else closeRail();
  });

  /* ---------- start ---------- */
  function start() {
    get('a=bootstrap').then(function (d) {
      state.me = d.me; state.projects = d.projects; state.tray = d.tray;
      renderTray(); renderRail();
      var last = null;
      try { last = parseInt(localStorage.getItem('pt.current'), 10); } catch (e) { /* ignore */ }
      var pick = state.projects.find(function (p) { return p.id === last; }) || state.projects[0] || null;
      if (pick) openProject(pick.id); else renderProject();
      var hsh = location.hash;
      if (hsh === '#drive-ok' || hsh === '#drive-error') {
        history.replaceState(null, '', location.pathname + location.search);
        openSettings();
        toast(hsh === '#drive-ok' ? 'Google Drive connected. Tap "Back up now" to try it.' : 'Google Drive could not be connected. The reason is shown in Settings.');
      }
    }).catch(function (err) {
      $('#dropHint').className = 'drop-hint big';
      $('#dropHint').innerHTML = '<strong>Could not load your projects</strong>';
      $('#dropHint').append(document.createTextNode(err.message + ' Reload the page to try again.'));
    });
  }
  start();

  window.addEventListener('resize', fitAll);
  window.addEventListener('load', fitAll);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitAll);

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () { /* ignore */ }); });
  }
})();
