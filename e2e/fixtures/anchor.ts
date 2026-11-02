/**
 * Fixture clock helpers. The app runs under libfaketime anchored at ANCHOR (see e2e/harness); every fixture
 * date is expressed relative to it so the dataset stays valid if the anchor is ever moved.
 *
 * The DB stores UTC; the UI renders America/Chicago. Fixture authors think in Chicago wall-clock time.
 */
export const ANCHOR = process.env.ANCHOR ?? '2031-01-15T16:00:00Z';
export const TZ = 'America/Chicago';

function chicagoOffsetMinutes(utcMs: number): number {
  const p = new Intl.DateTimeFormat('en-US', {
    timeZone: TZ, hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
  }).formatToParts(new Date(utcMs));
  const g = (t: string) => Number(p.find((x) => x.type === t)!.value);
  const asUtc = Date.UTC(g('year'), g('month') - 1, g('day'), g('hour'), g('minute'), g('second'));
  return Math.round((asUtc - Math.floor(utcMs / 1000) * 1000) / 60000);
}

/** Chicago wall-clock time -> UTC instant (handles DST; for the ambiguous hour picks the first). */
export function chicagoToUtc(y: number, m: number, d: number, hh: number, mm: number): Date {
  const guess = Date.UTC(y, m - 1, d, hh, mm);
  const off = chicagoOffsetMinutes(guess);
  let utc = guess - off * 60000;
  const off2 = chicagoOffsetMinutes(utc);
  if (off2 !== off) utc = guess - off2 * 60000;
  return new Date(utc);
}

/** Chicago calendar date (y, m, d) of the anchor instant. */
export function anchorLocalDate(): { y: number; m: number; d: number } {
  const p = new Intl.DateTimeFormat('en-US', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' })
    .formatToParts(new Date(ANCHOR));
  const g = (t: string) => Number(p.find((x) => x.type === t)!.value);
  return { y: g('year'), m: g('month'), d: g('day') };
}

/** `n` calendar days from the anchor's Chicago date, at Chicago wall time `HH:mm` -> UTC instant. */
export function daysFromAnchor(n: number, hhmm: string): Date {
  const { y, m, d } = anchorLocalDate();
  const base = new Date(Date.UTC(y, m - 1, d + n));
  const [hh, mm] = hhmm.split(':').map(Number);
  return chicagoToUtc(base.getUTCFullYear(), base.getUTCMonth() + 1, base.getUTCDate(), hh, mm);
}

/** Shift an instant by whole minutes (for 'anchor + 3 h' style values). */
export function plusMinutes(d: Date, minutes: number): Date {
  return new Date(d.getTime() + minutes * 60000);
}

/** Absolute Chicago wall time -> UTC instant, for fixed calendar dates such as DST transition days. */
export function onChicago(isoDate: string, hhmm: string): Date {
  const [y, m, d] = isoDate.split('-').map(Number);
  const [hh, mm] = hhmm.split(':').map(Number);
  return chicagoToUtc(y, m, d, hh, mm);
}

/** MySQL DATETIME literal (UTC). */
export function sql(d: Date): string {
  return d.toISOString().slice(0, 19).replace('T', ' ');
}

export const anchorInstant = (): Date => new Date(ANCHOR);
