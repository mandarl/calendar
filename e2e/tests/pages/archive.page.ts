import type { Locator, Page } from '@playwright/test';

/** /events/all - the events archive (admin only) with pagination and a GET date-range filter. */
export class ArchivePage {
  constructor(private readonly page: Page) {}

  async goto(query = '') {
    await this.page.goto(query ? `/events/all?${query}` : '/events/all');
  }

  get rows(): Locator {
    return this.page.locator('table tbody tr');
  }

  get paginator(): Locator {
    return this.page.locator('.pagination');
  }

  pageLink(n: number): Locator {
    return this.paginator.getByRole('link', { name: String(n), exact: true });
  }

  get startDate(): Locator {
    return this.page.locator('input[name="start_date"]');
  }

  get endDate(): Locator {
    return this.page.locator('input[name="end_date"]');
  }

  get applyButton(): Locator {
    return this.page.locator('form[action="/events/all"] [type="submit"], form[action="/events/all"] button').first();
  }

  async names(): Promise<string[]> {
    const cells = await this.rows.locator('td:first-child').allTextContents();
    return cells.map((t) => t.replace(/\s+/g, ' ').trim());
  }
}
