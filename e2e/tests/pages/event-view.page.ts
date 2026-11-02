import type { Locator, Page } from '@playwright/test';

export class EventViewPage {
  constructor(private readonly page: Page) {}

  async goto(id: number) {
    await this.page.goto(`/events/view/${id}`);
  }

  get title(): Locator {
    return this.page.getByRole('heading', { level: 1 });
  }

  get registrationHeading(): Locator {
    return this.page.getByRole('heading', { name: 'Registration', exact: true });
  }

  get registerButton(): Locator {
    return this.page.getByRole('link', { name: 'Register for this Event' });
  }

  get closedNotice(): Locator {
    return this.page.getByText('Registration for the event is closed.');
  }

  get fullNotice(): Locator {
    return this.page.getByText('There are no more spaces available for this event.');
  }

  get eventbriteLink(): Locator {
    return this.page.getByRole('link', { name: /third party registration/i });
  }

  get editButton(): Locator {
    return this.page.getByRole('link', { name: 'Edit Event' });
  }

  get copyButton(): Locator {
    return this.page.getByRole('link', { name: 'Copy Event' });
  }

  get spacesAvailable(): Locator {
    return this.page.locator('p.spaces_avaliable');
  }

  /** "Cost: ..." line in the Registration panel. */
  get costLine(): Locator {
    return this.page.locator('li', { hasText: /^\s*Cost:/ });
  }

  get restrictions(): Locator {
    return this.page.locator('li', { hasText: 'Restrictions:' });
  }

  get cancellationNotice(): Locator {
    return this.page.getByText(/Cancellations for this event must be made before/);
  }

  /** Links to uploaded files (public image, other attachments) on the event page. */
  get fileLinks(): Locator {
    return this.page.locator('a[href^="/files/"], img[src^="/files/"]');
  }

  /** Attendee panel (only for admins / hosts). */
  get attendeeTabs(): Locator {
    return this.page.getByRole('tab');
  }

  async bodyText(): Promise<string> {
    return (await this.page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  }
}
