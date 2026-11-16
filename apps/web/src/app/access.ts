import type { Role, SiteCode } from '@tanaad/domain';
import { SITE_NAV } from './nav';

const GROUP_ROLES: Role[] = ['ceo', 'group_admin'];
const matches = (rest: string, to: string) => rest === to || rest.startsWith(`${to}/`);

/**
 * May this session open this address? Driven by the same nav map that builds the menus, so a page that is not offered is not reachable by typing its URL.
 * `pathname` is the full path (/s/mats/cash or /g/audit). Unknown pages are allowed through so the router can say "not found".
 */
export function canOpenPath(pathname: string, session: { role: Role; siteId?: SiteCode } | null): boolean {
  if (!session) return false;
  const group = GROUP_ROLES.includes(session.role);
  const g = /^\/g(?:\/(.*))?$/.exec(pathname);
  if (g) {
    if (!group) return false;
    return true;
  }
  const m = /^\/s\/([^/]+)(?:\/(.*))?$/.exec(pathname);
  if (!m) return true;
  const [, site, rest = ''] = m;
  if (!group && session.siteId !== site) return false; // another site's pages
  const item = [...SITE_NAV].sort((a, b) => b.to.length - a.to.length).find((n) => matches(rest, n.to));
  if (!item) return true;
  if (item.roles && !item.roles.includes(session.role)) return false;
  if (item.sites && !item.sites.includes(site as SiteCode)) return false;
  return true;
}

/** Actions the server restricts to certain roles (mirrors each module's Authorizer). Offer the button only to those roles. */
export const ACTION_ROLES = {
  issuePart: ['storekeeper', 'supervisor'],
  recordPurchase: ['site_manager', 'storekeeper', 'ceo', 'group_admin'],
  receivePurchase: ['site_manager', 'storekeeper'],
} as const satisfies Record<string, readonly Role[]>;
export const mayDo = (action: keyof typeof ACTION_ROLES, role: Role | undefined) => !!role && (ACTION_ROLES[action] as readonly Role[]).includes(role);
