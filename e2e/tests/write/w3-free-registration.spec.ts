import { test, expect, resetFor } from '../support/write-fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { diffTable, diffText, mailGolden, rows, tableRows } from '../support/effects';
import { RegistrationPage } from '../pages/registration.page';

/**
 * W3 — free registration (RegistrationsController::event, Crud Add).
 * Anonymous registrations are identified by edit_key; member registrations by
 * ad_username. Approval-required events create pending registrations and mail
 * both the attendee and the instructor.
 */
test.beforeEach(async () => {
  await resetFor();
});

test('anonymous registers for a free open class', async ({ session }) => {
  const page = await session('anonymous');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.freeOpen.id);
  await reg.register({ name: 'Walk In One', email: 'walkin.one@example.test', phone: '555-0190' });
  expect(await reg.bodyText()).toContain("You're all set!");
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['walkin.one@example.test']);
  expect(Object.keys(added)).toHaveLength(1);
  const row = added[Object.keys(added)[0]];
  expect(row).toMatchObject({ event_id: CATALOG.events.freeOpen.id, type: 'free', status: 'confirmed', ad_username: '' }); // anonymous form posts an empty ad_username, not NULL
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  expect(diffText({ registrations: diffTable(beforeRegs, afterRegs) })).toMatchSnapshot('w3-free-registrations.txt');
  expect(await mailGolden()).toMatchSnapshot('w3-free-mail.txt');
});

test('member registers for a free members-only meetup', async ({ session }) => {
  const page = await session('member');
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.freeMembersOnly.id);
  await reg.register({ name: 'Member One', email: 'member.one@example.test', phone: '555-0191' });
  expect(await reg.bodyText()).toContain("You're all set!");
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['member.one@example.test']);
  expect(Object.keys(added)).toHaveLength(1);
  expect(added[Object.keys(added)[0]]).toMatchObject({ type: 'free', status: 'confirmed', ad_username: 'user1' });
  expect(await mailGolden()).toMatchSnapshot('w3-member-mail.txt');
});

test('approval-required event creates a pending registration and mails both sides', async ({ session }) => {
  const page = await session('anonymous');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.approvalRequired.id);
  await reg.register({ name: 'Pending One', email: 'pending.one@example.test', phone: '555-0192' });
  expect(await reg.bodyText()).toContain('Your registration is still pending.');
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['pending.one@example.test']);
  expect(Object.keys(added)).toHaveLength(1);
  expect(added[Object.keys(added)[0]]).toMatchObject({ type: 'free', status: 'pending' });
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  expect(diffText({ registrations: diffTable(beforeRegs, afterRegs) })).toMatchSnapshot('w3-pending-registrations.txt');
  expect(await mailGolden()).toMatchSnapshot('w3-pending-mail.txt');
});

test('age-restricted event requires the age acknowledgement', async ({ session }) => {
  const page = await session('anonymous');
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.ageRestricted.id);
  await reg.register({ name: 'Adult One', email: 'adult.one@example.test', phone: '555-0193', ageAcknowledged: true });
  expect(await reg.bodyText()).toContain("You're all set!");
  const added = await rows('SELECT * FROM `registrations` WHERE `email` = ?', ['adult.one@example.test']);
  expect(Object.keys(added)).toHaveLength(1);
  expect(added[Object.keys(added)[0]]).toMatchObject({ status: 'confirmed' });
  expect(await mailGolden()).toMatchSnapshot('w3-age-mail.txt');
});

test('full event offers no registration form', async ({ session }) => {
  const page = await session('anonymous');
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.fullEvent.id);
  await expect(reg.noSpacesNotice).toBeVisible();
  const regs = await rows('SELECT * FROM `registrations` WHERE `event_id` = ?', [CATALOG.events.fullEvent.id]);
  expect(Object.keys(regs)).toHaveLength(2);
});

test('member already registered is redirected to their registration', async ({ session }) => {
  const page = await session('member');
  const reg = new RegistrationPage(page);
  await reg.gotoEvent(CATALOG.events.freeOpen.id);
  expect(page.url()).toContain('/registrations/view/3001');
  await expect(reg.statusHeading).toBeVisible();
});
