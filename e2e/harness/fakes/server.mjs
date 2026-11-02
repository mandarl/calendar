// Based on the library kit's proposed fake (decline modes) plus recorded X-DMS-Webhook-* request headers on the sink.
// Adds failure modes so the app's `$result->success === false` branches can be exercised (RegistrationsController ~L141-L146):
//   nonce `fake-declined-nonce` => 422 api-error-response, message "Do Not Honor", transaction status processor_declined
//   nonce `fake-invalid-nonce`  => 422 api-error-response, message "Unknown or expired payment_method_nonce.", no transaction
// Sink entries now also record the x-dms-webhook-event and x-dms-webhook-secret headers (additive). Search for PROPOSED.
// Test doubles for the calendar baseline harness. No dependencies; run with plain Node.
//   :3000  fake Braintree gateway (the SDK's `development` environment talks to localhost:$GATEWAY_PORT)
//   :4010  webhook sink (WEBHOOK_TARGETS points here)
// Both also expose GET /__log and POST /__reset so tests can assert on and clear recorded calls.
//
// Transaction ids: ids created by this fake are `txn-0001`, `txn-0002`, ... Fixtures may reference ids the
// fake never created; their state is derived from the id so no seeding call is needed:
//   ids containing `sfs` => submitted_for_settlement (cancel => void); everything else => settled (cancel => refund).
//   Substring, not `-sfs-`: Braintree_Transaction::find() rejects any id outside /^[0-9a-z]+$/,
//   so a hyphenated `-sfs-` id can never reach this derivation (it 500s in _validateId first).
import http from 'node:http';

const GATEWAY_PORT = Number(process.env.GATEWAY_PORT || 3000);
const SINK_PORT = Number(process.env.SINK_PORT || 4010);

let log = [];
let txns = new Map();
let counter = 0;

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
  });
}

// Minimal XML -> object: enough to record what the SDK sent (amount, nonce, custom fields).
function parseXml(xml) {
  const root = {};
  const stack = [root];
  const re = /<\/?([A-Za-z0-9_-]+)[^>]*?(\/)?>|([^<]+)/g;
  let m;
  while ((m = re.exec(xml))) {
    if (m[3] !== undefined) {
      const t = m[3].trim();
      if (t) stack[stack.length - 1].__text = t;
    } else if (m[0].startsWith('</')) {
      const done = stack.pop();
      const parent = stack[stack.length - 1];
      const tag = m[1];
      const val = Object.keys(done).length === 1 && '__text' in done ? done.__text : done;
      parent[tag] = val;
    } else if (!m[2] && !m[0].startsWith('<?')) {
      stack.push({});
    }
  }
  return root;
}

function txnXml(t) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<transaction>
  <id>${esc(t.id)}</id>
  <status>${esc(t.status)}</status>
  <type>${esc(t.type || 'sale')}</type>
  <currency-iso-code>USD</currency-iso-code>
  <amount>${esc(t.amount || '0.00')}</amount>
  <merchant-account-id>fake-merchant</merchant-account-id>
</transaction>`;
}

function stateFor(id) {
  if (txns.has(id)) return txns.get(id);
  return { id, status: /sfs/.test(id) ? 'submitted_for_settlement' : 'settled', type: 'sale', amount: '0.00' };
}

function xml(res, code, body) {
  res.writeHead(code, { 'Content-Type': 'application/xml; charset=utf-8' });
  res.end(body);
}

const gateway = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const path = url.pathname;
  if (path === '/__log') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify(log));
  }
  if (path === '/__reset' && req.method === 'POST') {
    log = [];
    txns = new Map();
    counter = 0;
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end('{"ok":true}');
  }

  const body = await readBody(req);
  const parsed = body ? parseXml(body) : {};
  const rec = { service: 'braintree', method: req.method, path, body: parsed };

  let m;
  if (req.method === 'POST' && /\/client_token$/.test(path)) {
    log.push(rec);
    const value = Buffer.from(JSON.stringify({ version: 2, fake: true })).toString('base64');
    return xml(res, 201, `<?xml version="1.0" encoding="UTF-8"?><client-token><value>${value}</value></client-token>`);
  }
  if (req.method === 'POST' && /\/transactions$/.test(path)) {
    const tx = parsed.transaction || {};
    // PROPOSED: failure modes
    if (tx['payment-method-nonce'] === 'fake-declined-nonce') {
      const t = { id: `txn-${String(++counter).padStart(4, '0')}`, status: 'processor_declined', type: 'sale', amount: tx.amount || '0.00' };
      txns.set(t.id, t);
      rec.result = t.id;
      log.push(rec);
      return xml(res, 422, `<?xml version="1.0" encoding="UTF-8"?><api-error-response><errors><errors type="array"/></errors><message>Do Not Honor</message><transaction><id>${t.id}</id><status>processor_declined</status><type>sale</type><amount>${esc(t.amount)}</amount></transaction></api-error-response>`);
    }
    if (tx['payment-method-nonce'] === 'fake-invalid-nonce') {
      rec.result = 'invalid-nonce';
      log.push(rec);
      return xml(res, 422, `<?xml version="1.0" encoding="UTF-8"?><api-error-response><errors><errors type="array"><transaction type="array"/></errors></errors><message>Unknown or expired payment_method_nonce.</message></api-error-response>`);
    }
    const t = { id: `txn-${String(++counter).padStart(4, '0')}`, status: 'submitted_for_settlement', type: 'sale', amount: tx.amount || '0.00' };
    txns.set(t.id, t);
    rec.result = t.id;
    log.push(rec);
    return xml(res, 201, txnXml(t));
  }
  if ((m = path.match(/\/transactions\/([^/]+)\/void$/)) && req.method === 'PUT') {
    const t = { ...stateFor(m[1]), status: 'voided' };
    txns.set(t.id, t);
    log.push(rec);
    return xml(res, 200, txnXml(t));
  }
  if ((m = path.match(/\/transactions\/([^/]+)\/refund$/)) && req.method === 'POST') {
    const orig = stateFor(m[1]);
    const t = { id: `txn-${String(++counter).padStart(4, '0')}`, status: 'submitted_for_settlement', type: 'credit', amount: orig.amount };
    txns.set(t.id, t);
    rec.result = t.id;
    log.push(rec);
    return xml(res, 201, txnXml(t));
  }
  if ((m = path.match(/\/transactions\/([^/]+)$/)) && req.method === 'GET') {
    log.push(rec);
    return xml(res, 200, txnXml(stateFor(m[1])));
  }

  rec.unhandled = true;
  log.push(rec);
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('fake gateway: unhandled ' + req.method + ' ' + path);
});

const sink = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname === '/__log') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify(log));
  }
  if (url.pathname === '/__reset' && req.method === 'POST') {
    log = [];
    txns = new Map();
    counter = 0;
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end('{"ok":true}');
  }
  const body = await readBody(req);
  let json = null;
  try { json = JSON.parse(body); } catch { /* keep raw */ }
  log.push({ service: 'webhook', method: req.method, path: url.pathname, headers: { 'content-type': req.headers['content-type'], 'x-dms-webhook-event': req.headers['x-dms-webhook-event'], 'x-dms-webhook-secret': req.headers['x-dms-webhook-secret'] }, body: json ?? body });
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end('{"ok":true}');
});

gateway.listen(GATEWAY_PORT, '0.0.0.0', () => console.log(`fake braintree gateway on :${GATEWAY_PORT}`));
sink.listen(SINK_PORT, '0.0.0.0', () => console.log(`webhook sink on :${SINK_PORT}`));
