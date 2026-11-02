import { test, expect, resetFor } from '../support/write-fixtures';
import { diffTable, diffText, exec, tableRows } from '../support/effects';
import { HonorariaExportPage } from '../pages/honoraria-export.page';

/**
 * W7 — honoraria export (EventsController::exportHonoraria / exportHonorariaCsv,
 * Financial Reporting only). The pristine fixtures contain no completed event
 * with an honorarium, so the scenario is shaped first (test setup only): the
 * completed event 1018 gets an honorarium (pay contact, unpaid) and three
 * attended registrations, joining its one attended fixture registration for
 * four attendees (> 2, so "Honoraria Met"); the approved event 1007 gets an
 * honorarium but no attendees ("Honoraria Not Met"). CSV rows are ordered by
 * event start, so 1018 (Jan 10) precedes 1007 (Jan 15).
 */
const START = '2031-01-01';
const END = '2031-01-31';

async function shapeScenario(): Promise<void> {
  await exec(
    'INSERT INTO `honoraria` (`id`,`event_id`,`pay_contact`,`committee_id`,`paid`,`created`,`modified`) VALUES (?,?,?,?,?,?,?)',
    [5999, 1018, 2004, 1, 0, '2030-12-16 16:00:00', '2030-12-16 16:00:00'],
  );
  await exec(
    'INSERT INTO `honoraria` (`id`,`event_id`,`pay_contact`,`committee_id`,`paid`,`created`,`modified`) VALUES (?,?,?,?,?,?,?)',
    [5998, 1007, 2004, 1, null, '2030-12-16 16:00:00', '2030-12-16 16:00:00'],
  );
  // The CSV lists completed events only, so the not-met event must be completed.
  await exec('UPDATE `events` SET `status` = ? WHERE `id` = ?', ['completed', 1007]);
  for (const [id, n] of [[3993, '93'], [3994, '94'], [3995, '95']] as const) {
    await exec(
      'INSERT INTO `registrations` (`id`,`event_id`,`type`,`name`,`email`,`phone`,`ad_username`,`send_text`,`edit_key`,`status`,`attended`,`ad_assigned`,`transaction_id`,`created`,`modified`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [id, 1018, 'free', `Shaped Attendee ${n}`, `shaped.${n}@example.test`, '555-0198', null, 0, `edit-key-${id}`, 'confirmed', 1, 0, null, '2031-01-14 16:00:00', '2031-01-14 16:00:00'],
    );
  }
}

test.beforeEach(async () => {
  await resetFor();
});

test('CSV export lists met and not-met honoraria in start order', async ({ session }) => {
  await shapeScenario();
  const page = await session('financialReporting');
  const exportPage = new HonorariaExportPage(page);
  expect(await exportPage.csvStatus(START, END)).toBe(200);
  const csv = await exportPage.csvText(START, END);
  expect(csv).toContain('"Event ID","Event Time","Event Name",Committee,"Contact Name","Contact Email","Contact Phone",Attendees,"Requirements Met","Pay Contact?",Paid');
  expect(csv).toContain('1018,"01-10-2031 6:00PM","Fixture: Completed Event","Creative Arts","Test Instructor A",instructor.a@example.test,555-0101,4,"Honoraria Met",Yes,"Not Paid"');
  expect(csv).toContain('1007,"01-15-2031 9:00AM","Fixture: Eventbrite Registration Closed","Creative Arts","Test Instructor B",instructor.b@example.test,555-0102,0,"Honoraria Not Met","Don\'t Pay",N/A');
  expect(csv).toMatchSnapshot('w7-csv.txt');
});

test('export page saves a paid status', async ({ session }) => {
  await shapeScenario();
  const page = await session('financialReporting');
  const beforeHonoraria = await tableRows('honoraria');
  const exportPage = new HonorariaExportPage(page);
  await exportPage.gotoList(START, END);
  await expect(exportPage.heading).toBeVisible();
  await exportPage.setPaid(5999, 'Paid');
  await exportPage.save();
  const afterHonoraria = await tableRows('honoraria');
  const diff = diffTable(beforeHonoraria, afterHonoraria);
  expect(diff.changed['5999'].paid).toEqual([0, 1]);
  expect(diffText({ honoraria: diff })).toMatchSnapshot('w7-paid-diff.txt');
});

test('member cannot export honoraria', async ({ session }) => {
  await shapeScenario();
  const page = await session('member');
  const exportPage = new HonorariaExportPage(page);
  const csv = await exportPage.csvText(START, END);
  expect(csv).not.toContain('Fixture: Completed Event');
  expect(csv).not.toContain('Event ID,Event Time');
});
