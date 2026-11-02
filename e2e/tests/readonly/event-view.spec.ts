import { test, expect } from '../support/fixtures';
import { authFile } from '../data/test-users';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { EventViewPage } from '../pages/event-view.page';

const E = CATALOG.events;

test.describe('event view (anonymous visitor)', () => {
  test('free open class: register button, free cost, spaces', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.freeOpen.id);
    await expect(v.title).toHaveText(E.freeOpen.name);
    await expect(v.registerButton).toBeVisible();
    await expect(v.spacesAvailable).toContainText('9 spaces of 10 available'); // 1 confirmed, 1 cancelled
    await expect(v.editButton).toBeHidden();
  });

  test('DMS-paid members-only workshop: cost, restriction, cancellation cutoff', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.paidMembersOnly.id);
    await expect(v.costLine).toContainText('$5.00');
    await expect(v.restrictions).toContainText('DMS members only');
    await expect(v.cancellationNotice).toContainText('January 19, 2031');
    await expect(v.spacesAvailable).toContainText('6 spaces of 8 available'); // 2 confirmed paid, 1 cancelled
    await expect(v.registerButton).toBeVisible();
  });

  test('DMS-paid $45 class shows its cost', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.paid45.id);
    await expect(v.costLine).toContainText('$45.00');
  });

  test('Eventbrite open: third-party link and cost note (current behaviour: the DMS register button is also shown)', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.eventbriteOpen.id);
    await expect(v.eventbriteLink).toBeVisible();
    await expect(v.costLine).toContainText('Paid through Eventbrite');
    await expect(v.registerButton).toBeVisible();
  });

  test('Eventbrite closed and DMS-paid closed show the closed notice', async ({ page }) => {
    const v = new EventViewPage(page);
    for (const key of ['eventbriteClosed', 'registrationClosed']) {
      await v.goto(E[key].id);
      await expect(v.closedNotice, key).toBeVisible();
      await expect(v.registerButton, key).toBeHidden();
    }
  });

  test('full event says there are no spaces and offers no registration', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.fullEvent.id);
    await expect(v.fullNotice).toBeVisible();
    await expect(v.registerButton).toBeHidden();
  });

  test('age restriction is described; a required prerequisite is NOT shown on the event page (current behaviour)', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.ageRestricted.id);
    await expect(v.restrictions).toContainText('18');
    await v.goto(E.requiresPrerequisite.id);
    await expect(v.restrictions).toHaveCount(0);
    await expect(v.registerButton).toBeVisible();
  });

  // Current behaviour: descriptions are HTML-escaped, so markup is shown literally rather than rendered.
  test('unicode title renders and a long HTML description is shown escaped', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.unicodeLongHtml.id);
    await expect(v.title).toHaveText('Fixture: Céramique 日本語 Atelier');
    const text = await v.bodyText();
    expect(text).toContain('<strong>bold</strong>');
    expect(text).toContain('Second item with &amp; entity');
  });

  test('uploaded public image is linked from the event', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.withFiles.id);
    await expect(v.fileLinks.first()).toBeVisible();
  });

  // Page text for every variant is stored so the rebuilt app can be compared sentence by sentence.
  const goldenKeys = [
    'freeOpen', 'freeMembersOnly', 'paidMembersOnly', 'paid45', 'paidAndFree', 'eventbriteOpen', 'eventbriteClosed',
    'registrationClosed', 'ageRestricted', 'requiresPrerequisite', 'fulfillsPrerequisite', 'seriesParent', 'fullEvent',
    'approvalRequired', 'roomToolBooking', 'withFiles', 'unicodeLongHtml', 'dstSpringForward', 'dstFallBack',
  ];
  for (const key of goldenKeys) {
    test(`text golden: ${key}`, async ({ page }) => {
      const v = new EventViewPage(page);
      await v.goto(E[key].id);
      expect(await v.bodyText()).toMatchSnapshot(`event-view-${key}.txt`);
    });
  }
});

test.describe('event view (admin)', () => {
  test.use({ storageState: authFile('admin') });

  test('admin sees edit/copy and the attendee panel', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.paidMembersOnly.id);
    await expect(v.editButton).toBeVisible();
    await expect(v.copyButton).toBeVisible();
    await expect(v.attendeeTabs.first()).toBeVisible();
  });

  test('admin text golden: paid event with registrations', async ({ page }) => {
    const v = new EventViewPage(page);
    await v.goto(E.paidMembersOnly.id);
    expect(await v.bodyText()).toMatchSnapshot('event-view-admin-paidMembersOnly.txt');
  });
});
