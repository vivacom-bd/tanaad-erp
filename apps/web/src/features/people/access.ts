import type { Role } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { isReal } from '@/data/api/sync';

/** What the signed-in role may do on the people screens. Mirrors the server's PeopleAuthorizer so a button is not offered when the server would refuse it. */
export interface PeopleAccess { mark: boolean; renew: boolean; end: boolean; archive: boolean; createRun: boolean; approveRun: boolean; pay: boolean }

const OPEN: PeopleAccess = { mark: true, renew: true, end: true, archive: true, createRun: true, approveRun: true, pay: true };
const MANAGE: Role[] = ['site_manager', 'group_admin'];

/** In demo mode every role keeps the full prototype behaviour; in real mode the server's matrix applies. */
export function peopleAccess(role: Role | undefined, real: boolean): PeopleAccess {
  if (!real) return OPEN;
  const is = (...r: Role[]) => !!role && r.includes(role);
  return {
    mark: is('supervisor', ...MANAGE),
    renew: is(...MANAGE),
    end: is(...MANAGE),
    archive: is('ceo', ...MANAGE),
    createRun: is(...MANAGE),
    approveRun: is('ceo', ...MANAGE),
    pay: is('cashier', ...MANAGE),
  };
}

export function usePeopleAccess(): PeopleAccess {
  const role = useStore((s) => s.session?.role);
  return peopleAccess(role, isReal());
}
