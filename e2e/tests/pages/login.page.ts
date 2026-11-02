import { expect, type Locator, type Page } from '@playwright/test';

import { EventsIndexPage } from './events-index.page';

export class LoginPage {
  constructor(private readonly page: Page) {}

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'DMS Member Log In' });
  }

  private get usernameInput(): Locator {
    return this.page.getByLabel('Username');
  }

  private get passwordInput(): Locator {
    return this.page.getByLabel('Password');
  }

  private get loginButton(): Locator {
    return this.page.getByRole('button', { name: 'Login' });
  }

  async goto() {
    await this.page.goto('/users/login');
  }

  async submitCredentials(username: string, password: string) {
    await this.usernameInput.fill(username);
    await this.passwordInput.fill(password);
    await this.loginButton.click();
  }

  async loginAsMember(username: string, password: string): Promise<EventsIndexPage> {
    await this.submitCredentials(username, password);
    return new EventsIndexPage(this.page);
  }

  /** Flash/alert message shown after a failed login. */
  get flash(): Locator {
    return this.page.getByRole('alert').or(this.page.locator('.alert, .message')).first();
  }

  /** The nav shows the account menu only for signed-in users. */
  async expectLoggedIn() {
    await expect(this.page.getByRole('button', { name: 'My Account' })).toBeVisible();
  }
}
