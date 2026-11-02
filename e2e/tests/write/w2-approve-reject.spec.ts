import { test, expect, resetFor } from '../support/write-fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { diffTable, diffText, exec, gatewayGolden, mailGolden, tableRows, webhookGolden } from '../support/effects';
import { PendingPage } from '../pages/pending.page';
import { RejectionPage } from '../pages/rejection.page';

/**
 * W2 — moderation (EventsController::approve / reject / processRejection).
 * Approve is a postLink on the pending list; reject goes through the
 * process-rejection form. Approve also publishes the event.published webhook;
 * reject does not. Approving a series parent approves its continued dates too.
 * Honorarium events may only be approved by Honorarium Admins.
 */
test.beforeEach(async () => {
  await resetFor();
});

test('admin approves a pending event: status, mail, webhook', async ({ session }) => {
  const page = await session('admin');
  const beforeEvents = await tableRows('events');
  const pending = new PendingPage(page);
  await pending.goto();
  await expect(pending.heading).toBeVisible();
  await pending.approve(CATALOG.events.pendingFresh.name);
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w2-approve-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w2-approve-mail.txt');
  expect(await webhookGolden()).toMatchSnapshot('w2-approve-webhook.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w2-approve-gateway.txt');
});

test('admin approves a series parent: continued dates approve too', async ({ session }) => {
  // The fixture series is approved; shape it pending first (test setup only).
  await exec('UPDATE `events` SET `status` = ? WHERE `id` IN (?,?,?)', ['pending', 1012, 1013, 1014]);
  const page = await session('admin');
  const beforeEvents = await tableRows('events');
  const pending = new PendingPage(page);
  await pending.goto();
  await pending.approve(CATALOG.events.seriesParent.name);
  const afterEvents = await tableRows('events');
  const diff = diffText({ events: diffTable(beforeEvents, afterEvents) });
  expect(diff).toMatchSnapshot('w2-approve-series-events.txt');
  const changed = diffTable(beforeEvents, afterEvents).changed;
  expect(changed['1012'].status).toEqual(['pending', 'approved']);
  expect(changed['1013'].status).toEqual(['pending', 'approved']);
  expect(changed['1014'].status).toEqual(['pending', 'approved']);
  expect(await webhookGolden()).toMatchSnapshot('w2-approve-series-webhook.txt');
});

test('admin rejects a pending event with a reason: status, by, mail, no webhook', async ({ session }) => {
  const page = await session('admin');
  const beforeEvents = await tableRows('events');
  const rejection = new RejectionPage(page);
  await rejection.goto(CATALOG.events.pendingFresh.id);
  await expect(rejection.heading).toBeVisible();
  await rejection.rejectWithReason('Write suite rejection reason.');
  const afterEvents = await tableRows('events');
  const diff = diffText({ events: diffTable(beforeEvents, afterEvents) });
  expect(diff).toMatchSnapshot('w2-reject-events.txt');
  const changed = diffTable(beforeEvents, afterEvents).changed;
  expect(changed[String(CATALOG.events.pendingFresh.id)].status).toEqual(['pending', 'rejected']);
  expect(changed[String(CATALOG.events.pendingFresh.id)].rejected_by).toEqual([null, 'user2']);
  expect(changed[String(CATALOG.events.pendingFresh.id)].rejection_reason).toEqual([null, 'Write suite rejection reason.']);
  expect(await mailGolden()).toMatchSnapshot('w2-reject-mail.txt');
  expect(await webhookGolden()).toMatchSnapshot('w2-reject-webhook.txt');
});

test('honorarium admin approves a pending honorarium event; calendar admin cannot', async ({ session }) => {
  // Positive path: Honorarium Admin approves the honorarium-requested event.
  const honorPage = await session('honorariumAdmin');
  const beforeEvents = await tableRows('events');
  const pending = new PendingPage(honorPage);
  await pending.gotoHonorariaPending();
  await expect(pending.honorariaHeading).toBeVisible();
  await pending.approve(CATALOG.events.honorariumRequested.name);
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w2-approve-honorarium-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w2-approve-honorarium-mail.txt');
  expect(await webhookGolden()).toMatchSnapshot('w2-approve-honorarium-webhook.txt');
});

test('calendar admin cannot reach the honorarium approval list', async ({ session }) => {
  // The pending list itself filters Honoraria.id IS NULL, so a calendar admin never
  // sees an approve button for an honorarium event; the honoraria-pending list is
  // Honorarium Admins only. Refusal happens before any approve can be attempted.
  const page = await session('calendarAdmin');
  const beforeEvents = await tableRows('events');
  const pending = new PendingPage(page);
  await pending.gotoHonorariaPending();
  await expect(pending.honorariaHeading).toBeHidden();
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w2-approve-denied-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w2-approve-denied-mail.txt');
  expect(await webhookGolden()).toMatchSnapshot('w2-approve-denied-webhook.txt');
});
