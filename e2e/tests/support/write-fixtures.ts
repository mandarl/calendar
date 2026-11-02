import type { Page } from '@playwright/test';

import { ANCHOR } from '../../fixtures/anchor';
import { testUsers, type RoleName } from '../data/test-users';
import { LoginPage } from '../pages/login.page';
import { currentDataset } from './dataset';
import { installExternalRouting, test as base } from './fixtures';
import { clearMail, resetFakes } from './io';

export { expect } from './fixtures';

/**
 * Write specs reset the whole dataset (`resetFor()` in a `beforeAll`) and then log in FRESH for every test.
 *
 * Why not the saved logins (`authFile(role)`): (1) the reset deletes the contacts rows that the setup project created
 * when it logged the users in, so a saved session would point at a contact that no longer exists; (2) the server queues
 * flash messages in the session, and write actions leave plenty of them behind. A fresh context per test cannot leak
 * either into another spec, and it never touches the shared sessions the read-only projects use.
 */
export type Who = RoleName | 'anonymous';

export interface Sessions {
  /** A new browser context (+ page) for `who`, logged in through the real login form. Closed after the test. */
  session: (who: Who) => Promise<Page>;
}

export const test = base.extend<Sessions>({
  session: async ({ browser }, use) => {
    const opened: { close(): Promise<void> }[] = [];
    await use(async (who) => {
      const context = await browser.newContext({
        baseURL: process.env.BASE_URL,
        viewport: { width: 1440, height: 900 },
        locale: 'en-US',
        timezoneId: 'America/Chicago',
      });
      opened.push(context);
      await installExternalRouting(context);
      // The date picker on the event form limits dates relative to the BROWSER clock; pin it to the app's anchor.
      await context.clock.setFixedTime(new Date(ANCHOR));
      const page = await context.newPage();
      if (who !== 'anonymous') {
        const login = new LoginPage(page);
        await login.goto();
        await login.loginAsMember(testUsers[who].username, testUsers[who].password);
        await login.expectLoggedIn();
      }
      return page;
    });
    for (const c of opened) await c.close();
  },
});

/** Pristine dataset + empty mail box + empty fake logs. Call from `test.beforeAll` of every write spec file. */
export async function resetFor(): Promise<void> {
  await currentDataset().load();
}

/** Forget mail and gateway/webhook calls recorded so far (keeps the database). */
export async function clearEffects(): Promise<void> {
  await clearMail();
  await resetFakes();
}
