import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { test, expect, resetFor } from '../support/write-fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { diffTable, diffText, mailGolden, rows, tableRows } from '../support/effects';
import { EventFormPage } from '../pages/event-form.page';
import { EventViewPage } from '../pages/event-view.page';

/**
 * W1 — event submission (EventsController::add / edit, Crud Add/Edit).
 * Every test starts from a pristine dataset (resetFor in beforeEach) and logs
 * in fresh, so new-row ids are deterministic (first new event id is 1035).
 * Chicago wall times in the form become UTC in the database (CST, UTC-6).
 */
test.beforeEach(async () => {
  await resetFor();
});

test('member adds a free class: pending row, categories link, submitted mail', async ({ session }) => {
  const page = await session('member');
  const beforeEvents = await tableRows('events');
  const beforeLinks = await tableRows('categories_events', '1=1', [], []);
  const form = new EventFormPage(page);
  await form.gotoAdd();
  await form.fill({
    title: 'Write Test Free Class',
    type: 'Class',
    shortDescription: 'A free class added by the write suite.',
    start: '02/10/2031 6:00 PM',
    end: '02/10/2031 8:00 PM',
    description: 'Long description for the write test class.',
    paid: 'Free',
    freeSpaces: 10,
    room: 'Common Area',
  });
  await form.submit();
  await page.waitForLoadState();
  expect(await form.alertTexts()).toContain('The event has been created. Your event will appear in 48 hours (non honorarium) or 72 hours (honorarium) unless there is an objection.');

  const added = await rows('SELECT * FROM `events` WHERE `name` = ?', ['Write Test Free Class']);
  expect(Object.keys(added)).toHaveLength(1);
  const id = Number(Object.keys(added)[0]);
  expect(added[String(id)]).toMatchObject({
    status: 'pending',
    created_by: 'user1',
    event_start: '2031-02-11 00:00:00',
    event_end: '2031-02-11 02:00:00',
    free_spaces: 10,
    room_id: 3,
  });

  const afterEvents = await tableRows('events');
  const afterLinks = await tableRows('categories_events', '1=1', [], []);
  expect(
    diffText({
      events: diffTable(beforeEvents, afterEvents),
      categories_events: diffTable(beforeLinks, afterLinks),
    }),
  ).toMatchSnapshot('w1-add-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w1-add-mail.txt');

  const view = new EventViewPage(page);
  await view.goto(id);
  await expect(view.title).toContainText('Write Test Free Class');
});

test('end before start shows server validation and creates nothing', async ({ session }) => {
  const page = await session('member');
  const beforeEvents = await tableRows('events');
  const form = new EventFormPage(page);
  await form.gotoAdd();
  // Browser validation is switched off so the request reaches the SERVER validation
  // (end must be after start); the browser would otherwise block it first.
  await form.fill({
    title: 'Write Test Invalid Dates',
    type: 'Class',
    shortDescription: 'End before start.',
    description: 'Long description.',
  });
  await form.setDateRaw('start', '02/10/2031 6:00 PM');
  await form.setDateRaw('end', '02/10/2031 5:00 PM');
  const status = await form.submitSkippingBrowserValidation();
  expect(status).toBe(200);
  expect(await form.alertTexts()).toContain('The event could not be created. Errors are highlighted in red below. Make any necessary adjustments and try submitting again.');
  const errors = await form.fieldErrors();
  expect(Object.keys(errors).length).toBeGreaterThan(0);
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w1-end-before-start-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w1-end-before-start-mail.txt');
});

test('completely blank submit 500s (current behaviour, characterised)', async ({ session }) => {
  // A blank POST never reaches validation: FriendlyTimeBehavior::beforeMarshal
  // calls createFromFormat on the missing dates and throws InvalidArgumentException
  // ('Data missing'), so the app renders its 500 page. The Cake 5 port must either
  // reproduce this or record an owner decision to fix it; the write suite pins it.
  const page = await session('member');
  const beforeEvents = await tableRows('events');
  const form = new EventFormPage(page);
  await form.gotoAdd();
  const status = await form.submitSkippingBrowserValidation();
  expect(status).toBe(500);
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w1-blank-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w1-blank-mail.txt');
});
test('multipart add creates the base event and its continued date', async ({ session }) => {
  const page = await session('member');
  const beforeEvents = await tableRows('events');
  const form = new EventFormPage(page);
  await form.gotoAdd();
  await form.fill({
    title: 'Write Test Series',
    type: 'Class',
    shortDescription: 'A two-part series added by the write suite.',
    description: 'Long description for the write test series.',
    start: '02/10/2031 6:00 PM',
    end: '02/10/2031 8:00 PM',
    paid: 'Free',
    freeSpaces: 10,
    room: 'Common Area',
    continuedDates: [['02/17/2031 6:00 PM', '02/17/2031 8:00 PM']],
  });
  await form.submit();
  await page.waitForLoadState();
  expect(await form.alertTexts()).toContain('The event has been created. Your event will appear in 48 hours (non honorarium) or 72 hours (honorarium) unless there is an objection.');

  const added = await rows('SELECT * FROM `events` WHERE `name` = ?', ['Write Test Series']);
  expect(Object.keys(added)).toHaveLength(2);
  const parts = Object.values(added);
  const base = parts.find((r) => r.part_of_id === null);
  const continued = parts.find((r) => r.part_of_id !== null);
  expect(base).toBeDefined();
  expect(continued).toBeDefined();
  expect(continued!.part_of_id).toBe(base!.id);
  expect(continued!.event_start).toBe('2031-02-18 00:00:00');

  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w1-multipart-events.txt');
  expect(await mailGolden()).toMatchSnapshot('w1-multipart-mail.txt');
});

test('owner edits own event title', async ({ session }) => {
  const page = await session('member');
  const beforeEvents = await tableRows('events');
  const form = new EventFormPage(page);
  await form.gotoEdit(CATALOG.events.freeOpen.id);
  await form.fill({ title: 'Fixture: Free Open Class (edited)' });
  await form.submit();
  await page.waitForLoadState();
  expect(await form.alertTexts()).toContain('The event has been updated.');
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w1-edit-events.txt');
  const view = new EventViewPage(page);
  await view.goto(CATALOG.events.freeOpen.id);
  await expect(view.title).toContainText('Fixture: Free Open Class (edited)');
});

test('member adds a class with a file attachment: files row, file on disk', async ({ session }) => {
  // Slice C proof: the add form's file slots (Upload behaviour) save a files
  // row and the bytes under webroot/files/<event_id>/ (shared into the runner
  // as /files). resetFor clears /files first, so nothing here can be a leftover.
  const page = await session('member');
  const beforeFiles = await tableRows('files');
  const form = new EventFormPage(page);
  await form.gotoAdd();
  await form.fill({
    title: 'Write Test Class With File',
    type: 'Class',
    shortDescription: 'A free class with an attachment.',
    start: '02/10/2031 6:00 PM',
    end: '02/10/2031 8:00 PM',
    description: 'Long description for the write test class with file.',
    paid: 'Free',
    freeSpaces: 10,
    room: 'Common Area',
    files: [{ slot: 1, name: 'write-test-handout.txt', mimeType: 'text/plain', buffer: Buffer.from('Write suite handout contents.\n') }],
  });
  await form.submit();
  await page.waitForLoadState();
  expect(await form.alertTexts()).toContain('The event has been created. Your event will appear in 48 hours (non honorarium) or 72 hours (honorarium) unless there is an objection.');

  const added = await rows('SELECT * FROM `events` WHERE `name` = ?', ['Write Test Class With File']);
  expect(Object.keys(added)).toHaveLength(1);
  const id = Number(Object.keys(added)[0]);
  expect(added[String(id)]).toMatchObject({ status: 'pending' });
  const files = await rows('SELECT * FROM `files` WHERE `event_id` = ?', [id]);
  expect(Object.keys(files)).toHaveLength(1);
  const fileRow = files[Object.keys(files)[0]];
  expect(fileRow).toMatchObject({ file: 'write-test-handout.txt', event_id: id, private: 0 });
  const filesRoot = process.env.FILES_DIR ?? '/files';
  expect(readFileSync(join(filesRoot, String(id), 'write-test-handout.txt'), 'utf8')).toBe('Write suite handout contents.\n');

  const afterFiles = await tableRows('files');
  expect(diffText({ files: diffTable(beforeFiles, afterFiles) })).toMatchSnapshot('w1-upload-files.txt');
  expect(await mailGolden()).toMatchSnapshot('w1-upload-mail.txt');
});

test('copy creates a new pending event from an owned event', async ({ session }) => {
  const page = await session('member');
  const beforeEvents = await tableRows('events');
  const form = new EventFormPage(page);
  await form.gotoCopy(CATALOG.events.freeOpen.id);
  await form.fill({
    type: 'Class',
    start: '02/24/2031 6:00 PM',
    end: '02/24/2031 8:00 PM',
  });
  await form.submit();
  await page.waitForLoadState();
  expect(await form.alertTexts()).toContain('The event has been created. Your event will appear in 48 hours (non honorarium) or 72 hours (honorarium) unless there is an objection.');
  const copies = await rows('SELECT * FROM `events` WHERE `name` = ? AND `id` <> ?', [
    'Fixture: Free Open Class',
    CATALOG.events.freeOpen.id,
  ]);
  expect(Object.keys(copies)).toHaveLength(1);
  const copy = copies[Object.keys(copies)[0]];
  expect(copy).toMatchObject({ status: 'pending', part_of_id: null, event_start: '2031-02-25 00:00:00' });
  const afterEvents = await tableRows('events');
  expect(diffText({ events: diffTable(beforeEvents, afterEvents) })).toMatchSnapshot('w1-copy-events.txt');
});
