// Who may READ what, per site. Mirrors the backend's read roles (each module's Snapshots::register roles and Authorizer::canRead),
// so the sync never asks for data the signed-in role cannot see (a 403 on every poll). The server stays the authority:
// the existing 403 tolerance in each fetch remains as a safety net. Group roles (ceo, group_admin) read everything.
// null = any member of the site.
import type { SiteCode } from '@tanaad/domain';
import type { RoleGrant } from './types';

const SALES = ['sales', 'site_manager'];
const STOCK_READ = ['storekeeper', 'supervisor', 'site_manager'];
const PRODUCTION_READ = ['supervisor', 'site_manager'];

export const READ_ROLES = {
  // Snapshots (apps/api/modules/*/…ServiceProvider.php)
  people: null, engagements: null, attendance: null, cash_days: null, cash_entries: null, exceptions: null,
  collector_ledger: ['supervisor', 'site_manager', 'cashier'], // the money owed; narrower than the day's weigh-ins
  collector_deliveries: ['supervisor', 'site_manager', 'gate', 'storekeeper', 'cashier'],
  production_entries: PRODUCTION_READ, production_shifts_closed: PRODUCTION_READ, waste_weighings: PRODUCTION_READ, production_batches: PRODUCTION_READ,
  labels: ['supervisor', 'storekeeper', 'site_manager'],
  stock_on_hand: STOCK_READ,
  purchases: ['site_manager', 'cashier', 'storekeeper'],
  parts: ['mechanic', 'storekeeper', 'site_manager'], part_issues: ['mechanic', 'storekeeper', 'site_manager'], breakdowns: ['mechanic', 'site_manager', 'supervisor'],
  clients: [...SALES, 'cashier'], orders: [...SALES, 'cashier', 'storekeeper'], invoices: [...SALES, 'cashier'], payments: [...SALES, 'cashier'],
  // Views (controllers)
  dispatches: ['gate', 'storekeeper', 'supervisor', 'site_manager', 'driver'],
  stock_views: STOCK_READ, // stock/movements, stock/transfers, stock/count-variance
  production_views: PRODUCTION_READ, // mass-balance, machine-wall, production/output
  costing: ['site_manager'],
} as const satisfies Record<string, readonly string[] | null>;

export type Readable = keyof typeof READ_ROLES;
const GROUP = new Set(['ceo', 'group_admin']);

/** May a user holding these grants read `what` at `site`? */
export function canRead(roles: readonly RoleGrant[], site: SiteCode, what: Readable | string): boolean {
  if (roles.some((r) => GROUP.has(r.role))) return true;
  const need = (READ_ROLES as Record<string, readonly string[] | null>)[what];
  if (need === undefined) return true; // unknown: ask, and let the 403 safety net answer
  const held = roles.filter((r) => r.site_code === site).map((r) => r.role as string);
  if (held.length === 0) return false;
  return need === null || held.some((r) => need.includes(r));
}
