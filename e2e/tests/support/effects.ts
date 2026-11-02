import { fakesLog, listMail, type CapturedMail } from './io';
import mysql from 'mysql2/promise';

import { connectionConfig, withConnection } from './db';
import { ANCHOR } from '../../fixtures/anchor';

/**
 * Capture and normalise the SIDE EFFECTS of a write action so they can be stored as goldens:
 * mail (MailHog), gateway / webhook calls (the fakes log) and database rows.
 *
 * Every function that turns captured data into text is PURE (no I/O) and lives here so a rebuilt (Cake 5) run
 * normalises exactly like the baseline. Volatile values (ids that depend on the random edit key, "now" stamps,
 * tokens) are replaced by placeholders; everything else is kept so a real behaviour change shows up as a diff.
 */

// ------------------------------------------------------------------------------------------------ pure helpers

const ENTITIES: Record<string, string> = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'", '&#39;': "'", '&copy;': '(c)', '&nbsp;': ' ' };

/** HTML mail body -> readable text, keeping link targets. Markup differences that do not change the text are ignored. */
export function htmlToText(html: string): string {
  const text = html
    .replace(/\r\n/g, '\n')
    .replace(/<head>[\s\S]*?<\/head>/i, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<a\b[^>]*?href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/gi, (_m, href: string, label: string) => `${label.replace(/\s+/g, ' ').trim()} <${href}>`)
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|tr|li|h\d)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&[a-z#0-9]+;/gi, (e) => ENTITIES[e.toLowerCase()] ?? e);
  return text
    .split('\n')
    .map((l) => l.replace(/[ \t]+/g, ' ').trim())
    .filter((l, i, a) => l !== '' || (i > 0 && a[i - 1] !== ''))
    .join('\n')
    .trim();
}

/** Random per-request tokens that appear in mail bodies. */
export function normalizeTokens(s: string): string {
  return s.replace(/edit_key=[0-9a-f]{32}/g, 'edit_key=<edit-key>');
}

/**
 * Chicago wall time of the anchor instant ("10:00AM"). The app runs under a
 * TICKING faked clock (anchor + real elapsed time since the stack restart), so
 * a mail that stamps "now" — the event-submission mail's `Submission Time` line
 * — drifts by minutes between runs and can never match a fixed golden. Pin that
 * line's clock time to the anchor's: the date and the format are still pinned by
 * the golden, only the tick drift is removed. (Same idea as the DB stamp window
 * in normalizeCell below, but mail bodies are free text, so this is scoped to
 * the one line the app stamps with "now".)
 */
const ANCHOR_CHICAGO_TIME = new Intl.DateTimeFormat('en-US', {
  timeZone: 'America/Chicago',
  hour: 'numeric',
  minute: '2-digit',
  hour12: true,
})
  .format(new Date(ANCHOR))
  .replace(' ', '');

export function normalizeNowTimes(s: string): string {
  return s.replace(/(Submission Time: [A-Za-z]+ [A-Za-z]+ \d+, )\d{1,2}:\d\d[AP]M/g, `$1${ANCHOR_CHICAGO_TIME}`);
}

export function describeMail(m: CapturedMail): string {
  return [`From: ${m.from}`, `To: ${m.to.join(', ')}`, `Subject: ${m.subject}`, '', normalizeNowTimes(normalizeTokens(htmlToText(m.body)))].join('\n');
}

/** All captured mail in ARRIVAL order (MailHog lists newest first). */
export async function capturedMail(): Promise<CapturedMail[]> {
  return (await listMail()).reverse();
}

export function mailGoldenText(mails: CapturedMail[]): string {
  if (mails.length === 0) return '(no mail sent)\n';
  return mails.map((m, i) => `##### mail ${i + 1} of ${mails.length}\n${describeMail(m)}`).join('\n\n') + '\n';
}

export async function mailGolden(): Promise<string> {
  return mailGoldenText(await capturedMail());
}

function sortKeys(v: unknown): unknown {
  if (Array.isArray(v)) return v.map(sortKeys);
  if (v && typeof v === 'object') {
    return Object.fromEntries(Object.keys(v as object).sort().map((k) => [k, sortKeys((v as Record<string, unknown>)[k])]));
  }
  return v;
}

/** The fake logs gateway and webhook calls together; keep only the requested service. */
export function selectService(log: any[], service: 'braintree' | 'webhook'): any[] {
  return log.filter((e) => e.service === service);
}

/** Gateway calls in order, keys sorted (a different SDK version may order XML elements differently). */
export function gatewayGoldenText(log: any[]): string {
  const calls = selectService(log, 'braintree').map((e) => sortKeys({ method: e.method, path: e.path, body: e.body, result: e.result, unhandled: e.unhandled }));
  return JSON.stringify(calls, null, 2) + '\n';
}

/** Webhook deliveries in order. `occurred_at` is "now" and replaced. */
export function webhookGoldenText(log: any[]): string {
  const calls = selectService(log, 'webhook').map((e) => {
    const body = e.body && typeof e.body === 'object' ? { ...e.body, occurred_at: e.body.occurred_at ? '<now>' : e.body.occurred_at } : e.body;
    return sortKeys({ method: e.method, path: e.path, headers: e.headers, body });
  });
  return JSON.stringify(calls, null, 2) + '\n';
}

export async function gatewayGolden(): Promise<string> {
  return gatewayGoldenText(await fakesLog());
}

export async function webhookGolden(): Promise<string> {
  return webhookGoldenText(await fakesLog());
}

// ------------------------------------------------------------------------------------------------ database rows

export type Row = Record<string, string | number | null>;
export type Table = Record<string, Row>;

const NOW_MS = Date.parse(ANCHOR);
/** Columns that hold "when the app wrote this row". Anything within a few hours of the anchor is "now". */
const STAMP_COLUMNS = new Set(['created', 'modified', 'date_time']);
const NOW_WINDOW_MS = 3 * 3600 * 1000;

/** Pure: stamps written "now" by the app (the faked clock ticks) become `<now>`; other values pass through. */
export function normalizeCell(column: string, value: unknown): string | number | null {
  if (value === null || value === undefined) return null;
  if (STAMP_COLUMNS.has(column) && typeof value === 'string' && /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(value)) {
    const t = Date.parse(value.replace(' ', 'T') + 'Z');
    if (Math.abs(t - NOW_MS) <= NOW_WINDOW_MS) return '<now>';
  }
  if (typeof value === 'bigint') return Number(value);
  return value as string | number;
}

export function normalizeRow(row: Record<string, unknown>, drop: string[] = []): Row {
  const out: Row = {};
  for (const k of Object.keys(row).sort()) {
    if (drop.includes(k)) continue;
    out[k] = normalizeCell(k, row[k]);
  }
  return out;
}

/** Rows of a query keyed by `key`, normalised. Datetimes come back as strings (no timezone conversion). */
export async function rows(sql: string, params: unknown[] = [], key = 'id', drop: string[] = []): Promise<Table> {
  const conn = await mysql.createConnection({ ...connectionConfig(), dateStrings: true });
  let raw: Record<string, unknown>[];
  try {
    const [r] = await conn.query(sql, params);
    raw = r as unknown as Record<string, unknown>[];
  } finally {
    await conn.end();
  }
  const out: Table = {};
  for (const r of raw) out[String(r[key])] = normalizeRow(r, drop);
  return out;
}

export const tableRows = (table: string, where = '1=1', params: unknown[] = [], drop: string[] = []) =>
  rows(`SELECT * FROM \`${table}\` WHERE ${where} ORDER BY 1`, params, 'id', drop);

export interface TableDiff {
  added: Table;
  removed: string[];
  changed: Record<string, Record<string, [string | number | null, string | number | null]>>;
}

/** Pure: row-level difference between two keyed snapshots. */
export function diffTable(before: Table, after: Table): TableDiff {
  const diff: TableDiff = { added: {}, removed: [], changed: {} };
  for (const id of Object.keys(after)) {
    if (!(id in before)) {
      diff.added[id] = after[id];
      continue;
    }
    const cols: Record<string, [string | number | null, string | number | null]> = {};
    for (const c of Object.keys(after[id])) {
      if (before[id][c] !== after[id][c]) cols[c] = [before[id][c] ?? null, after[id][c]];
    }
    if (Object.keys(cols).length) diff.changed[id] = cols;
  }
  for (const id of Object.keys(before)) if (!(id in after)) diff.removed.push(id);
  return diff;
}

export function diffText(diff: Record<string, TableDiff>): string {
  const sorted = Object.fromEntries(Object.keys(diff).sort().map((t) => [t, sortKeys(diff[t])]));
  return JSON.stringify(sorted, null, 2) + '\n';
}

export function jsonText(v: unknown): string {
  return JSON.stringify(sortKeys(v), null, 2) + '\n';
}

/** Run one statement (test setup: shape fixture rows for a scenario; never used to fake an app action). */
export async function exec(sql: string, params: unknown[] = []): Promise<void> {
  await withConnection(async (c) => {
    await c.query(sql, params);
  });
}
