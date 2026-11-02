import { test, expect, resetFor } from '../support/write-fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { diffTable, diffText, exec, gatewayGolden, mailGolden, tableRows } from '../support/effects';
import { EventFormPage } from '../pages/event-form.page';
import { RegistrationPage } from '../pages/registration.page';

/**
 * W5 — cancellations (RegistrationsController::cancel, EventsController::cancel).
 * Registration cancel refunds through the fake gateway: a settled transaction is
 * refunded (POST refund), a submitted-for-settlement one is voided (PUT void).
 * The fixture transaction ids (`fx-settled-*`, `fx-sfs-*`) are rejected by
 * Braintree_TransactionGateway::_validateId (/^[0-9a-z]+$/), so each paid test
 * first shapes its registration’s transaction_id to a lib-valid lowercase
 * alphanumeric id that preserves the fake gateway’s `sfs` signal (test setup only).
 * The attendee-cancellation cutoff is enforced for non-admins; admins override it.
 * After the cutoff the UI offers no Cancel RSVP button to non-admins at all.
 */
test.beforeEach(async () => {
  await resetFor();
});

test('attendee cancels a free registration before the cutoff', async ({ session }) => {
  const page = await session('anonymous');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoView(3001, 'edit-key-3001');
  await reg.cancelRsvp();
  expect(await reg.bodyText()).toContain('Your registration has been cancelled.');
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  expect(diffText({ registrations: diffTable(beforeRegs, afterRegs) })).toMatchSnapshot('w5-free-cancel-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w5-free-cancel-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w5-free-cancel-mail.txt');
});

test('attendee cancels a settled paid registration before the cutoff: refund', async ({ session }) => {
  const page = await session('anonymous');
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsettled0001', 3003]);
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoView(CATALOG.registrations.paidConfirmedSettled, 'edit-key-3003');
  await reg.cancelRsvp();
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const diff = diffTable(beforeRegs, afterRegs);
  expect(diff.changed['3003'].status).toEqual(['confirmed', 'cancelled']);
  expect(diffText({ registrations: diff })).toMatchSnapshot('w5-settled-cancel-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w5-settled-cancel-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w5-settled-cancel-mail.txt');
});

test('attendee cancels a submitted-for-settlement paid registration: void', async ({ session }) => {
  const page = await session('anonymous');
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsfs0001', 3004]);
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoView(CATALOG.registrations.paidConfirmedSfs, 'edit-key-3004');
  await reg.cancelRsvp();
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const diff = diffTable(beforeRegs, afterRegs);
  expect(diff.changed['3004'].status).toEqual(['confirmed', 'cancelled']);
  expect(diffText({ registrations: diff })).toMatchSnapshot('w5-sfs-cancel-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w5-sfs-cancel-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w5-sfs-cancel-mail.txt');
});

test('after the cutoff a non-admin is offered no cancel button and nothing changes', async ({ session }) => {
  // Shape a confirmed paid registration on the already-closed event 1007 (test setup only).
  await exec(
    'INSERT INTO `registrations` (`id`,`event_id`,`type`,`name`,`email`,`phone`,`ad_username`,`send_text`,`edit_key`,`status`,`attended`,`ad_assigned`,`transaction_id`,`created`,`modified`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [3991, 1007, 'paid', 'Late Cancel Paid', 'late.cancel@example.test', '555-0196', null, 0, 'edit-key-3991', 'confirmed', 0, 0, 'fxsettled0099', '2031-01-14 16:00:00', '2031-01-14 16:00:00'],
  );
  const page = await session('anonymous');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoView(3991, 'edit-key-3991');
  await expect(reg.cancelButton).toBeHidden();
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  expect(diffText({ registrations: diffTable(beforeRegs, afterRegs) })).toMatchSnapshot('w5-after-cutoff-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w5-after-cutoff-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w5-after-cutoff-mail.txt');
});

test('admin overrides the cutoff and cancels with a refund', async ({ session }) => {
  await exec(
    'INSERT INTO `registrations` (`id`,`event_id`,`type`,`name`,`email`,`phone`,`ad_username`,`send_text`,`edit_key`,`status`,`attended`,`ad_assigned`,`transaction_id`,`created`,`modified`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [3991, 1007, 'paid', 'Late Cancel Paid', 'late.cancel@example.test', '555-0196', null, 0, 'edit-key-3991', 'confirmed', 0, 0, 'fxsettled0099', '2031-01-14 16:00:00', '2031-01-14 16:00:00'],
  );
  const page = await session('admin');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoView(3991, 'edit-key-3991');
  await expect(reg.cancelButton).toBeVisible();
  await reg.cancelRsvp();
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const diff = diffTable(beforeRegs, afterRegs);
  expect(diff.changed['3991'].status).toEqual(['confirmed', 'cancelled']);
  expect(diffText({ registrations: diff })).toMatchSnapshot('w5-admin-override-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w5-admin-override-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w5-admin-override-mail.txt');
});

test('owner cancels a paid event: registrations cancelled, refunds, mails', async ({ session }) => {
  const page = await session('member');
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsettled0001', 3003]);
  await exec('UPDATE `registrations` SET `transaction_id` = ? WHERE `id` = ?', ['fxsfs0001', 3004]);
  const beforeEvents = await tableRows('events');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const form = new EventFormPage(page);
  await form.gotoEdit(CATALOG.events.paidMembersOnly.id);
  await form.cancelEvent();
  const afterEvents = await tableRows('events');
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const eventDiff = diffTable(beforeEvents, afterEvents);
  const regDiff = diffTable(beforeRegs, afterRegs);
  expect(eventDiff.changed[String(CATALOG.events.paidMembersOnly.id)].status).toEqual(['approved', 'cancelled']);
  expect(regDiff.changed['3003'].status).toEqual(['confirmed', 'cancelled']);
  expect(regDiff.changed['3004'].status).toEqual(['confirmed', 'cancelled']);
  expect(diffText({ events: eventDiff, registrations: regDiff })).toMatchSnapshot('w5-event-cancel-diff.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w5-event-cancel-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w5-event-cancel-mail.txt');
});
