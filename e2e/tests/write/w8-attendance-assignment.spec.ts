import { test, expect, resetFor } from '../support/write-fixtures';
import { diffTable, diffText, exec, tableRows } from '../support/effects';
import { EventManagePage } from '../pages/event-manage.page';

/**
 * W8 — attendance + AD assignment (EventsController::attendance / assignments,
 * the tabs on the event view page for hosts/admins). Attendance can only be
 * taken while the event start is within the last 48h (configuration 6 = 2 days),
 * so the attendance scenario moves event 1001's start to 2h before the anchor
 * (test setup only). AD assignment attaches the attendee to the AD group of the
 * prerequisite the event fulfills (Woodshop Basics) in the harness dev LDAP.
 */
test.beforeEach(async () => {
  await resetFor();
});

test('host marks attendance for a recent event', async ({ session }) => {
  await exec('UPDATE `events` SET `event_start` = ?, `event_end` = ? WHERE `id` = ?', ['2031-01-15 14:00:00', '2031-01-15 15:00:00', 1001]);
  const page = await session('member');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const manage = new EventManagePage(page);
  await manage.gotoView(1001);
  await manage.openAttendance();
  await expect(manage.attendanceCheckboxes()).toHaveCount(1);
  await manage.markAttendedAll();
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const diff = diffTable(beforeRegs, afterRegs);
  expect(diff.changed['3001'].attended).toEqual([0, 1]);
  expect(diffText({ registrations: diff })).toMatchSnapshot('w8-attendance-diff.txt');
  await manage.gotoView(1001);
  await manage.openAttendance();
  await expect(manage.attendanceCheckboxes().first()).toBeChecked();
});

test('host assigns the AD group for a prerequisite-fulfilling event', async ({ session }) => {
  await exec(
    'INSERT INTO `registrations` (`id`,`event_id`,`type`,`name`,`email`,`phone`,`ad_username`,`send_text`,`edit_key`,`status`,`attended`,`ad_assigned`,`transaction_id`,`created`,`modified`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [3992, 1011, 'free', 'AD Assignee', 'ad.assignee@example.test', '555-0197', 'user1', 0, 'edit-key-3992', 'confirmed', 0, 0, null, '2031-01-14 16:00:00', '2031-01-14 16:00:00'],
  );
  const page = await session('member');
  const beforeRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const manage = new EventManagePage(page);
  await manage.gotoView(1011);
  await manage.openAssignments();
  await expect(manage.assignmentCheckboxes()).toHaveCount(1);
  await manage.assignAll();
  const afterRegs = await tableRows('registrations', '1=1', [], ['edit_key']);
  const diff = diffTable(beforeRegs, afterRegs);
  expect(diff.changed['3992'].ad_assigned).toEqual([0, 1]);
  expect(diffText({ registrations: diff })).toMatchSnapshot('w8-assignment-diff.txt');
  await manage.gotoView(1011);
  await manage.openAssignments();
  await expect(manage.assignmentCheckmarks()).toHaveCount(1);
});

test('attendance is closed for an event that ended days ago', async ({ session }) => {
  const page = await session('member');
  const manage = new EventManagePage(page);
  await manage.gotoView(1018);
  await manage.openAttendance();
  expect(await manage.paneText('attendance')).toContain('Attendance is closed for this class.');
});
