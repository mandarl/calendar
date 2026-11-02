import type { Locator, Page } from '@playwright/test';

/**
 * The Crud scaffold admin pages (categories, committees, tools, rooms,
 * prerequisites, contacts, configurations): index / add / edit / delete.
 * Delete is a postLink with a confirm dialog, accepted explicitly.
 * The Calendar Admin page (super-config message + Allow Honoraria toggle)
 * has its own small page object below.
 */
export class AdminCrudPage {
  constructor(private readonly page: Page) {}

  async gotoIndex(resource: string) {
    await this.page.goto(`/${resource}`);
  }

  async gotoAdd(resource: string) {
    await this.page.goto(`/${resource}/add`);
  }

  async gotoEdit(resource: string, id: number) {
    await this.page.goto(`/${resource}/edit/${id}`);
  }

  get heading(): Locator {
    return this.page.getByRole('heading').first();
  }

  private field(label: string): Locator {
    return this.page.getByRole('textbox', { name: new RegExp(`^${label}`) });
  }

  async fillField(label: string, value: string): Promise<void> {
    await this.field(label).fill(value);
  }

  async fieldValue(label: string): Promise<string> {
    return this.field(label).inputValue();
  }

  async submitAdd(resourceSingular: string): Promise<void> {
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: `Add New ${resourceSingular}` }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async submitEdit(resourceSingular: string): Promise<void> {
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: `Save ${resourceSingular}` }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  /** The index row mentioning `text` (name cell). */
  rowFor(text: string): Locator {
    return this.page.getByRole('row', { name: new RegExp(text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) });
  }

  async deleteRow(text: string): Promise<void> {
    const row = this.rowFor(text);
    this.page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      row.getByRole('link', { name: 'Delete', exact: true }).click(),
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

export class CalendarAdminPage {
  constructor(private readonly page: Page) {}

  async goto() {
    await this.page.goto('/calendar-admin/edit');
  }

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Calendar Super Admin' });
  }

  get allowHonorariaCheckbox(): Locator {
    return this.page.getByRole('checkbox', { name: 'Allow Honoraria' });
  }

  get messageInput(): Locator {
    return this.page.getByRole('textbox', { name: 'Message to be displayed' });
  }

  async save(): Promise<void> {
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: 'Save Configuration Value' }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async alertTexts(): Promise<string[]> {
    return (await this.page.getByRole('alert').allInnerTexts()).map((t) => t.replace(/[\s]+/g, ' ').trim()).filter(Boolean);
  }
}
