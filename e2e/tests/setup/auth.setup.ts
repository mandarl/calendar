import { mkdirSync } from 'node:fs';

import { test as setup } from '../support/fixtures';
import { AUTH_DIR, authFile, authRoles, namespacedSessions, testUsers } from '../data/test-users';
import { LoginPage } from '../pages/login.page';

// One real UI login per role. Sessions are regenerated every run and never reused across builds.
mkdirSync(AUTH_DIR, { recursive: true });

// Serial and in a fixed order ON PURPOSE: the app creates a contacts row the first time a user logs in, so parallel
// logins would assign contact ids in a race and make /contacts (and anything listing contacts) differ run to run.
setup.describe.configure({ mode: 'serial' });

for (const role of authRoles) {
  setup(`authenticate as ${role}`, async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.loginAsMember(testUsers[role].username, testUsers[role].password);
    await login.expectLoggedIn();
    await page.context().storageState({ path: authFile(role) });
  });
}

// Separate logins (= separate server-side sessions) for specs that provoke failures; see authFile().
for (const { ns, role } of namespacedSessions) {
  setup(`authenticate as ${role} (${ns} session)`, async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.loginAsMember(testUsers[role].username, testUsers[role].password);
    await login.expectLoggedIn();
    await page.context().storageState({ path: authFile(role, ns) });
  });
}
