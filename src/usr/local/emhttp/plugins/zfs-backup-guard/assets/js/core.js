/* ZFS Backup Guard – Kern: Konfiguration, API, Formatierung, Cron, Retention, Rendering */
(function () {
  'use strict';

  const cfg = Object.assign({ api: '', csrf: '', theme: 'light', locale: 'de-DE' }, window.ZBG_CONFIG || {});

  const Z = (window.ZBG = {
    cfg,
    state: {
      view: 'overview',
      overview: null,
      datasets: null,
      containers: null,
      runs: {}, // jobId -> Live-Status
      loading: true,
      error: null,
    },
    views: {},
    actions: {},
    refreshers: {},
  });

  /* ---------- API ---------- */
  Z.api = async function (action, payload = {}) {
    if (cfg.csrf === 'dev' && typeof window.ZBG_MOCK === 'function') {
      return window.ZBG_MOCK(action, JSON.parse(JSON.stringify(payload)));
    }
    const body = new URLSearchParams({ action, payload: JSON.stringify(payload), csrf_token: cfg.csrf });
    const bounded = ['run.status', 'run.log', 'history.log'].includes(action);
    const controller = bounded ? new AbortController() : null;
    const timeout = controller ? setTimeout(() => controller.abort(), 20000) : null;
    try {
      let res;
      try {
        res = await fetch(cfg.api, { method: 'POST', body, credentials: 'same-origin', ...(controller ? { signal: controller.signal } : {}) });
      } catch (e) {
        throw new Error(controller?.signal.aborted ? 'Statusabfrage hat das Zeitlimit überschritten' : 'Server nicht erreichbar');
      }
      let json;
      try {
        json = await res.json();
      } catch (e) {
        throw new Error(controller?.signal.aborted ? 'Statusabfrage hat das Zeitlimit überschritten' : `Ungültige Antwort (${res.status})`);
      }
      if (!res.ok || !json.ok) throw new Error(json.error || `Fehler ${res.status}`);
      return json.data;
    } finally {
      clearTimeout(timeout);
    }
  };

  /* ---------- Hilfen ---------- */
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  Z.esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ESC[c]);
  Z.attr = Z.esc;
  Z.clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  Z.uid = () => Math.random().toString(36).slice(2, 9);
  Z.debounce = (fn, ms = 300) => {
    let t;
    return (...a) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...a), ms);
    };
  };
  Z.get = (obj, path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), obj);
  Z.set = (obj, path, val) => {
    const ks = path.split('.');
    let o = obj;
    ks.slice(0, -1).forEach((k) => (o = o[k] ??= {}));
    o[ks[ks.length - 1]] = val;
  };

  /* ---------- Formatierung ---------- */
  const nf1 = new Intl.NumberFormat(cfg.locale, { maximumFractionDigits: 1 });
  const nf0 = new Intl.NumberFormat(cfg.locale, { maximumFractionDigits: 0 });
  Z.fmt = {
    bytes(b) {
      if (b == null || isNaN(b)) return '–';
      const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
      let i = 0;
      let v = Number(b);
      while (v >= 1024 && i < u.length - 1) {
        v /= 1024;
        i++;
      }
      return `${(v < 10 && i > 0 ? nf1 : nf0).format(v)} ${u[i]}`;
    },
    num: (n) => nf0.format(n ?? 0),
    pct: (n) => `${nf0.format(n ?? 0)} %`,
    rate: (bps) => (bps ? `${Z.fmt.bytes(bps)}/s` : '–'),
    duration(sec) {
      if (sec == null) return '–';
      sec = Math.round(sec);
      if (sec < 60) return `${sec} s`;
      const m = Math.floor(sec / 60);
      if (m < 60) return `${m} min ${sec % 60 ? (sec % 60) + ' s' : ''}`.trim();
      const h = Math.floor(m / 60);
      return `${h} h ${m % 60} min`;
    },
    rel(ts, now = Date.now() / 1000) {
      if (!ts) return 'nie';
      const d = ts - now;
      const a = Math.abs(d);
      const rtf = new Intl.RelativeTimeFormat(cfg.locale, { numeric: 'auto', style: 'short' });
      if (a < 45) return d < 0 ? 'gerade eben' : 'gleich';
      if (a < 3600) return rtf.format(Math.round(d / 60), 'minute');
      if (a < 86400) return rtf.format(Math.round(d / 3600), 'hour');
      if (a < 86400 * 30) return rtf.format(Math.round(d / 86400), 'day');
      if (a < 86400 * 365) return rtf.format(Math.round(d / (86400 * 30)), 'month');
      return rtf.format(Math.round(d / (86400 * 365)), 'year');
    },
    date(ts, opts) {
      if (!ts) return '–';
      return new Date(ts * 1000).toLocaleString(cfg.locale, opts || { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    },
    dateFull: (ts) => (ts ? new Date(ts * 1000).toLocaleString(cfg.locale, { dateStyle: 'medium', timeStyle: 'short' }) : '–'),
    /** „heute 02:00“, „morgen 02:00“, sonst Datum */
    when(ts) {
      if (!ts) return '–';
      const d = new Date(ts * 1000);
      const today = new Date();
      const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
      const diff = Math.round((day(d) - day(today)) / 86400000);
      const time = d.toLocaleTimeString(cfg.locale, { hour: '2-digit', minute: '2-digit' });
      if (diff === 0) return `heute ${time}`;
      if (diff === 1) return `morgen ${time}`;
      if (diff === -1) return `gestern ${time}`;
      return Z.fmt.date(ts);
    },
  };

  /* ---------- Cron ---------- */
  const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
  function cronField(f, min, max) {
    const out = new Set();
    for (const part of f.split(',')) {
      const m = /^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/.exec(part);
      if (!m) throw new Error('ungültig');
      const step = m[2] ? +m[2] : 1;
      if (step < 1) throw new Error('Schritt');
      let a, b;
      if (m[1] === '*') [a, b] = [min, max];
      else if (m[1].includes('-')) [a, b] = m[1].split('-').map(Number);
      else [a, b] = [+m[1], m[2] ? max : +m[1]];
      if (a < min || b > max || a > b) throw new Error('Bereich');
      for (let v = a; v <= b; v += step) out.add(v);
    }
    return out;
  }
  Z.cron = {
    parse(expr) {
      const f = String(expr || '').trim().split(/\s+/);
      if (f.length !== 5) throw new Error('5 Felder erwartet');
      const s = f.map((x, i) => cronField(x, ...RANGES[i]));
      if (s[4].has(7)) {
        s[4].delete(7);
        s[4].add(0);
      }
      s.dom = f[2] !== '*';
      s.dow = f[4] !== '*';
      s.raw = f;
      return s;
    },
    valid(expr) {
      try {
        Z.cron.parse(expr);
        return true;
      } catch (e) {
        return false;
      }
    },
    /** nächste Ausführung nach ts (Sekunden) */
    next(expr, from = Date.now() / 1000) {
      let s;
      try {
        s = Z.cron.parse(expr);
      } catch (e) {
        return null;
      }
      const t = new Date((Math.floor(from / 60) + 1) * 60000);
      const limit = from * 1000 + 5 * 366 * 86400000;
      while (t.getTime() < limit) {
        if (!s[3].has(t.getMonth() + 1)) {
          t.setMonth(t.getMonth() + 1, 1);
          t.setHours(0, 0, 0, 0);
          continue;
        }
        const domOk = s[2].has(t.getDate());
        const dowOk = s[4].has(t.getDay());
        if (!(s.dom && s.dow ? domOk || dowOk : domOk && dowOk)) {
          t.setDate(t.getDate() + 1);
          t.setHours(0, 0, 0, 0);
          continue;
        }
        if (!s[1].has(t.getHours())) {
          t.setHours(t.getHours() + 1, 0, 0, 0);
          continue;
        }
        if (!s[0].has(t.getMinutes())) {
          t.setMinutes(t.getMinutes() + 1, 0, 0);
          continue;
        }
        return t.getTime() / 1000;
      }
      return null;
    },
    nextN(expr, n = 3, from = Date.now() / 1000) {
      const out = [];
      let t = from;
      for (let i = 0; i < n; i++) {
        t = Z.cron.next(expr, t);
        if (!t) break;
        out.push(t);
      }
      return out;
    },
    describe(expr) {
      let s;
      try {
        s = Z.cron.parse(expr);
      } catch (e) {
        return 'Ungültiger Ausdruck';
      }
      const [mi, ho, dom, mon, dow] = s.raw;
      const pad = (n) => String(n).padStart(2, '0');
      const DAYS = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
      const isNum = (x) => /^\d+$/.test(x);
      const timeStr = () => (isNum(mi) && isNum(ho) ? `${pad(ho)}:${pad(mi)}` : null);
      if (mon !== '*') return 'Benutzerdefiniert';
      if (isNum(mi) && ho === '*' && dom === '*' && dow === '*') return `Stündlich zur Minute ${mi}`;
      const step = /^\*\/(\d+)$/.exec(ho);
      if (isNum(mi) && step && dom === '*' && dow === '*') return `Alle ${step[1]} Stunden (zur Minute ${mi})`;
      if (/^\*\/(\d+)$/.test(mi) && ho === '*' && dom === '*' && dow === '*') return `Alle ${mi.slice(2)} Minuten`;
      const t = timeStr();
      if (t && dom === '*' && dow === '*') return `Täglich um ${t}`;
      if (t && dom === '*' && dow !== '*') {
        const days = [...s[4]].sort().map((d) => DAYS[d]);
        if (dow === '1-5') return `Werktags um ${t}`;
        return `${days.length === 1 ? 'Jeden ' : ''}${days.join(', ')} um ${t}`;
      }
      if (t && isNum(dom) && dow === '*') return `Monatlich am ${dom}. um ${t}`;
      return 'Benutzerdefiniert';
    },
  };

  /* ---------- Retention (Spiegel von lib/Retention.php, Semantik wie borg prune) ---------- */
  const isoWeek = (d) => {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const day = t.getUTCDay() || 7;
    t.setUTCDate(t.getUTCDate() + 4 - day);
    const y = t.getUTCFullYear();
    return `${y}-W${Math.ceil(((t - Date.UTC(y, 0, 1)) / 86400000 + 1) / 7)}`;
  };
  const PERIOD = {
    hourly: (d) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()} ${d.getHours()}`,
    daily: (d) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`,
    weekly: isoWeek,
    monthly: (d) => `${d.getFullYear()}-${d.getMonth()}`,
    yearly: (d) => `${d.getFullYear()}`,
  };
  Z.retention = {
    LEVELS: ['hourly', 'daily', 'weekly', 'monthly', 'yearly'],
    LABEL: { hourly: 'Stündlich', daily: 'Täglich', weekly: 'Wöchentlich', monthly: 'Monatlich', yearly: 'Jährlich' },
    plan(items, keep) {
      items = [...items].sort((a, b) => b.time - a.time);
      const reasons = new Map();
      for (const lvl of Z.retention.LEVELS) {
        const n = +keep[lvl] || 0;
        if (n <= 0) continue;
        let last = null;
        let count = 0;
        for (const it of items) {
          const k = PERIOD[lvl](new Date(it.time * 1000));
          if (k === last) continue;
          last = k;
          if (!reasons.has(it.name)) {
            reasons.set(it.name, [lvl]);
            if (++count >= n) break;
          }
        }
      }
      if (items[0]) reasons.set(items[0].name, [...(reasons.get(items[0].name) || []), 'newest']);
      return { keep: items.filter((i) => reasons.has(i.name)).map((i) => ({ ...i, reasons: reasons.get(i.name) })), reasons };
    },
    /** Simuliert die Aufbewahrung nach langem Betrieb mit dem gegebenen Zeitplan. */
    simulate(schedule, keep, days = 800) {
      const now = Date.now() / 1000;
      let t = now - days * 86400;
      const items = [];
      for (let i = 0; i < 20000; i++) {
        t = Z.cron.next(schedule, t);
        if (!t || t > now) break;
        items.push({ name: 's' + i, time: t });
      }
      const res = Z.retention.plan(items, keep);
      const oldest = res.keep.length ? res.keep[res.keep.length - 1].time : null;
      return { runs: items.length, kept: res.keep, oldest, now };
    },
  };

  /* ---------- Rendering & Events ---------- */
  Z.$ = (sel, root = document) => root.querySelector(sel);
  Z.$$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  Z.root = () => document.getElementById('zbg');

  Z.render = function () {
    const main = Z.$('#zbg-main');
    if (!main) return;
    const v = Z.views[Z.state.view] || Z.views.overview;
    // Einblend-Animation nur beim Wechsel der Ansicht, nicht bei jedem Neuzeichnen
    const key = Z.state.view + (Z.state.overview ? ':ready' : '');
    main.classList.toggle('enter', main.dataset.key !== key);
    main.dataset.key = key;
    main.innerHTML = v.render();
    v.mounted && v.mounted(main);
    Z.$$('.zbg-tab').forEach((t) => {
      const active = t.dataset.view === Z.state.view;
      t.classList.toggle('active', active);
      t.setAttribute('aria-selected', String(active));
    });
  };

  /** Nur einen Teilbereich neu zeichnen (data-region="name"). */
  Z.refresh = function (name) {
    const fn = Z.refreshers[name];
    if (!fn) return;
    Z.$$(`[data-region="${name}"]`).forEach((el) => {
      el.innerHTML = fn(el);
    });
  };

  Z.go = function (view, params) {
    Z.views[Z.state.view]?.leave?.();
    Z.state.view = view;
    Z.state.params = params || {};
    const hash = '#' + view + (params && params.q ? '/' + encodeURIComponent(params.q) : '');
    if (location.hash !== hash) history.replaceState(null, '', hash);
    Z.render();
    const top = Z.root();
    if (top && top.getBoundingClientRect().top < 0) top.scrollIntoView({ behavior: 'smooth' });
    Promise.resolve(Z.views[view]?.enter?.(params || {})).catch(Z.fail);
    Z.pollRuns?.();
    Z.scheduleOverviewRefresh?.();
  };

  Z.bindEvents = function (root) {
    root.addEventListener('click', (ev) => {
      const el = ev.target.closest('[data-act]');
      if (!el || !root.contains(el) || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
      const fn = Z.actions[el.dataset.act];
      if (fn) {
        // Checkboxen und Radios behalten ihr natives Umschalten
        if (el.tagName !== 'INPUT') ev.preventDefault();
        Promise.resolve().then(() => fn(el, ev)).catch(Z.fail);
      }
    });
    const onInput = (ev) => {
      const el = ev.target.closest('[data-bind]');
      if (el && Z.onBind) Promise.resolve().then(() => Z.onBind(el, ev)).catch(Z.fail);
      const act = ev.target.closest('[data-input]');
      if (act && Z.actions[act.dataset.input]) Promise.resolve().then(() => Z.actions[act.dataset.input](act, ev)).catch(Z.fail);
    };
    root.addEventListener('input', onInput); // feuert auch für Checkbox, Radio und Select
  };
})();
