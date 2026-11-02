import type { Locator, Page } from '@playwright/test';

export class EventsIndexPage {
  constructor(private readonly page: Page) {}

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Upcoming Classes and Events' });
  }

  private get calendarViewLink(): Locator {
    return this.page.getByRole('link', { name: 'Calendar View' });
  }

  /** One level-4 heading per listed event: "<time> <name> - <cost>". */
  get eventHeadings(): Locator {
    return this.page.getByRole('heading', { level: 4 });
  }

  get latestEventsLink(): Locator {
    return this.page.getByRole('link', { name: /Latest Events/ });
  }

  get createdRecentlyLink(): Locator {
    return this.page.getByRole('link', { name: /Created Recently/ });
  }

  async goto(query = '') {
    await this.page.goto(query ? `/?${query}` : '/');
  }

  async openCalendarView() {
    await this.calendarViewLink.click();
  }

  /** Visible text of every listed event heading, in page order. */
  async eventTitles(): Promise<string[]> {
    return (await this.eventHeadings.allTextContents()).map((t) => t.replace(/\s+/g, ' ').trim());
  }

  /** The dropdown filter buttons (By Type, By Category, By Tool, By Room). */
  filterButton(name: 'By Type' | 'By Category' | 'By Tool' | 'By Room'): Locator {
    return this.page.getByRole('button', { name });
  }
}
