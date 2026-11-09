import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { ArrowRight, Building2, Factory, LogOut, Moon, Recycle, ShoppingBag, Sun, type LucideIcon } from 'lucide-react';
import type { Role, SiteCode } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { usePrefs } from '@/app/prefs';
import { SITES } from '@/config/sites';
import { roleLabel } from '@/config/roles';
import { Avatar, cn } from '@/components/ui';
import { attendanceFor, openExceptions, portalsFor } from '@/data/selectors';
import { fmtDayLong, fmtNum } from '@/data/format';
import { Logo } from './BrandPanel';

type PortalId = 'group' | SiteCode;
const ICON: Record<PortalId, LucideIcon> = { group: Building2, mats: Factory, bags: ShoppingBag, recycling: Recycle };
const COLOR: Record<PortalId, string> = { group: 'var(--site-group)', mats: 'var(--site-mats)', bags: 'var(--site-bags)', recycling: 'var(--site-recycling)' };

/** Opens only workspaces assigned to the signed-in person. */
export function Portals() {
  const s = useStore(); const nav = useNavigate();
  const theme = usePrefs((p) => p.theme); const toggleTheme = usePrefs((p) => p.toggleTheme);
  const dark = theme === 'dark';
  const userId = s.auth?.userId ?? s.session?.userId ?? '';
  const access = useMemo(() => portalsFor(s, userId), [s.users, userId]); // eslint-disable-line react-hooks/exhaustive-deps
  const user = access.user;
  const [rolePick, setRolePick] = useState<Partial<Record<SiteCode, Role>>>({});

  const cards = useMemo(() => {
    const out: { id: PortalId; name: string; description: string; roles: Role[] }[] = [];
    if (access.group && access.groupRole) {
      out.push({
        id: 'group',
        name: 'All sites',
        description: 'Group overview and decisions',
        roles: [access.groupRole],
      });
    }
    for (const p of access.sites) {
      if (p.roles.length === 0) continue;
      out.push({
        id: p.id,
        name: SITES[p.id].name,
        description: p.id === 'mats' ? 'Mats operation' : p.id === 'bags' ? 'Tanaad Packages Ltd' : 'HDPE recycling operation',
        roles: p.roles,
      });
    }
    return out;
  }, [access]);

  const open = (id: PortalId) => {
    const card = cards.find((c) => c.id === id); if (!card || !user) return;
    if (id === 'group') { s.signIn(user.id, card.roles[0]); nav({ to: '/g/cockpit' as never }); return; }
    const role = rolePick[id] ?? card.roles[0];
    s.signIn(user.id, role, id);
    nav({ to: `/s/${id}/${role === 'ceo' || role === 'group_admin' || role === 'site_manager' ? 'dashboard' : 'today'}` as never });
  };

  useEffect(() => { if (!user) nav({ to: '/' }); }, [user, nav]);

  if (!user) return null;

  return (
    <div className={cn('h-dvh overflow-auto', dark ? 'bg-[#0c1418] text-slate-100' : 'bg-[var(--bg)] text-[var(--text)]')} data-theme={theme}>
      <div className="max-w-[1120px] mx-auto px-5 sm:px-8 py-6 min-h-full">
        <header className={cn('flex items-center justify-between pb-5 mb-7 border-b', dark ? 'border-white/10' : 'border-[var(--line)]')}>
          <Logo onDark={dark} size={32} />
          <div className="flex items-center gap-3">
            <div className="hidden sm:block text-right leading-tight">
              <div className={cn('t-base font-medium', dark ? 'text-white' : undefined)}>{user.name}</div>
              <div className={cn('t-xs', dark ? 'text-slate-400' : 'text-[var(--text-3)]')}>{roleLabel(user.roles[0]?.role ?? 'supervisor')}</div>
            </div>
            <Avatar name={user.name} seed={user.avatarSeed} size={32} />
            <button
              type="button"
              onClick={toggleTheme}
              aria-label={dark ? 'Switch to light theme' : 'Switch to dark theme'}
              className={cn('size-8 grid place-items-center rounded-md border', dark ? 'border-white/15 bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' : 'border-[var(--line-2)] bg-[var(--surface)] text-[var(--text-2)] hover:bg-[var(--hover)]')}
            >
              {dark ? <Sun className="size-3.5" /> : <Moon className="size-3.5" />}
            </button>
            <button
              onClick={() => { s.signOut(); nav({ to: '/' }); }}
              className={cn('ctl px-3 rounded-md border t-sm font-medium inline-flex items-center gap-1.5', dark ? 'border-white/15 bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white' : 'border-[var(--line-2)] bg-[var(--surface)] hover:bg-[var(--hover)]')}
            >
              <LogOut className="size-3" />
              Sign out
            </button>
          </div>
        </header>

        <div className="mb-6">
          <div className={cn('t-label mb-1.5', dark ? 'text-[var(--color-amber-400)]' : undefined)}>Workspaces</div>
          <h1 className={cn('text-[28px] leading-tight font-semibold tracking-[-0.02em]', dark ? 'text-white' : undefined)}>Choose where to work</h1>
          <p className={cn('t-base mt-1.5', dark ? 'text-slate-400' : 'text-[var(--text-2)]')}>Demo data · {fmtDayLong(s.today)} · only assigned workspaces are shown.</p>
        </div>

        <div className={cn('grid gap-4', cards.length > 1 ? 'sm:grid-cols-2' : 'max-w-[560px]')}>
          {cards.map((c) => {
            const Icon = ICON[c.id]; const col = COLOR[c.id];
            const sites: SiteCode[] = c.id === 'group' ? ['mats', 'bags', 'recycling'] : [c.id];
            const attendance = sites.reduce((total, x) => total + s.shifts.filter((shift) => shift.siteId === x && shift.day === s.today).reduce((n, shift) => n + attendanceFor(s, shift.id).length, 0), 0);
            const ex = sites.reduce((t, x) => t + openExceptions(s, x).length, 0);
            const selectedRole = c.id === 'group' ? c.roles[0] : rolePick[c.id] ?? c.roles[0];

            return (
              <article key={c.id} className={cn('relative overflow-hidden rounded-xl border p-5 flex flex-col', dark ? 'border-[#203642] bg-[#121f26]' : 'border-[var(--line)] bg-[var(--surface)] shadow-[var(--shadow-tile)]')}>
                <div className="flex items-start gap-3">
                  <span className="size-10 rounded-lg grid place-items-center text-white shrink-0" style={{ background: col }}>
                    <Icon className="size-4.5" strokeWidth={2.2} />
                  </span>
                  <div className="min-w-0 flex-1">
                    <h2 className={cn('t-lg font-semibold', dark ? 'text-white' : undefined)}>{c.name}</h2>
                    <p className={cn('t-sm mt-0.5', dark ? 'text-slate-400' : 'text-[var(--text-3)]')}>{c.description}</p>
                  </div>
                </div>

                <dl className={cn('grid grid-cols-2 gap-3 mt-5 p-3.5 rounded-lg border', dark ? 'bg-black/25 border-white/5' : 'bg-[var(--surface-2)] border-[var(--line)]')}>
                  <div><dt className={cn('t-xs', dark ? 'text-slate-400' : 'text-[var(--text-3)]')}>Attendance records today</dt><dd className={cn('t-kpi mt-1', dark ? 'text-white' : undefined)}>{fmtNum(attendance)}</dd></div>
                  <div><dt className={cn('t-xs', dark ? 'text-slate-400' : 'text-[var(--text-3)]')}>Open exceptions</dt><dd className={cn('t-kpi mt-1', dark ? 'text-white' : undefined)}>{fmtNum(ex)}</dd></div>
                </dl>

                {c.id !== 'group' && c.roles.length > 1 && (
                  <div className="flex flex-wrap items-center gap-1.5 mt-4">
                    <span className={cn('t-xs mr-1', dark ? 'text-slate-400' : 'text-[var(--text-3)]')}>Open as</span>
                    {c.roles.map((r) => {
                      const on = selectedRole === r;
                      return (
                        <button
                          key={r}
                          onClick={() => setRolePick((p) => ({ ...p, [c.id]: r }))}
                          className={cn('ctl-sm px-2.5 rounded-md border t-xs', on
                            ? (dark ? 'bg-white text-slate-900 border-white font-semibold' : 'bg-[var(--brand)] text-[var(--brand-contrast)] border-[var(--brand)]')
                            : (dark ? 'border-white/15 text-slate-400 hover:text-white hover:bg-white/5' : 'border-[var(--line-2)] bg-[var(--surface)]'))}
                        >
                          {roleLabel(r)}
                        </button>
                      );
                    })}
                  </div>
                )}

                <div className={cn('mt-4 pt-4 border-t flex items-center justify-between gap-3', dark ? 'border-white/10' : 'border-[var(--line)]')}>
                  <span className={cn('t-xs', dark ? 'text-slate-400' : 'text-[var(--text-3)]')}>{roleLabel(selectedRole)}</span>
                  <button onClick={() => open(c.id)} className={cn('ctl px-3 rounded-md t-sm font-semibold inline-flex items-center justify-center gap-1.5', dark ? 'bg-white text-slate-900 hover:bg-slate-100' : 'bg-[var(--brand)] text-[var(--brand-contrast)]')}>
                    Open workspace <ArrowRight className="size-3.5" />
                  </button>
                </div>
              </article>
            );
          })}
        </div>
      </div>
    </div>
  );
}
