import { test, expect } from '../support/fixtures';
import { authFile, authRoles, testUsers, type RoleName } from '../data/test-users';
import { NavComponent } from '../components/nav.component';
import { EventsIndexPage } from '../pages/events-index.page';
import { LoginPage } from '../pages/login.page';

// NOTE: tests must never log out a shared storageState user (see 'logout ends the session').

test.describe('login', () => {
  test('wrong password is rejected with a message and stays on the login form', async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.submitCredentials(testUsers.member.username, 'not-the-password');
    await expect(login.heading).toBeVisible();
    // Characterise the current wording; a rebuilt app is compared against it.
    expect(((await login.flash.textContent()) ?? '').replace(/\s+/g, ' ').trim()).toMatchSnapshot('login-failure-message.txt');
  });

  // Uses its OWN login: logging out destroys the server-side session, which would break every spec that shares the
  // saved storageState for the same user.
  test('logout ends the session', async ({ page }) => {
    const login = new LoginPage(page);
    const nav = new NavComponent(page);
    await login.goto();
    await login.loginAsMember(testUsers.member.username, testUsers.member.password);
    await login.expectLoggedIn();
    await nav.logout();
    await expect(nav.accountMenu).toBeHidden();
  });
});

// Navigation menu per role. The full link list is stored as a golden (diffed against a rebuilt app);
// the explicit assertions below capture the intent.
const navRoles: (RoleName | 'anonymous')[] = ['anonymous', ...authRoles];
const expectedExtraMenus: Record<RoleName | 'anonymous', string[]> = {
  anonymous: [],
  member: [],
  admin: ['Admin', 'Financials', 'Honoraria', 'Super Calendar Admin'], // user2 is in every admin group
  calendarAdmin: ['Admin'],
  honorariumAdmin: ['Honoraria'],
  financialReporting: ['Financials'],
  superAdmin: ['Super Calendar Admin'],
};
for (const role of navRoles) {
  test.describe(`nav as ${role}`, () => {
    if (role !== 'anonymous') test.use({ storageState: authFile(role) });

    test(`menu items for ${role}`, async ({ page }) => {
      const index = new EventsIndexPage(page);
      const nav = new NavComponent(page);
      await index.goto();
      const links = await nav.linkNames();
      const buttons = await nav.menuButtonNames();
      expect(JSON.stringify({ buttons, links }, null, 2)).toMatchSnapshot(`nav-${role}.json`);

      expect(links).toContain('Submit Event');
      if (role === 'anonymous') {
        expect(buttons).not.toContain('My Account');
        expect(buttons).not.toContain('Admin');
      } else {
        expect(buttons).toContain('My Account');
        expect(links).toContain('Logout');
      }
      // Each admin group unlocks exactly one extra menu (AppController hasAdminMenu / hasFinancialMenu / ...).
      const extraMenus = buttons.filter((b) => !['My Account', 'Popular Links'].includes(b));
      expect(extraMenus).toEqual(expectedExtraMenus[role]);
    });
  });
}
