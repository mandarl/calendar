import type { Page } from '@playwright/test';

/**
 * /events/cron is anonymous and mutating, so it is deliberately kept OUT of the
 * crawl allowlist and is called only from the write project (own harness project
 * calwt), through the same browser context as the rest of the spec.
 */
export class CronPage {
  constructor(private readonly page: Page) {}

  /** GET /events/cron; returns the HTTP status. The action renders nothing. */
  async run(): Promise<number> {
    const response = await this.page.request.get('/events/cron');
    return response.status();
  }
}
