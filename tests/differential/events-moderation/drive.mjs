// Differential driver for Slice D (events moderation).
// Runs inside a container on the harness backend network: BASE=http://app,
// MAIL=http://mail:8025, GATEWAY=http://app:3000, SINK=http://app:4010.
// Usage: node drive.mjs --case <name> [--user <username>] [--event <id>] [--reason <text>]
// Prints one JSON object: POST status/location, followed flash, MailHog subjects,
// fake-gateway log, webhook-sink log. DB rows are dumped separately (dump.php in app).
// It never edits the harness, fixtures or specs; it only drives HTTP + reads logs.
import { argv, env } from 'node:process';

const BASE = env.BASE || 'http://app';
const MAIL = env.MAIL || 'http://mail:8025';
const GATEWAY = env.GATEWAY || 'http://app:3000';
const SINK = env.SINK || 'http://app:4010';

const args = {};
for (let i = 2; i < argv.length; i += 2) args[argv[i].replace(/^--/, '')] = argv[i + 1];
const CASE = args.case;
const USER = args.user || null;
const EVENT = args.event ? Number(args.event) : null;
const REASON = args.reason || 'Fixture test rejection reason.';

function decode(s) {
  return String(s ?? '').replace(/&amp;/g, '&').replace(/&quot;/g, '"')
    .replace(/&#039;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');
}
function parseAttrs(tag) {
  const out = {};
  for (const m of tag.matchAll(/([A-Za-z_:][\w:.-]*)\s*=\s*"([^"]*)"|([A-Za-z_:][\w:.-]*)\s*=\s*'([^']*)'/g)) {
    out[(m[1] || m[3]).toLowerCase()] = decode(m[2] ?? m[4] ?? '');
  }
  return out;
}
function parseForms(html) {
  const forms = [];
  for (const m of html.matchAll(/<form\b([^>]*)>([\s\S]*?)<\/form>/gi)) {
    const attrs = parseAttrs(m[1]);
    const inputs = [];
    for (const im of m[2].matchAll(/<input\b([^>]*?)>/gi)) {
      const a = parseAttrs(im[1]);
      if (a.name) inputs.push({ name: a.name, value: a.value ?? '', type: a.type || 'text' });
    }
    forms.push({ action: attrs.action || '', method: attrs.method || 'post', name: attrs.name || '', inputs });
  }
  return forms;
}
function strip(html) {
  return String(html ?? '').replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();
}
function flashes(html) {
  const out = [];
  for (const m of html.matchAll(/<div[^>]*(?:class="[^"]*(?:alert|error|success)[^"]*"|role="alert")[^>]*>([\s\S]*?)<\/div>/gi)) {
    const t = strip(m[1]);
    if (t) out.push(t.slice(0, 300));
  }
  return out.slice(0, 4);
}

class Jar {
  constructor() { this.cookies = new Map(); }
  store(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const c of raw) {
      const [pair] = c.split(';');
      const eq = pair.indexOf('=');
      if (eq > 0) this.cookies.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
    }
  }
  header() {
    return [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
  }
  async req(path, opts = {}) {
    const url = path.startsWith('http') ? path : BASE + path;
    const headers = { ...(opts.headers || {}) };
    if (this.cookies.size) headers.Cookie = this.header();
    const res = await fetch(url, { ...opts, headers, redirect: 'manual' });
    this.store(res);
    return res;
  }
  async get(path, referer) {
    const res = await this.req(path, { headers: referer ? { Referer: referer } : {} });
    return { status: res.status, location: res.headers.get('location'), body: await res.text() };
  }
  async post(path, params, referer) {
    const body = new URLSearchParams();
    for (const [k, v] of params) body.append(k, v);
    const res = await this.req(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...(referer ? { Referer: referer } : {}) },
      body: body.toString(),
    });
    return { status: res.status, location: res.headers.get('location'), body: await res.text() };
  }
}

async function login(jar, username) {
  const page = await jar.get('/users/login');
  const forms = parseForms(page.body);
  if (!forms.length) return { loginGet: page.status, forms: 0 };
  const params = [];
  for (const inp of forms[0].inputs) params.push([inp.name, inp.value]);
  params.push(['username', username]);
  params.push(['password', 'password']);
  const res = await jar.post('/users/login', params, BASE + '/users/login');
  let after = null;
  if (res.location) {
    const g = await jar.get(res.location);
    after = { status: g.status, flashes: flashes(g.body) };
  }
  return { loginGet: page.status, loginPost: res.status, loginLocation: res.location, after };
}

async function fetchJson(url) {
  try {
    const r = await fetch(url);
    return await r.json();
  } catch (e) { return { error: String(e) }; }
}
async function logs() {
  const mail = await fetchJson(MAIL + '/api/v2/messages');
  const mails = (mail.items || []).map((it) => {
    const to = (it.To || []).map((t) => `${t.Mailbox}@${t.Domain}`);
    const subj = it.Content?.Headers?.Subject?.[0] ?? '';
    const body = strip(it.Content?.Body ?? '').slice(0, 160);
    return { to, subject: subj, body };
  });
  const gw = await fetchJson(GATEWAY + '/__log');
  const gateway = Array.isArray(gw) ? gw.map((x) => ({ method: x.method, path: x.path, body: JSON.stringify(x.body ?? '').slice(0, 400) })) : gw;
  const sink = await fetchJson(SINK + '/__log');
  const webhooks = Array.isArray(sink) ? sink.map((x) => ({ method: x.method, path: x.path, headers: x.headers, body: JSON.stringify(x.body ?? '').slice(0, 600) })) : sink;
  return { mails, gateway, webhooks };
}
async function resetLogs() {
  try { await fetch(GATEWAY + '/__reset', { method: 'POST' }); } catch {}
  try { await fetch(SINK + '/__reset', { method: 'POST' }); } catch {}
  try { await fetch(MAIL + '/api/v1/messages', { method: 'DELETE' }); } catch {}
}

function formParams(form, overrides) {
  // overrides: Map name->value, or function(name, value) -> value|undefined to drop
  const out = [];
  for (const inp of form.inputs) {
    if (overrides instanceof Map && overrides.has(inp.name)) { out.push([inp.name, overrides.get(inp.name)]); continue; }
    if (typeof overrides === 'function') {
      const v = overrides(inp.name, inp.value);
      if (v === undefined) continue;
      out.push([inp.name, v]); continue;
    }
    out.push([inp.name, inp.value]);
  }
  return out;
}

const jar = new Jar();
const result = { case: CASE, user: USER, event: EVENT };
try {
  await resetLogs();
  let loginInfo = null;
  if (USER) loginInfo = await login(jar, USER);
  result.login = loginInfo;

  if (CASE === 'processRejectionGET') {
    const p = await jar.get(`/events/processRejection/${EVENT}?redirect_url=/events/pending`);
    result.get = { status: p.status, hasHeading: p.body.includes('Process Event Rejection'), hasReasonField: /rejection_reason/.test(p.body), formActionReject: /\/events\/reject\//.test(p.body), flashes: flashes(p.body) };
  } else if (CASE.endsWith('GET')) {
    // Auth probe: GET only, no form tokens needed (FormProtection does not run on GET).
    const action = CASE.replace(/GET$/, '').replace(/([A-Z])/g, (c, i) => (i === 0 ? c.toLowerCase() : c));
    // Map case prefixes to controller actions
    const map = { approveMemberGET: 'approve', approveAnonGET: 'approve', rejectMemberGET: 'reject', cancelOtherGET: 'cancel', attendanceOtherGET: 'attendance', assignmentsOtherGET: 'assignments' };
    const act = map[CASE] || action;
    const p = await jar.get(`/events/${act}/${EVENT}`);
    let follow = null;
    if (p.location) { const g = await jar.get(p.location); follow = { status: g.status, flashes: flashes(g.body) }; }
    result.get = { status: p.status, location: p.location, follow };
  } else if (CASE === 'approve' || CASE === 'approveSeriesReapprove') {
    const pend = await jar.get('/events/pending');
    const forms = parseForms(pend.body);
    const f = forms.find((x) => x.action.includes(`/approve/${EVENT}`));
    if (!f) throw new Error('approve postLink form not found on /events/pending');
    const post = await jar.post(f.action, formParams(f), BASE + '/events/pending');
    let follow = null;
    if (post.location) { const g = await jar.get(post.location); follow = { status: g.status, flashes: flashes(g.body) }; }
    result.post = { status: post.status, location: post.location, follow, formAction: f.action };
  } else if (CASE === 'rejectHonorarium' || CASE === 'rejectNonHonorarium' || CASE === 'rejectSeries') {
    const qp = CASE === 'rejectHonorarium' ? '/events/pendingHonoraria' : '/events/pending';
    const page = await jar.get(`/events/processRejection/${EVENT}?redirect_url=${encodeURIComponent(qp)}`);
    const forms = parseForms(page.body);
    const f = forms.find((x) => x.action.includes(`/reject/${EVENT}`));
    if (!f) throw new Error('reject form not found on processRejection page; get status=' + page.status);
    const params = formParams(f, (name, value) => (/rejection_reason/.test(name) ? REASON : value));
    const post = await jar.post(f.action, params, BASE + `/events/processRejection/${EVENT}`);
    let follow = null;
    if (post.location) { const g = await jar.get(post.location); follow = { status: g.status, flashes: flashes(g.body) }; }
    result.post = { status: post.status, location: post.location, follow, formAction: f.action, getStatus: page.status };
  } else if (CASE === 'cancelFree' || CASE === 'cancelPaid' || CASE === 'cancelSeries') {
    // Cancel postLink lives on the edit page (Cake 3) but the Cake 5 edit page is slice C
    // (still broken in this copy); fall back to the approve postLink tokens, which carry
    // the same empty field set as any postLink form.
    let f = null; let src = '';
    const edit = await jar.get(`/events/edit/${EVENT}`);
    if (edit.status === 200) {
      f = parseForms(edit.body).find((x) => x.action.includes(`/cancel/${EVENT}`));
      src = 'edit';
    }
    if (!f) {
      const pend = await jar.get('/events/pending');
      f = parseForms(pend.body).find((x) => x.action.includes(`/approve/`));
      src = 'pending-approve-token-fallback';
      if (!f) throw new Error('no cancel/approve form found');
    }
    const action = f.action.includes(`/cancel/${EVENT}`) ? f.action : `/events/cancel/${EVENT}`;
    const post = await jar.post(action, formParams(f), BASE + '/events/edit/' + EVENT);
    let follow = null;
    if (post.location) { const g = await jar.get(post.location); follow = { status: g.status, flashes: flashes(g.body) }; }
    result.post = { status: post.status, location: post.location, follow, tokenSource: src, formAction: action, editStatus: edit.status };
  } else if (CASE === 'attendance') {
    const view = await jar.get(`/events/view/${EVENT}`);
    const forms = parseForms(view.body);
    const f = forms.find((x) => x.action.includes(`/attendance/${EVENT}`));
    if (!f) throw new Error('attendance form not found on view page; view status=' + view.status);
    const post = await jar.post(f.action, formParams(f), BASE + `/events/view/${EVENT}`);
    let follow = null;
    if (post.location) { const g = await jar.get(post.location); follow = { status: g.status, flashes: flashes(g.body) }; }
    result.post = { status: post.status, location: post.location, follow, formAction: f.action, viewStatus: view.status };
  } else if (CASE === 'assignments' || CASE === 'assignmentsGroupNotFound') {
    const view = await jar.get(`/events/view/${EVENT}`);
    const forms = parseForms(view.body);
    const f = forms.find((x) => x.action.includes(`/assignments/${EVENT}`));
    if (!f) throw new Error('assignments form not found on view page; view status=' + view.status);
    const post = await jar.post(f.action, formParams(f), BASE + `/events/view/${EVENT}`);
    let follow = null;
    if (post.location) { const g = await jar.get(post.location); follow = { status: g.status, flashes: flashes(g.body) }; }
    result.post = { status: post.status, location: post.location, follow, formAction: f.action, viewStatus: view.status };
  } else {
    throw new Error('unknown case ' + CASE);
  }
  result.logs = await logs();
} catch (e) {
  result.error = String(e && e.stack ? e.stack : e);
  try { result.logs = await logs(); } catch {}
}
console.log(JSON.stringify(result, null, 2));
