import { chmodSync, existsSync, mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { FIXTURE_FILES } from '../../fixtures/synthetic/catalog';

/** Uploaded-file volume, mounted at /files in the runner and at webroot/files in the app. */
const FILES_ROOT = process.env.FILES_DIR ?? '/files';

export function resetFiles(): void {
  mkdirSync(FILES_ROOT, { recursive: true });
  for (const entry of readdirSync(FILES_ROOT)) rmSync(join(FILES_ROOT, entry), { recursive: true, force: true });
  for (const f of FIXTURE_FILES) {
    const dir = join(FILES_ROOT, String(f.eventId));
    mkdirSync(dir, { recursive: true });
    writeFileSync(join(dir, f.name), Buffer.from(f.base64, 'base64'));
  }
  // www-data (in the app container) must be able to write uploads into a root-created volume.
  chmodRecursive(FILES_ROOT);
}

function chmodRecursive(p: string): void {
  chmodSync(p, 0o777);
  if (!existsSync(p)) return;
  for (const e of readdirSync(p, { withFileTypes: true })) {
    const child = join(p, e.name);
    if (e.isDirectory()) chmodRecursive(child);
    else chmodSync(child, 0o666);
  }
}

// ---- MailHog
const MAIL = process.env.MAIL_API ?? 'http://mail:8025';

export interface CapturedMail {
  from: string;
  to: string[];
  subject: string;
  body: string;
}

export async function clearMail(): Promise<void> {
  await fetch(`${MAIL}/api/v1/messages`, { method: 'DELETE' });
}

export async function listMail(): Promise<CapturedMail[]> {
  const res = await fetch(`${MAIL}/api/v2/messages?limit=500`);
  const json = (await res.json()) as { items: any[] };
  return json.items.map((m) => ({
    from: `${m.From.Mailbox}@${m.From.Domain}`,
    to: (m.To as any[]).map((t) => `${t.Mailbox}@${t.Domain}`),
    subject: m.Content.Headers.Subject?.[0] ?? '',
    body: m.Content.Body as string,
  }));
}

// ---- fake Braintree gateway + webhook sink (share the app's network namespace)
const FAKES = process.env.FAKES_API ?? 'http://app:3000';

export async function resetFakes(): Promise<void> {
  await fetch(`${FAKES}/__reset`, { method: 'POST' });
}

export async function fakesLog(): Promise<any[]> {
  const res = await fetch(`${FAKES}/__log`);
  return (await res.json()) as any[];
}
