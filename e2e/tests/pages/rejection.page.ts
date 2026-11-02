import type { Locator, Page } from '@playwright/test';

/** "Process Event Rejection": the form that posts a rejection reason to /events/reject/<id>. */
export class RejectionPage {
  constructor(private readonly page: Page) {}

  async goto(id: number, redirectUrl = '/events/pending') {
    await this.page.goto(`/events/process-rejection/${id}?redirect_url=${encodeURIComponent(redirectUrl)}`);
  }

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Process Event Rejection' });
  }

  private get reasonInput(): Locator {
    return this.page.getByRole('textbox', { name: /rejection reason/i });
  }

  async rejectWithReason(reason: string): Promise<void> {
    await this.reasonInput.fill(reason);
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: 'Reject Event' }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async alertTexts(): Promise<string[]> {
    return (await this.page.getByRole('alert').allInnerTexts()).map((t) => t.replace(/[\s]+/g, ' ').trim()).filter(Boolean);
  }
}
