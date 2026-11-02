import type { Locator, Page } from '@playwright/test';

/** Top navigation bar. Locates by role/text only so the same checks work on a rebuilt (Cake 5) UI. */
export class NavComponent {
  constructor(private readonly page: Page) {}

  private get bar(): Locator {
    return this.page.getByRole('navigation').first();
  }

  /** Every link in the nav (including collapsed dropdown items), by visible text, de-duplicated and sorted. */
  async linkNames(): Promise<string[]> {
    // CSS (not role) locator on purpose: collapsed dropdown items are hidden but still part of the menu.
    const texts = await this.bar.locator('a').evaluateAll((els) =>
      els.map((e) => (e.textContent ?? '').replace(/\s+/g, ' ').trim()).filter(Boolean),
    );
    return [...new Set(texts)].sort();
  }

  /** Top-level dropdown/menu buttons (Popular Links, Admin, My Account ...), sorted. */
  async menuButtonNames(): Promise<string[]> {
    const texts = await this.bar.locator('[data-toggle="dropdown"]').evaluateAll((els) =>
      els.map((e) => (e.textContent ?? '').replace(/\s+/g, ' ').trim()).filter(Boolean),
    );
    return [...new Set(texts)].sort();
  }

  get accountMenu(): Locator {
    return this.page.getByRole('button', { name: 'My Account' });
  }

  async logout(): Promise<void> {
    await this.accountMenu.click();
    await this.page.getByRole('link', { name: 'Logout' }).click();
  }
}
