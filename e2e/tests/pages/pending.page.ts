import type { Locator, Page } from '@playwright/test';

/**
 * The "Pending Events" list (Calendar Admins only). Approve is a postLink with a
 * JavaScript confirm dialog, so the dialog is accepted explicitly: Playwright's
 * default auto-dismiss would cancel the approval instead of confirming it.
 */
export class PendingPage {
  constructor(private readonly page: Page) {}

  async goto() {
    await this.page.goto('/events/pending');
  }

  async gotoHonorariaPending() {
    await this.page.goto('/events/honoraria/pending');
  }

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Pending Events' });
  }

  get honorariaHeading(): Locator {
    return this.page.getByRole('heading', { name: 'Pending Events Requesting Honorarium' });
  }

  private rowFor(name: string): Locator {
    return this.page.getByRole('row', { name: new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) });
  }

  getRow(name: string): Locator {
    return this.rowFor(name);
  }

  async approve(name: string): Promise<void> {
    const row = this.rowFor(name);
    this.page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      row.getByRole('link', { name: 'Approve', exact: true }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async rejectLink(name: string): Promise<void> {
    await this.rowFor(name).getByRole('link', { name: 'Reject', exact: true }).click();
    await this.page.waitForLoadState();
  }

  /** Flash banners currently shown. */
  async alertTexts(): Promise<string[]> {
    return (await this.page.getByRole('alert').allInnerTexts()).map((t) => t.replace(/[\s]+/g, ' ').trim()).filter(Boolean);
  }
}
