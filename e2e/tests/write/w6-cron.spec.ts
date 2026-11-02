import { test, expect, resetFor, clearEffects } from '../support/write-fixtures';
import { diffTable, diffText, exec, gatewayGolden, mailGolden, tableRows, webhookGolden } from '../support/effects';
import { CronPage } from '../pages/cron.page';

/**
 * W6 — cron() (EventsController::cron, anonymous GET). Kept OUT of the crawl
 * allowlist on purpose: it mutates. Run only in this project's own harness
 * (calwt). Branches, in code order: complete ended approved events (pending
 * registrations cancelled + refunded + mailed), auto-approve pending events
 * (plain: 2-day window; honorarium: 3-day window) + event.published webhook,
 * cancellation reminders, start reminders. A second call must be a no-op.
 *
 * Fixture note (verified by the diff golden, not assumed): the cancel-reminder
 * fixture 1031 does NOT fire on a fresh-anchor cron — its cancellation cutoff
 * (start minus 6h) is still ~41h out, beyond the +24h reminder window — while
 * the start-reminder fixture 1032 does, and the already-past events 1007/1008
 * only flip their reminder/cancel flags (they have no registrations).
 */
test.beforeEach(async () => {
  await resetFor();
});

test('first cron: completions, auto-approvals, reminders, gateway, webhooks', async ({ session }) => {
  const page = await session('anonymous');
  // The completion branch refunds 1019’s regs, and Braintree _validateId
  // rejects the hyphenated fixture ids, so shape them to lib-valid ids that
  // preserve the fake gateway’s `sfs` signal (test setup only).
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsfs0002', 3012]);
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsettled0004', 3013]);
  const beforeEvents = await tableRows('events');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const cron = new CronPage(page);
  expect(await cron.run()).toBe(200);
  const afterEvents = await tableRows('events');
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const eventDiff = diffTable(beforeEvents, afterEvents);
  const regDiff = diffTable(beforeRegs, afterRegs);
  expect(eventDiff.changed['1019'].status).toEqual(['approved', 'completed']);
  expect(eventDiff.changed['1033'].status).toEqual(['pending', 'approved']);
  expect(eventDiff.changed['1034'].status).toEqual(['pending', 'approved']);
  expect(eventDiff.changed['1032'].reminder_notification).toEqual([0, 1]);
  expect(regDiff.changed['3012'].status).toEqual(['pending', 'cancelled']);
  expect(diffText({ events: eventDiff, registrations: regDiff })).toMatchSnapshot('w6-cron-diff.txt');
  expect(await mailGolden()).toMatchSnapshot('w6-cron-mail.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w6-cron-gateway.txt');
  expect(await webhookGolden()).toMatchSnapshot('w6-cron-webhook.txt');
});

test('second cron is a no-op', async ({ session }) => {
  const page = await session('anonymous');
  // The completion branch refunds 1019’s regs, and Braintree _validateId
  // rejects the hyphenated fixture ids, so shape them to lib-valid ids that
  // preserve the fake gateway’s `sfs` signal (test setup only).
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsfs0002', 3012]);
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsettled0004', 3013]);
  const cron = new CronPage(page);
  expect(await cron.run()).toBe(200);
  const afterFirstEvents = await tableRows('events');
  const afterFirstRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  await clearEffects();
  expect(await cron.run()).toBe(200);
  const afterSecondEvents = await tableRows('events');
  const afterSecondRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const eventDiff = diffTable(afterFirstEvents, afterSecondEvents);
  const regDiff = diffTable(afterFirstRegs, afterSecondRegs);
  expect(eventDiff).toEqual({ added: {}, removed: [], changed: {} });
  expect(regDiff).toEqual({ added: {}, removed: [], changed: {} });
  expect(diffText({ events: eventDiff, registrations: regDiff })).toMatchSnapshot('w6-cron-second-diff.txt');
  expect(await mailGolden()).toMatchSnapshot('w6-cron-second-mail.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w6-cron-second-gateway.txt');
  expect(await webhookGolden()).toMatchSnapshot('w6-cron-second-webhook.txt');
});
