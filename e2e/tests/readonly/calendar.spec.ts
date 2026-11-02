import { test, expect } from '../support/fixtures';
import { CATALOG } from '../../fixtures/synthetic/catalog';
import { CalendarPage } from '../pages/calendar.page';
import { EventsIndexPage } from '../pages/events-index.page';
import { GenericPage } from '../pages/generic.page';

test.describe('calendar month view', () => {
  test('opens on the anchor month and navigates prev/next', async ({ page }) => {
    const cal = new CalendarPage(page);
    await cal.goto();
    await expect(cal.monthHeading).toBeVisible();
    expect(await cal.monthText()).toBe('January 2031');

    await cal.next();
    expect(await cal.monthText()).toBe('February 2031');
    await cal.previous();
    await cal.previous();
    expect(await cal.monthText()).toBe('December 2030');
  });

  test('shows fixture events in the right month', async ({ page }) => {
    const cal = new CalendarPage(page);
    await cal.goto('/events/calendar/2031/2');
    expect(await cal.monthText()).toBe('February 2031');
    // unicodeLongHtml is on Feb 6 (Chicago); it should be linked from the grid.
    await expect(cal.eventLinks.filter({ hasText: 'Céramique' }).first()).toBeVisible();

    await cal.goto('/events/calendar/2031/3');
    expect(await cal.monthText()).toBe('March 2031');
    await expect(cal.eventLinks.filter({ hasText: CATALOG.events.dstSpringForward.name }).first()).toBeVisible();
  });

  test('can switch back to the list view', async ({ page }) => {
    const cal = new CalendarPage(page);
    const index = new EventsIndexPage(page);
    await cal.goto();
    await cal.listViewLink.click();
    await expect(index.heading).toBeVisible();
  });

  // CHARACTERISATION of a latent bug (do not fix in the baseline): the day view renders a template that does not
  // exist (EventsController.php:668 render('index_daily')). The status/outcome is recorded as current behaviour.
  test('day view current behaviour (golden)', async ({ page }) => {
    const generic = new GenericPage(page);
    const res = await generic.open('/events/calendar/2031/1/15');
    expect(JSON.stringify({ status: res?.status(), heading: (await generic.text()).slice(0, 120) })).toMatchSnapshot('calendar-day-view.json');
  });
});
