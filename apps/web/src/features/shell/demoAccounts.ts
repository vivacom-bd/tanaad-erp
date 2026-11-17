/**
 * Accounts the dev server's DemoUsersSeeder creates (password "password", PIN 1234). Used only by the "Use a demo account"
 * picker, which exists only in builds made with VITE_DEMO_ACCOUNTS=1. A real build has no user list before sign-in.
 */
export interface DemoAccount { username: string; name: string; role: string; where: string }

export const DEMO_ACCOUNTS_LIST: DemoAccount[] = [
  { username: 'ceo1', name: 'Hassan Warsame', role: 'CEO', where: 'Group' },
  { username: 'ceo2', name: 'Abdi Osman', role: 'CEO', where: 'Group' },
  { username: 'admin', name: 'Yasir Osman', role: 'Group admin', where: 'Group' },
  { username: 'smmats', name: 'Moses Tumusiime', role: 'Site manager', where: 'Mats' },
  { username: 'smbags', name: 'Grace Nabirye', role: 'Site manager', where: 'Bags' },
  { username: 'smrec', name: 'Ibrahim Farah', role: 'Site manager', where: 'Recycling' },
  { username: 'supmats', name: 'Amina Nakato', role: 'Supervisor', where: 'Mats' },
  { username: 'supbags', name: 'Muzamir Ssentongo', role: 'Supervisor', where: 'Bags' },
  { username: 'suprec', name: 'Said Elmi', role: 'Supervisor', where: 'Recycling' },
  { username: 'cashmats', name: 'Joseph Okello', role: 'Cashier', where: 'Mats' },
  { username: 'cashbags', name: 'Esther Namuli', role: 'Cashier', where: 'Bags' },
  { username: 'cashrec', name: 'Halima Jama', role: 'Cashier', where: 'Recycling' },
  { username: 'storemats', name: 'Peter Mukasa', role: 'Storekeeper + Gate', where: 'Mats' },
  { username: 'storebags', name: 'Ronald Kizza', role: 'Storekeeper + Gate', where: 'Bags' },
  { username: 'storerec', name: 'Omar Hersi', role: 'Storekeeper + Gate', where: 'Recycling' },
  { username: 'mech', name: 'Kato Mugisha', role: 'Mechanic', where: 'Mats + Recycling' },
  { username: 'salesmats', name: 'Fatuma Ali', role: 'Sales', where: 'Mats' },
  { username: 'salesbags', name: 'Brian Lubega', role: 'Sales', where: 'Bags' },
  { username: 'salesrec', name: 'Nasra Mohamed', role: 'Sales', where: 'Recycling' },
];
