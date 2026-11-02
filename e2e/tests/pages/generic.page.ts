import type { Locator, Page, Response } from '@playwright/test';

/**
 * A page addressed by path. Used where the check is about access/redirect/status rather than content
 * (access matrix, admin index pages, error pages), so specs never call page.goto themselves.
 */
export class GenericPage {
  constructor(private readonly page: Page) {}

  async open(path: string): Promise<Response | null> {
    return this.page.goto(path);
  }

  get url(): string {
    return this.page.url();
  }

  get pathAndQuery(): string {
    const u = new URL(this.page.url());
    return u.pathname + u.search;
  }

  get mainHeading(): Locator {
    return this.page.getByRole('heading', { level: 1 }).or(this.page.getByRole('heading', { level: 2 })).first();
  }

  /** Rows of the first data table (admin index pages), excluding the header row. */
  get tableRows(): Locator {
    return this.page.locator('table tbody tr');
  }

  get tableHeaders(): Locator {
    return this.page.locator('table thead th');
  }

  /** Pin the browser clock to the anchor (server-rendered pages already use the server's faked clock). */
  async fixClock(instant: Date): Promise<void> {
    await this.page.clock.setFixedTime(instant);
  }

  /** Wait until web fonts and network are settled so screenshots are stable. */
  async settle(): Promise<void> {
    await this.page.waitForLoadState('networkidle');
    await this.page.evaluate(() => document.fonts.ready.then(() => undefined));
  }

  /** Regions whose content is intentionally non-deterministic between renders. */
  get volatileRegions(): Locator[] {
    return [
      this.page.locator('#payment-form'), // payment widget placeholder
      this.page.locator('[data-visual-mask]'),
      this.page.locator('input[name="edit_key"]'),
      // A <select multiple> scrolls itself to its first selected option and the scroll position is not deterministic.
      // The selected state is still asserted via the crawl's HTML bodies.
      this.page.locator('select[multiple]'),
    ];
  }

  /** Standard volatile regions plus any page-specific selectors. */
  masks(extraSelectors: string[] = []): Locator[] {
    return [...this.volatileRegions, ...extraSelectors.map((s) => this.page.locator(s))];
  }

  async text(): Promise<string> {
    return (await this.page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  }
}
