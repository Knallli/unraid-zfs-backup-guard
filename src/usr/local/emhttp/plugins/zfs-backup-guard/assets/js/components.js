/* ZFS Backup Guard – UI-Bausteine */
(function () {
  'use strict';
  const Z = window.ZBG;
  const { esc } = Z;

  /* ---------- Icons (Lucide-Stil, MIT) ---------- */
  const P = {
    shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    shieldCheck: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    shieldAlert: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
    play: '<polygon points="6 3 20 12 6 21 6 3"/>',
    pause: '<rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/>',
    stop: '<rect width="14" height="14" x="5" y="5" rx="2"/>',
    flask: '<path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
    clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
    drive: '<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>',
    camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
    folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    sliders: '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
    plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
    trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    edit: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    restore: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    right: '<path d="m9 18 6-6-6-6"/>',
    left: '<path d="m15 18-6-6 6-6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    arrow: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    cloud: '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
    layers: '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    zap: '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    box: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    calendar: '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    archive: '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
    terminal: '<polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/>',
    server: '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
    more: '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    eye: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    grid: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    sparkles: '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
    snowflake: '<line x1="2" x2="22" y1="12" y2="12"/><line x1="12" x2="12" y1="2" y2="22"/><path d="m20 16-4-4 4-4"/><path d="m4 8 4 4-4 4"/><path d="m16 4-4 4-4-4"/><path d="m8 20 4-4 4 4"/>',
  };
  Z.icon = (name, cls = '') =>
    `<svg class="zi ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[name] || P.info}</svg>`;

  /* ---------- Status ---------- */
  Z.STATUS = {
    ok: { label: 'Geschützt', tone: 'ok', icon: 'shieldCheck' },
    stale: { label: 'Überfällig', tone: 'warn', icon: 'clock' },
    failed: { label: 'Fehlgeschlagen', tone: 'err', icon: 'shieldAlert' },
    never: { label: 'Noch nie gelaufen', tone: 'idle', icon: 'shield' },
    disabled: { label: 'Pausiert', tone: 'idle', icon: 'pause' },
    running: { label: 'Läuft', tone: 'run', icon: 'refresh' },
  };

  Z.ui = {
    badge: (text, tone = 'neutral', icon) => `<span class="zbg-badge tone-${tone}">${icon ? Z.icon(icon) : ''}${esc(text)}</span>`,
    dot: (tone) => `<span class="zbg-dot tone-${tone}"></span>`,

    btn(label, { act, icon, kind = 'ghost', data = {}, disabled, title, size } = {}) {
      const d = Object.entries(data).map(([k, v]) => ` data-${k}="${esc(v)}"`).join('');
      return `<button type="button" class="zbg-btn ${kind}${size ? ' ' + size : ''}${label ? '' : ' icon-only'}"${act ? ` data-act="${act}"` : ''}${d}${disabled ? ' disabled' : ''}${title ? ` title="${esc(title)}" aria-label="${esc(title)}"` : ''}>${icon ? Z.icon(icon) : ''}${label ? `<span>${esc(label)}</span>` : ''}</button>`;
    },

    /** Donut aus Segmenten [{value,tone}] */
    ring(segments, { size = 132, stroke = 12, center = '' } = {}) {
      const r = (size - stroke) / 2;
      const c = 2 * Math.PI * r;
      const total = segments.reduce((s, x) => s + x.value, 0) || 1;
      let off = 0;
      const gap = segments.filter((s) => s.value > 0).length > 1 ? 4 : 0;
      const arcs = segments
        .filter((s) => s.value > 0)
        .map((s) => {
          const len = (s.value / total) * c;
          const el = `<circle class="ring-seg tone-${s.tone}" r="${r}" cx="${size / 2}" cy="${size / 2}" stroke-width="${stroke}" stroke-dasharray="${Math.max(len - gap, 0.1)} ${c}" stroke-dashoffset="${-off}" />`;
          off += len;
          return el;
        })
        .join('');
      return `<div class="zbg-ring" style="width:${size}px;height:${size}px"><svg viewBox="0 0 ${size} ${size}"><circle class="ring-track" r="${r}" cx="${size / 2}" cy="${size / 2}" stroke-width="${stroke}"/>${arcs}</svg><div class="ring-center">${center}</div></div>`;
    },

    /** Balken-Sparkline der letzten Läufe (Höhe = Dauer, Farbe = Ergebnis) */
    sparkline(runs, { height = 34, key = 'duration' } = {}) {
      const n = 30;
      const list = runs.slice(-n);
      if (!list.length) return `<div class="zbg-spark empty">Noch keine Läufe</div>`;
      const max = Math.max(...list.map((r) => r[key] || 0), 1);
      const bars = list
        .map((r) => {
          const h = Math.max(3, Math.round(((r[key] || 0) / max) * height));
          const tone = r.result === 'success' ? 'ok' : r.result === 'failed' ? 'err' : r.result === 'dry-run' ? 'info' : 'warn';
          const tip = `${Z.fmt.dateFull(r.start)} · ${Z.fmt.duration(r.duration)} · ${Z.fmt.bytes(r.bytes)}`;
          return `<i class="tone-${tone}" style="height:${h}px" title="${esc(tip)}"></i>`;
        })
        .join('');
      const pad = Array(Math.max(0, n - list.length)).fill('<i class="sp-pad"></i>').join('');
      return `<div class="zbg-spark" style="height:${height}px">${pad}${bars}</div>`;
    },

    progress(pct, { tone = 'accent', striped = false, label } = {}) {
      return `<div class="zbg-progress tone-${tone}${striped ? ' striped' : ''}"><div style="width:${Z.clamp(pct, 0, 100)}%"></div>${label ? `<span>${esc(label)}</span>` : ''}</div>`;
    },

    meter(used, total, { warnAt = 80, errAt = 90 } = {}) {
      const pct = total ? (used / total) * 100 : 0;
      const tone = pct >= errAt ? 'err' : pct >= warnAt ? 'warn' : 'ok';
      return `<div class="zbg-meter tone-${tone}"><div style="width:${Z.clamp(pct, 0, 100)}%"></div></div>`;
    },

    seg(name, options, value, { act = 'seg' } = {}) {
      return `<div class="zbg-seg" role="radiogroup">${options
        .map(
          (o) =>
            `<button type="button" role="radio" aria-checked="${o.value === value}" class="${o.value === value ? 'on' : ''}" data-act="${act}" data-name="${esc(name)}" data-value="${esc(o.value)}"${o.disabled ? ' disabled' : ''}>${o.icon ? Z.icon(o.icon) : ''}<span>${esc(o.label)}</span></button>`
        )
        .join('')}</div>`;
    },

    toggle(bind, on, label) {
      return `<label class="zbg-switch"><input type="checkbox" data-bind="${esc(bind)}"${on ? ' checked' : ''}><span class="track"><span class="thumb"></span></span>${label ? `<span class="lbl">${esc(label)}</span>` : ''}</label>`;
    },

    empty(icon, title, text, cta = '') {
      return `<div class="zbg-empty"><div class="empty-ic">${Z.icon(icon)}</div><h3>${esc(title)}</h3><p>${esc(text)}</p>${cta}</div>`;
    },

    kv(rows) {
      return `<dl class="zbg-kv">${rows.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${v}</dd>`).join('')}</dl>`;
    },

    skeleton(lines = 3) {
      return `<div class="zbg-skel">${Array(lines).fill('<div></div>').join('')}</div>`;
    },
  };

  /* ---------- Toasts ---------- */
  Z.toast = function (msg, tone = 'ok', ms = 4200) {
    let host = Z.$('.zbg-toasts');
    if (!host) {
      host = document.createElement('div');
      host.className = 'zbg-toasts';
      Z.root().appendChild(host);
    }
    const el = document.createElement('div');
    el.className = `zbg-toast tone-${tone}`;
    el.innerHTML = `${Z.icon(tone === 'err' ? 'alert' : tone === 'warn' ? 'info' : 'check')}<span>${esc(msg)}</span>`;
    host.appendChild(el);
    void el.offsetWidth; // Reflow erzwingen, damit die Transition startet (rAF ist in Hintergrund-Tabs gedrosselt)
    el.classList.add('in');
    setTimeout(() => {
      el.classList.remove('in');
      setTimeout(() => el.remove(), 300);
    }, ms);
  };
  Z.fail = (e) => Z.toast(e && e.message ? e.message : String(e), 'err', 6500);

  /* ---------- Modale & Sheets ---------- */
  const layers = [];
  Z.modal = function ({ title, body, footer = '', size = 'md', kind = 'modal', onClose, cls = '' }) {
    const id = 'm' + Z.uid();
    const el = document.createElement('div');
    el.className = `zbg-layer ${kind}`;
    el.id = id;
    el.innerHTML = `<div class="zbg-backdrop" data-act="closeLayer"></div>
      <div class="zbg-dialog size-${size} ${cls}" role="dialog" aria-modal="true" aria-labelledby="${id}-t">
        <header><h2 id="${id}-t">${title}</h2>${Z.ui.btn('', { act: 'closeLayer', icon: 'x', title: 'Schließen' })}</header>
        <div class="zbg-dialog-body">${body}</div>
        ${footer ? `<footer>${footer}</footer>` : ''}
      </div>`;
    Z.root().appendChild(el);
    document.body.classList.add('zbg-noscroll');
    void el.offsetWidth; // Reflow erzwingen, damit die Transition startet (rAF ist in Hintergrund-Tabs gedrosselt)
    el.classList.add('in');
    const layer = { id, el, onClose };
    layers.push(layer);
    setTimeout(() => el.querySelector('[autofocus], .zbg-dialog input, .zbg-dialog button.primary')?.focus(), 60);
    return layer;
  };
  Z.closeLayer = function (layer) {
    layer = layer || layers[layers.length - 1];
    if (!layer) return;
    const i = layers.indexOf(layer);
    if (i >= 0) layers.splice(i, 1);
    layer.el.classList.remove('in');
    setTimeout(() => layer.el.remove(), 220);
    if (!layers.length) document.body.classList.remove('zbg-noscroll');
    layer.onClose && layer.onClose();
  };
  Z.closeTopLayer = () => Z.closeLayer();
  Z.topLayer = () => layers[layers.length - 1];
  Z.actions.closeLayer = () => Z.closeLayer();
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape' && layers.length) Z.closeLayer();
  });

  Z.confirm = function ({ title, text, ok = 'Bestätigen', tone = 'danger', icon = 'alert' }) {
    return new Promise((resolve) => {
      let done = false;
      const layer = Z.modal({
        title: esc(title),
        size: 'sm',
        body: `<div class="zbg-confirm tone-${tone}"><div class="cf-ic">${Z.icon(icon)}</div><p>${text}</p></div>`,
        footer: `${Z.ui.btn('Abbrechen', { act: 'cfNo' })}${Z.ui.btn(ok, { act: 'cfYes', kind: tone === 'danger' ? 'danger' : 'primary' })}`,
        onClose: () => !done && resolve(false),
      });
      Z.actions.cfYes = () => {
        done = true;
        Z.closeLayer(layer);
        resolve(true);
      };
      Z.actions.cfNo = () => Z.closeLayer(layer);
    });
  };

  Z.copy = async function (text) {
    try {
      await navigator.clipboard.writeText(text);
      Z.toast('In die Zwischenablage kopiert');
    } catch (e) {
      Z.toast('Kopieren nicht möglich – bitte manuell markieren', 'warn');
    }
  };
  Z.actions.copy = (el) => Z.copy(el.dataset.text || el.closest('[data-copy]')?.dataset.copy || '');
})();
