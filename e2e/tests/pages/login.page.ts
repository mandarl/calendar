import type { Locator, Page } from '@playwright/test';

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
}
