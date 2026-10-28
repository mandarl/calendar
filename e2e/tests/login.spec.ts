import { test, expect } from '@playwright/test';

import { testUsers } from './data/test-users';
import { EventsIndexPage } from './pages/events-index.page';
import { LoginPage } from './pages/login.page';

test('member can log in with LDAP credentials', async ({ page }) => {
  const loginPage = new LoginPage(page);
  const eventsIndex = new EventsIndexPage(page);

  await loginPage.goto();
  await expect(loginPage.heading).toBeVisible();

  await loginPage.loginAsMember(testUsers.member.username, testUsers.member.password);

  await expect(eventsIndex.heading).toBeVisible();
});
