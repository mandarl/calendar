import type { Locator, Page } from '@playwright/test';

export class EventsIndexPage {
  constructor(private readonly page: Page) {}

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Upcoming Classes and Events' });
  }

  private get calendarViewLink(): Locator {
    return this.page.getByRole('link', { name: 'Calendar View' });
  }

  async goto() {
    await this.page.goto('/');
  }

  async openCalendarView() {
    await this.calendarViewLink.click();
  }
}
