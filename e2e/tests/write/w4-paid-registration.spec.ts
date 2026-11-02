import { test, expect, resetFor } from '../support/write-fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { diffTable, diffText, gatewayGolden, mailGolden, rows, tableRows } from '../support/effects';
import { RegistrationPage } from '../pages/registration.page';

/**
 * W4 — paid registration (RegistrationsController::event, Braintree sale via the
 * harness fake gateway). The drop-in is stubbed, so a normal paid submit sends
 * nonce `fake-valid-nonce`; the declined-nonce test posts the same form with
 * nonce `fake-declined-nonce` (a value the stub cannot produce) to reach the
 * gateway-failure branch, reusing the form's own CSRF/_Token fields.
 */
test.beforeEach(async () => {
  await resetFor();
});

test('member pays for a DMS-paid workshop: sale, confirmed registration, mail', async ({ session }) => {
  const page = await session('member');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.paidMembersOnly.id);
  await reg.register({
    name: 'Member Paid One',
    email: 'member.paid@example.test',
    phone: '555-0192',
    typeLabel: 'Paid Registration ($5.00)',
    safetyAcknowledged: true,
  });
  expect(await reg.bodyText()).toContain("You're all set!");
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['member.paid@example.test']);
  expect(Object.keys(added)).toHaveLength(1);
  expect(added[Object.keys(added)[0]]).toMatchObject({ type: 'paid', status: 'confirmed', transaction_id: 'txn-0001' });
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  expect(diffText({ registrations: diffTable(beforeRegs, afterRegs) })).toMatchSnapshot('w4-paid-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w4-paid-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w4-paid-mail.txt');
});

test('member chooses the free option on a paid-and-free event: no sale', async ({ session }) => {
  const page = await session('member');
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.paidAndFree.id);
  await reg.register({
    name: 'Member Free Choice',
    email: 'member.free@example.test',
    phone: '555-0194',
    typeLabel: 'Free Registration',
  });
  expect(await reg.bodyText()).toContain("You're all set!");
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['member.free@example.test']);
  expect(Object.keys(added)).toHaveLength(1);
  expect(added[Object.keys(added)[0]]).toMatchObject({ type: 'free', status: 'confirmed', transaction_id: null });
  expect(await gatewayGolden()).toMatchSnapshot('w4-free-choice-gateway.txt');
  expect(await mailGolden()).toMatchSnapshot('w4-free-choice-mail.txt');
});

test('declined card: gateway failure is reported, no registration is created, but a confirmation mail is still sent (characterised bug)', async ({ session }) => {
  const page = await session('member');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  const html = await reg.registerWithNonce(
    CATALOG.events.paidMembersOnly.id,
    { name: 'Member Declined', email: 'member.declined@example.test', phone: '555-0195', typeLabel: 'Paid Registration ($5.00)', safetyAcknowledged: true },
    'fake-declined-nonce',
  );
  expect(html).toContain('Do Not Honor');
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['member.declined@example.test']);
  expect(Object.keys(added)).toHaveLength(0);
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  expect(diffText({ registrations: diffTable(beforeRegs, afterRegs) })).toMatchSnapshot('w4-declined-registrations.txt');
  expect(await gatewayGolden()).toMatchSnapshot('w4-declined-gateway.txt');
  // Characterised Cake 3 bug: beforeSave records the gateway error but its
  // stopPropagation() is commented out, so the save fails on the entity error
  // (no row) yet the afterSave listener still sends sendRegistrationConfirmation.
  // The attendee is told 'Do Not Honor' AND mailed 'You\'re confirmed'.
  const declinedMail = await mailGolden();
  expect(declinedMail).toMatchSnapshot('w4-declined-mail.txt');
  expect(declinedMail).toContain('You\'re confirmed for an event!');
});
