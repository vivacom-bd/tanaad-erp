import { expect, loginAs, openFirstPortal, test, visitPrimaryNav } from '../../support/helpers';

// One smoke per role: sign in through the real UI, open the portal, walk the primary navigation.
// Add more usernames here (seeded by DemoUsersSeeder): smmats, suprec, cashrec, storerec, storebags, smbags, supbags, ...
const ROLES = [
  { username: 'supmats', label: 'Supervisor, Mats' },
  { username: 'cashmats', label: 'Cashier, Mats' },
  { username: 'ceo1', label: 'CEO' },
];

for (const r of ROLES) {
  test(`smoke: ${r.label} (${r.username}) signs in and opens the primary nav`, async ({ page, w }) => {
    await loginAs(page, r.username);
    await openFirstPortal(page);
    const visited = await visitPrimaryNav(page);
    expect(visited.length, 'primary nav links visited').toBeGreaterThan(0);
    w.assertClean();
  });
}
