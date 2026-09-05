/* Wspolny rdzen wszystkich stron: formatowanie, budowanie DOM-u, tooltip,
   przelacznik kadencji i dwa wykresy uzywane na wiecej niz jednej stronie.
   Wstrzykiwany przez PageComposer, zeby nie zyl w kilku kopiach. */

const ROMAN = { 7: 'VII', 8: 'VIII', 9: 'IX', 10: 'X' };

// Znacznik, nie stala jezykowa: strona angielska dostaje tu 'en-GB', wiec liczby
// formatuje przegladarka, a nie osobna galaz kodu. Bez tego wersja angielska
// pokazywala "10 148 regulations (59,2%)" - polskie separatory w tekscie angielskim.
const LOCALE = '{{pl-PL}}';
const fmtInt = n => new Intl.NumberFormat(LOCALE).format(n);
const fmtPct = (x, d = 1) => (100 * x).toLocaleString(LOCALE, { minimumFractionDigits: d, maximumFractionDigits: d }) + '%';

const el = (tag, attrs = {}, ...kids) => {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') n.className = v;
    else if (k === 'text') n.textContent = v;
    else if (v !== null && v !== undefined) n.setAttribute(k, v);
  }
  kids.flat().forEach(c => n.append(c));
  return n;
};

const svgEl = (tag, attrs = {}) => {
  const n = document.createElementNS('http://www.w3.org/2000/svg', tag);
  for (const [k, v] of Object.entries(attrs)) if (v !== null && v !== undefined) n.setAttribute(k, v);
  return n;
};

/* ---------- tooltip ---------- */
const tip = document.getElementById('tip');

function showTip(evt, html) {
  tip.innerHTML = html;
  tip.style.opacity = '1';
  const pad = 14, r = tip.getBoundingClientRect();
  let x = evt.clientX + pad, y = evt.clientY + pad;
  if (x + r.width > innerWidth - 8) x = evt.clientX - r.width - pad;
  if (y + r.height > innerHeight - 8) y = evt.clientY - r.height - pad;
  tip.style.left = x + 'px';
  tip.style.top = y + 'px';
}

const hideTip = () => { tip.style.opacity = '0'; };

function bindTip(node, html) {
  node.addEventListener('mousemove', e => showTip(e, typeof html === 'function' ? html() : html));
  node.addEventListener('mouseleave', hideTip);
}

/* ---------- przelacznik kadencji ---------- */
// Strony bez podzialu na kadencje (vacatio legis) niosa dane plasko,
// wiec `report()` sprowadza sie wtedy do samego DATA.
const state = { term: DATA.domyslna_kadencja ?? null };
const report = () => (DATA.raporty ?? {})[state.term] ?? DATA;
const termInfo = n => (DATA.kadencje ?? []).find(k => k.numer === n) ?? null;

/**
 * Buduje przelacznik i wola onChange przy kazdej zmianie. Strony roznia sie tym,
 * co potrafia policzyc dla danej kadencji, wiec `disabled` decyduje o wyszarzeniu.
 */
function mountTermSwitch(hostId, onChange, disabled = () => false) {
  const host = document.getElementById(hostId);
  if (!host) return;

  DATA.kadencje.forEach(k => {
    const off = disabled(k);
    const b = el('button', {
      type: 'button',
      'aria-pressed': String(k.numer === state.term),
      disabled: off ? '' : null,
      title: off ? '{{Dla tej kadencji nie da się policzyć tej miary}}' : null,
    },
      el('span', { text: ROMAN[k.numer] ?? String(k.numer) }),
      el('span', { class: 'yr', text: `${k.od.slice(0, 4)}–${k.zamknieta ? k.do.slice(0, 4) : '{{dziś}}'}` }),
    );
    if (!off) {
      b.onclick = () => {
        if (state.term === k.numer) return;
        state.term = k.numer;
        host.querySelectorAll('button').forEach((x, i) =>
          x.setAttribute('aria-pressed', String(DATA.kadencje[i].numer === state.term)));
        hideTip();
        onChange();
      };
    }
    host.append(b);
  });
}

/** Pierwsza kadencja, dla ktorej miara jest policzalna - domyslny wybor strony. */
function firstAvailableTerm(ok) {
  const best = DATA.kadencje.filter(ok).map(k => k.numer);
  return best.length ? Math.max(...best) : DATA.domyslna_kadencja;
}

/* ---------- stopka ---------- */
function renderStamp(hostId, extra = []) {
  const host = document.getElementById(hostId);
  if (!host) return;
  const k = termInfo(state.term);
  const meta = report()?.meta ?? {};
  const parts = [];
  if (k) {
    parts.push(el('span', { text: `{{Kadencja}} ${ROMAN[state.term] ?? state.term} · ${k.od} – ${k.zamknieta ? k.do : '{{trwa}}'}` }));
    parts.push(el('span', { text: `{{Data odcięcia:}} ${meta.wygenerowano ?? '—'}${k.zamknieta ? ' {{(koniec kadencji)}}' : ''}` }));
  } else if (meta.wygenerowano) {
    parts.push(el('span', { text: `{{Stan na}} ${meta.wygenerowano}` }));
  }
  parts.push(el('span', {}, '{{Źródło:}} ', el('a', { href: 'https://api.sejm.gov.pl', text: meta.zrodlo ?? 'api.sejm.gov.pl' })));
  host.replaceChildren(...parts, ...extra.map(t => el('span', { text: t })));
}

/* ---------- wykres slupkowy poziomy ---------- */
function hbar(hostId, rows, opts) {
  const host = document.getElementById(hostId);
  if (!host) return;
  if (!rows.length) { host.replaceChildren(el('p', { class: 'sub', text: '{{Brak danych.}}' })); return; }

  const rowH = opts.rowH || 30, P = { t: 8, r: 62, b: 26, l: opts.labelWidth || 140 }, W = 900;
  const H = P.t + P.b + rows.length * rowH;
  const iw = W - P.l - P.r;
  const maxX = Math.max(...rows.map(r => r.value)) * 1.12 || 1;

  const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': opts.label });
  const grid = svgEl('g', { class: 'grid' });
  const axis = svgEl('g', { class: 'axis' });

  const ticks = opts.ticks || 5;
  for (let i = 0; i <= ticks; i++) {
    const v = (maxX * i) / ticks, x = P.l + (iw * v) / maxX;
    grid.append(svgEl('line', { x1: x, x2: x, y1: P.t, y2: P.t + rows.length * rowH }));
    const t = svgEl('text', { x, y: H - 8, 'text-anchor': 'middle' });
    t.textContent = opts.tick(v);
    axis.append(t);
  }
  svg.append(grid);

  rows.forEach((r, i) => {
    const y = P.t + i * rowH;
    const w = Math.max(2, (iw * r.value) / maxX);
    const name = svgEl('text', { x: P.l - 12, y: y + rowH / 2 + 4, 'text-anchor': 'end', class: 'serieslabel' });
    name.textContent = r.label;
    const val = svgEl('text', { x: P.l + w + 8, y: y + rowH / 2 + 4, class: 'serieslabel' });
    val.textContent = r.display;
    const hit = svgEl('rect', { x: P.l, y, width: iw, height: rowH, fill: 'transparent' });
    if (r.tip) {
      hit.addEventListener('mousemove', e => showTip(e, r.tip));
      hit.addEventListener('mouseleave', hideTip);
    }
    svg.append(svgEl('rect', { x: P.l, y: y + 7, width: w, height: rowH - 16, rx: 4, fill: 'var(--series-1)' }), name, val, hit);
  });
  svg.append(axis);
  host.replaceChildren(svg);
}

/* ---------- sortowalna tabela ---------- */
function sortableTable(cfg) {
  const head = document.getElementById(cfg.headId);
  const body = document.getElementById(cfg.bodyId);

  head.replaceChildren(el('th', { class: 'rank', text: '#' }));
  cfg.columns.forEach(c => {
    const th = el('th', { class: 'sortable', text: c.label });
    if (cfg.state.sort === c.key) th.setAttribute('aria-sort', cfg.state.dir === -1 ? 'descending' : 'ascending');
    th.onclick = () => {
      cfg.state.dir = cfg.state.sort === c.key ? -cfg.state.dir : -1;
      cfg.state.sort = c.key;
      cfg.rerender();
    };
    head.append(th);
  });

  const rows = cfg.rows.slice().sort((a, b) => {
    const x = a[cfg.state.sort], y = b[cfg.state.sort];
    if (typeof x === 'string' || typeof y === 'string') {
      return cfg.state.dir * String(x ?? '').localeCompare(String(y ?? ''), 'pl');
    }
    return cfg.state.dir * ((x ?? -1) - (y ?? -1));
  }).slice(0, cfg.limit ?? 60);

  body.replaceChildren(...rows.map((r, i) => {
    const tr = el('tr', {}, el('td', { class: 'rank', text: String(i + 1) }));
    cfg.columns.forEach(c => {
      // Etykieta kolumny jedzie z komorka. Ponizej 640 px tabela rozklada sie na
      // karty i nagłówek znika z pola widzenia - bez tego czytelnik widzi kolumne
      // liczb bez informacji, co one znacza. CSS bierze ja przez attr().
      if (c.render) {
        const td = c.render(r);
        td.setAttribute('data-etykieta', c.label);
        tr.append(td);
        return;
      }
      const v = r[c.key];
      let text = '—';
      if (v !== null && v !== undefined) {
        if (c.type === 'pct') text = fmtPct(v, c.digits ?? 0);
        else if (c.type === 'days') text = v + ' d';
        else if (c.type === 'int') text = fmtInt(v);
        else text = String(v);
      }
      tr.append(el('td', { class: c.type === 'text' ? '' : 'num', text, 'data-etykieta': c.label }));
    });
    if (cfg.tip) bindTip(tr, () => cfg.tip(r));
    return tr;
  }));

  return rows.length;
}

/* ---------- podstawa prawna ---------- */
/**
 * Przypis do przepisu, na ktorym stoi prog. Prog ustawowy jest najwazniejsza liczba
 * w projekcie, wiec tym bardziej musi prowadzic do tekstu aktu.
 */
function legalRef(key, label) {
  const src = (DATA.podstawy ?? {})[key];
  if (!src) return el('span', { text: label ?? '' });

  return el('a', {
    href: src.url,
    target: '_blank',
    rel: 'noopener',
    title: `${src.akt}, ${src.adres} — ${src.o_czym}`,
    text: label ?? src.przepis,
  });
}

/** Lista wszystkich podstaw prawnych - do stopki strony. */
function legalFooter() {
  const sources = Object.values(DATA.podstawy ?? {});
  if (sources.length === 0) return el('span');

  const row = el('div', { class: 'links', style: 'margin-top:8px' });
  const seen = new Set();
  sources.forEach(s => {
    if (seen.has(s.adres)) return;
    seen.add(s.adres);
    row.append(el('a', { class: 'src', href: s.url, target: '_blank', rel: 'noopener',
      text: `${s.akt.split(' ').slice(0, 3).join(' ')}… (${s.adres}) ↗` }));
  });

  return row;
}

/* ---------- nawigacja na waskim ekranie ---------- */
/**
 * Jedenascie pozycji nawigacji zajmowalo na telefonie 240 z 812 px, zanim
 * czytelnik zobaczyl cokolwiek. Ponizej 860 px chowamy je pod jawny przycisk.
 *
 * Widocznoscia rzadzi nasz wlasny selektor, a nie natywne <details>: tamtego
 * nie da sie nadpisac stylem, wiec przy zlym odczycie szerokosci nawigacja
 * znikala takze na biurku.
 *
 * Przycisk pojawia sie dopiero, gdy ten kod ustawi data-menu, wiec bez
 * JavaScriptu zostaje pelna lista - stan sprzed zmiany, a nie strona bez menu.
 */
(() => {
  const nav = document.querySelector('nav.pages');
  const toggle = nav?.querySelector('.menu-toggle');
  if (!nav || !toggle) return;

  const ustaw = (rozwiniete) => {
    nav.dataset.menu = rozwiniete ? 'rozwiniete' : 'zwiniete';
    toggle.setAttribute('aria-expanded', String(rozwiniete));
  };

  // Startujemy zwinieci i NIE pytamy o szerokosc. Powyzej 860 px regula chowajaca
  // liste nie obowiazuje, wiec atrybut jest tam bez znaczenia, a przycisku nie
  // widac. Odczyt szerokosci w chwili startu bywal falszywy - ramka albo panel
  // podgladu potrafily zwrocic szerokosc sprzed ulozenia strony - i nawigacja
  // ladowala zwinieta na biurku.
  ustaw(false);

  toggle.addEventListener('click', () => ustaw(nav.dataset.menu !== 'rozwiniete'));

  // Po przekroczeniu progu wracamy do stanu domyslnego dla nowej szerokosci.
  window.matchMedia('(max-width: 860px)').addEventListener('change', e => ustaw(!e.matches));
})();

/* ---------- etykiety wartosci nadawanych przez nas, nie przez API ---------- */
/**
 * Rodzaj pytania i kategoria glosowania sa naszymi nazwami wlasnymi, nie
 * cytatem ze zrodla, wiec podlegaja tlumaczeniu. Nazwy poslow, resortow
 * i tytuly aktow zostaja w oryginale — to nazwy wlasne.
 */
const KIND_LABELS = { 'interpelacja': '{{interpelacja}}', 'zapytanie': '{{zapytanie}}' };
const CATEGORY_LABELS = {
  'wnioski formalne': '{{wnioski formalne}}',
  'wybory i odwołania': '{{wybory i odwołania}}',
  'budżet': '{{budżet}}',
  'uchwały Senatu': '{{uchwały Senatu}}',
  'ratyfikacje': '{{ratyfikacje}}',
  'wotum': '{{wotum}}',
  'projekty rządowe': '{{projekty rządowe}}',
  'projekty poselskie i inne': '{{projekty poselskie i inne}}',
  'pozostałe': '{{pozostałe}}',
};
const CABINET_ACT_LABELS = {
  'desygnowanie premiera': '{{desygnowanie premiera}}',
  'powołanie premiera': '{{powołanie premiera}}',
  'dymisja rządu': '{{dymisja rządu}}',
  'zmiana w składzie': '{{zmiana w składzie}}',
  'powołanie w skład': '{{powołanie w skład}}',
  'odwołanie': '{{odwołanie}}',
  'inne': '{{inne}}',
};
const kindLabel = v => KIND_LABELS[v] ?? v;
const cabinetActLabel = v => CABINET_ACT_LABELS[v] ?? v;
const categoryLabel = v => CATEGORY_LABELS[v] ?? v;

/* ---------- odnosnik do profilu posla ---------- */
/**
 * Kolumna z nazwiskiem prowadzaca do profilu. Profile powstaja per kadencja,
 * wiec adres niesie i kadencje, i identyfikator.
 */
function memberColumn(label = '{{Poseł}}') {
  return {
    key: 'nazwa',
    label,
    type: 'text',
    render: m => el('td', {}, el('a', {
      class: 'profile',
      href: `posel/${m.kadencja ?? state.term}-${m.id}.html`,
      text: m.nazwa,
    })),
  };
}

/* ---------- odnosniki do zrodla ---------- */
function sourceLinks(q) {
  const row = el('div', { class: 'links' },
    el('a', { class: 'src', href: q.url, target: '_blank', rel: 'noopener', text: '{{treść pytania ↗}}' }),
  );
  if (q.url_odpowiedzi) {
    row.append(el('a', {
      class: 'src', href: q.url_odpowiedzi, target: '_blank', rel: 'noopener',
      text: q.tylko_skan ? '{{odpowiedź (PDF) ↗}}' : '{{treść odpowiedzi ↗}}',
    }));
  } else if (q.odpowiedziano) {
    row.append(el('span', { class: 'tag', text: '{{brak odnośnika do odpowiedzi w API}}' }));
  }
  if (q.prolongata) row.append(el('span', { class: 'tag', text: '{{prolongata}}' }));
  return row;
}
