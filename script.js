// ==========================================================================
// VetConnect Kenya — shared frontend (API client, session, realtime, forms)
// ==========================================================================
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ---------------- Session + API ---------------- */
const Session = {
  get token() { return localStorage.getItem('vc_token'); },
  get user() { try { return JSON.parse(localStorage.getItem('vc_user')); } catch { return null; } },
  set(token, user) { localStorage.setItem('vc_token', token); localStorage.setItem('vc_user', JSON.stringify(user)); },
  clear() { localStorage.removeItem('vc_token'); localStorage.removeItem('vc_user'); },
  home(role) { return { farmer: 'dashboard-farmer.html', vet: 'dashboard-vet.html', admin: 'dashboard-admin.html' }[role]; }
};

async function api(path, method = 'GET', body) {
  const res = await fetch('api/index.php' + path, {
    method,
    headers: { 'Content-Type': 'application/json', ...(Session.token ? { 'X-Auth-Token': Session.token } : {}) },
    body: body ? JSON.stringify(body) : undefined
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && Session.token) { Session.clear(); location.href = 'login.html'; }
  if (!res.ok) throw new Error(data.error || 'Something went wrong');
  return data;
}

function toast(msg, type = 'ok') {
  let box = $('.toasts');
  if (!box) { box = document.createElement('div'); box.className = 'toasts'; document.body.appendChild(box); }
  const t = document.createElement('div');
  t.className = 'toast ' + type; t.textContent = msg; box.appendChild(t);
  setTimeout(() => t.remove(), 4500);
}

function timeAgo(sql) {
  const d = new Date(sql.replace(' ', 'T') + 'Z'), s = Math.max(1, (Date.now() - d) / 1000);
  if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago';
  if (s < 86400) return Math.floor(s / 3600) + ' h ago'; return d.toLocaleDateString();
}

/* ---------------- Location ---------------- */
function getPosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('Location is not supported on this device'));
    navigator.geolocation.getCurrentPosition(p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
      () => reject(new Error('Could not get your location — allow location access, or pick your county')), { enableHighAccuracy: true, timeout: 12000 });
  });
}

// Wires a "Use my location" button to hidden lat/lng inputs inside a form
function initLocationButton(form) {
  const btn = $('[data-get-location]', form); if (!btn) return;
  btn.addEventListener('click', async () => {
    btn.disabled = true; btn.textContent = 'Finding you…';
    try {
      const p = await getPosition();
      form.querySelector('[name="lat"]').value = p.lat; form.querySelector('[name="lng"]').value = p.lng;
      btn.textContent = '✓ Location saved (' + p.lat.toFixed(3) + ', ' + p.lng.toFixed(3) + ')';
    } catch (e) { btn.textContent = '📍 Use my location'; toast(e.message, 'err'); }
    btn.disabled = false;
  });
}

/* ---------------- Realtime (polls the server every 3 s for new events) ---------------- */
function connectRealtime(handlers = {}) {
  let last = null, stopped = false;
  async function tick() {
    if (!document.hidden) {
      try {
        const r = await api('/events?since=' + (last === null ? -1 : last));
        last = r.last;
        r.events.forEach(e => handlers[e.type] && handlers[e.type](e.data));
      } catch (e) { /* offline or server restarting — try again next tick */ }
    }
    if (!stopped) setTimeout(tick, 3000);
  }
  tick();
  return { close() { stopped = true; } };
}

/* ---------------- Dashboard guard ---------------- */
function requireRole(role) {
  const u = Session.user;
  if (!Session.token || !u || u.role !== role) { location.href = 'login.html'; return null; }
  $$('[data-user-name]').forEach(el => el.textContent = u.name);
  $$('[data-logout]').forEach(el => el.addEventListener('click', e => { e.preventDefault(); Session.clear(); location.href = 'index.html'; }));
  return u;
}

// Sidebar tabs: <a data-view="x"> shows <section data-panel="x">
function initTabs(onShow) {
  const links = $$('[data-view]'), panels = $$('[data-panel]');
  const show = v => {
    links.forEach(l => l.classList.toggle('active', l.dataset.view === v));
    panels.forEach(p => p.hidden = p.dataset.panel !== v);
    onShow && onShow(v);
  };
  links.forEach(l => l.addEventListener('click', e => { e.preventDefault(); show(l.dataset.view); }));
  show(links[0].dataset.view);
  return show;
}

const STATUS = {
  pending: ['new', 'Awaiting vet'], accepted: ['progress', 'Accepted'], in_progress: ['progress', 'In progress'],
  completed: ['done', 'Completed'], declined: ['new', 'Declined'], cancelled: ['cancelled', 'Cancelled']
};
const statusPill = s => `<span class="pill ${STATUS[s][0]}">${STATUS[s][1]}</span>`;
const urgencyPill = u => u === 'routine' ? '' : `<span class="pill ${u === 'emergency' ? 'emergency' : 'urgent'}">${u}</span>`;

/* ---------------- Chat widget (requirement 3) ---------------- */
const Chat = {
  reqId: null,
  async open(req, me) {
    this.reqId = req.id; this.me = me;
    const box = $('#chat-modal'); box.classList.add('open');
    $('#chat-title').textContent = `${req.species} — ${req.problem.slice(0, 40)}`;
    $('#chat-sub').textContent = me.role === 'vet' ? `Farmer: ${req.farmer_name}${req.farmer_phone ? ' · ' + req.farmer_phone : ''}` : (req.vet_name ? `Vet: ${req.vet_name}${req.vet_phone ? ' · ' + req.vet_phone : ''}` : 'Waiting for a vet to accept…');
    $('#chat-advice-wrap').hidden = me.role !== 'vet';
    $('#chat-appt-wrap').hidden = me.role !== 'vet';
    const msgs = await api(`/requests/${req.id}/messages`);
    $('#chat-log').innerHTML = ''; msgs.forEach(m => this.add(m));
  },
  add(m) {
    const log = $('#chat-log'), mine = m.sender_id === this.me.id;
    const el = document.createElement('div');
    el.className = `msg ${mine ? 'mine' : 'theirs'} ${m.kind}`;
    const label = m.kind === 'advice' ? '💊 Treatment advice' : m.kind === 'appointment' ? '📅 Appointment' : '';
    el.innerHTML = `${label ? `<div class="msg-kind">${label}</div>` : ''}<div>${esc(m.body)}</div><small>${esc(m.sender_name)} · ${timeAgo(m.created_at)}</small>`;
    log.appendChild(el); log.scrollTop = log.scrollHeight;
  },
  onIncoming(m) { if (m.request_id === this.reqId && $('#chat-modal').classList.contains('open')) { this.add(m); } else toast('💬 New message from ' + m.sender_name); },
  init() {
    const box = $('#chat-modal'); if (!box) return;
    $('[data-chat-close]').addEventListener('click', () => { box.classList.remove('open'); this.reqId = null; });
    $('#chat-form').addEventListener('submit', async e => {
      e.preventDefault();
      const input = $('#chat-input'), body = input.value.trim(); if (!body) return;
      const kind = $('#chat-advice') && $('#chat-advice').checked ? 'advice' : 'message';
      input.value = '';
      try { const m = await api(`/requests/${this.reqId}/messages`, 'POST', { body, kind }); this.add(m); } catch (err) { toast(err.message, 'err'); }
    });
  }
};

/* ---------------- Mobile nav ---------------- */
function initNavToggle() {
  const toggle = $('.nav-toggle'), nav = $('.main-nav');
  if (!toggle || !nav) return;
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('nav-open');
    nav.style.cssText = open ? 'display:flex;flex-direction:column;position:absolute;top:100%;left:0;right:0;background:var(--sand);padding:1rem 1.5rem;border-bottom:1px solid var(--line);' : '';
  });
}

/* ---------------- Farm Notes carousel ---------------- */
function initNotesCarousel() {
  const root = $('[data-notes]'); if (!root) return;
  const slides = $$('.note-slide', root), dotsWrap = $('.notes-dots', root);
  let index = 0, timer = null;
  slides.forEach((_, i) => { const d = document.createElement('button'); d.setAttribute('aria-label', `Show tip ${i + 1}`); d.addEventListener('click', () => { show(i); restart(); }); dotsWrap.appendChild(d); });
  const dots = Array.from(dotsWrap.children);
  function show(i) { index = (i + slides.length) % slides.length; slides.forEach((s, n) => s.classList.toggle('active', n === index)); dots.forEach((d, n) => d.classList.toggle('active', n === index)); }
  function restart() { clearInterval(timer); timer = setInterval(() => show(index + 1), 6000); }
  $('[data-notes-next]', root).addEventListener('click', () => { show(index + 1); restart(); });
  $('[data-notes-prev]', root).addEventListener('click', () => { show(index - 1); restart(); });
  root.addEventListener('mouseenter', () => clearInterval(timer)); root.addEventListener('mouseleave', restart);
  show(0); restart();
}

/* ---------------- Register + login forms ---------------- */
function initRegisterForm(formSelector, role) {
  const form = $(formSelector); if (!form) return;
  initLocationButton(form);
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('button[type=submit]', form); btn.disabled = true;
    const data = Object.fromEntries(new FormData(form)); data.role = role;
    data.name = data.fullName || data.name;       // form field is called fullName
    data.county = data.location || data.county;   // county dropdown is called location
    try {
      const r = await api('/auth/register', 'POST', data);
      Session.set(r.token, r.user); location.href = Session.home(role) + '?joined=1';
    } catch (err) { toast(err.message, 'err'); btn.disabled = false; }
  });
}

function initLoginForm() {
  const form = $('#login-form'); if (!form) return;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('button[type=submit]', form); btn.disabled = true;
    try {
      const r = await api('/auth/login', 'POST', Object.fromEntries(new FormData(form)));
      Session.set(r.token, r.user); location.href = Session.home(r.user.role);
    } catch (err) { toast(err.message, 'err'); btn.disabled = false; }
  });
}

function initModals() {
  $$('.modal-backdrop').forEach(b => {
    $$('[data-close-modal]', b).forEach(x => x.addEventListener('click', () => b.classList.remove('open')));
    b.addEventListener('click', e => { if (e.target === b) b.classList.remove('open'); });
  });
  $$('[data-open]').forEach(btn => btn.addEventListener('click', () => $('#' + btn.dataset.open).classList.add('open')));
  if (new URLSearchParams(location.search).get('joined')) { const b = $('[data-welcome-banner]'); if (b) b.style.display = 'flex'; }
}

document.addEventListener('DOMContentLoaded', () => { initNavToggle(); initNotesCarousel(); initModals(); Chat.init(); });
