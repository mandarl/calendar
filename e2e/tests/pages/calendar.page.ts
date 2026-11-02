import type { Locator, Page } from '@playwright/test';

const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

export class CalendarPage {
  constructor(private readonly page: Page) {}

  async goto(path = '/events/calendar') {
    await this.page.goto(path);
  }

  /** "January 2031"-style month heading with the prev/next arrows. */
  get monthHeading(): Locator {
    return this.page.getByRole('heading', { name: new RegExp(`^(${MONTHS}) \\d{4}$`) });
  }

  async monthText(): Promise<string> {
    return ((await this.monthHeading.textContent()) ?? '').replace(/\s+/g, ' ').trim();
  }

  async next() {
    await this.monthHeading.locator('.next a').click();
  }

  async previous() {
    await this.monthHeading.locator('.prev a').click();
  }

  get listViewLink(): Locator {
    return this.page.getByRole('link', { name: 'List View' });
  }

  get cells(): Locator {
    return this.page.locator('table.table-calendar td');
  }

  /** Event entries shown in the grid (each carries the event name as its title; the detail link is in a popover). */
  get eventLinks(): Locator {
    return this.page.locator('table.table-calendar td a[title]');
  }
}
