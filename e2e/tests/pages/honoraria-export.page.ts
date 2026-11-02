import type { Locator, Page } from '@playwright/test';

/**
 * Honoraria export (Financial Reporting only): the date-range list page and its
 * CSV download. The CSV is fetched through the page's own request context so it
 * carries the same authenticated session as the browser; the paid-status selects
 * have no accessible name, so they are located by their form field name.
 */
export class HonorariaExportPage {
  constructor(private readonly page: Page) {}

  async gotoList(startDate: string, endDate: string) {
    await this.page.goto(`/events/export-honoraria?start_date=${startDate}&end_date=${endDate}`);
  }

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Export Honoraria' });
  }

  async csvText(startDate: string, endDate: string): Promise<string> {
    const response = await this.page.request.get(`/events/export-honoraria-csv?start_date=${startDate}&end_date=${endDate}`);
    return response.text();
  }

  async csvStatus(startDate: string, endDate: string): Promise<number> {
    const response = await this.page.request.get(`/events/export-honoraria-csv?start_date=${startDate}&end_date=${endDate}`);
    return response.status();
  }

  private paidSelect(honorariumId: number): Locator {
    return this.page.locator(`select[name="paid[${honorariumId}]"]`);
  }

  async setPaid(honorariumId: number, label: string): Promise<void> {
    await this.paidSelect(honorariumId).selectOption({ label });
  }

  async save(): Promise<void> {
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: 'Save' }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async alertTexts(): Promise<string[]> {
    return (await this.page.getByRole('alert').allInnerTexts()).map((t) => t.replace(/[\s]+/g, ' ').trim()).filter(Boolean);
  }

  async bodyText(): Promise<string> {
    return (await this.page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  }
}
