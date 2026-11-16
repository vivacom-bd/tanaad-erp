import { Link } from '@tanstack/react-router';
import { Lock } from 'lucide-react';
import { useStore } from '@/data/store';

/** Shown in place of a page the signed-in role may not open. */
export function NoAccess() {
  const session = useStore((s) => s.session);
  const home = session?.siteId ? `/s/${session.siteId}/today` : '/g/cockpit';
  return (
    <div className="max-w-md mx-auto text-center py-16" role="alert">
      <Lock className="size-8 mx-auto text-[var(--text-3)]" />
      <h1 className="t-lg font-semibold mt-3">You don't have access to this page</h1>
      <p className="t-sm text-[var(--text-3)] mt-1">Your role does not include this screen. Ask your site manager if you need it.</p>
      <div className="flex justify-center gap-2 mt-5">
        <Link to={home as never} className="inline-flex items-center h-9 px-3 rounded-md border border-[var(--line-2)] t-base">Go to my home page</Link>
        <Link to={'/portals' as never} className="inline-flex items-center h-9 px-3 rounded-md border border-[var(--line-2)] t-base">All portals</Link>
      </div>
    </div>
  );
}
