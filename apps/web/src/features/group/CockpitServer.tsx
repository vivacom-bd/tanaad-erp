// Real mode: the cockpit tiles are the server's figures from GET /group/cockpit. A figure the server cannot give is a dash with the reason.
import { useEffect, useState, type ReactNode } from 'react';
import { Inbox, RefreshCw } from 'lucide-react';
import { useStore } from '@/data/store';
import { useGo } from '@/app/hooks';
import { Badge, Button, Empty, KpiStrip, PageHeader, Panel, Stat } from '@/components/ui';
import { compact, fmtDayLong, fmtKg, fmtNum } from '@/data/format';
import { cashNote, type CockpitSite } from '@/data/api/groupMap';
import { loadCockpit, useServerGroup } from '@/data/api/group';
import { SiteMark, plural, unitName } from './shared';

const Dash = ({ why }: { why: string }) => <span className="text-[var(--text-4)]" title={why}>—</span>;

interface Tile { id: string; label: string; value: ReactNode; sub: string; badge?: ReactNode; to?: 'approvals' | 'credit' | 'pnl' }

function tilesFor(s: CockpitSite, day: string): Tile[] {
  const unit = unitName(s.siteCode); const c = s.cash;
  return [
    { id: 'cash', label: `Cash in the safe, ${s.currency}`, value: c.asOf ? compact(c.inSafe) : <Dash why="No cash page yet" />, sub: cashNote(c, day), badge: c.variance !== null && c.variance !== 0 ? <Badge tone="warn" dot>Variance {fmtNum(c.variance)}</Badge> : undefined },
    { id: 'people', label: 'On shift today', value: fmtNum(s.headcount.total), sub: `${s.headcount.present} full · ${s.headcount.half} half day${s.headcount.absent ? ` · ${s.headcount.absent} absent` : ''}` },
    { id: 'output', label: `${unit[0].toUpperCase()}${unit.slice(1)} so far`, value: s.output.entries ? fmtNum(s.output.goodUnits) : <Dash why="Nothing has been logged today" />, sub: s.output.entries ? `${plural(s.output.entries, 'entry', 'entries')} · ${fmtKg(Math.round(s.output.outputKg))} out${s.output.rejectUnits ? ` · ${fmtNum(s.output.rejectUnits)} rejects` : ''}` : 'Nothing logged yet today', badge: s.output.belowBenchmark ? <Badge tone="warn" dot>{s.output.belowBenchmark} below benchmark</Badge> : undefined },
    { id: 'dispatch', label: 'Dispatches open', value: fmtNum(s.dispatches.pending + s.dispatches.held + s.dispatches.inTransit), sub: `${s.dispatches.pending} waiting at the gate · ${s.dispatches.inTransit} on the road`, badge: s.dispatches.held ? <Badge tone="bad" dot>{s.dispatches.held} held</Badge> : undefined },
    { id: 'exceptions', label: 'To decide', value: fmtNum(s.exceptions.total), sub: s.exceptions.total ? [`${s.exceptions.critical} critical`, `${s.exceptions.warning} warning`, `${s.exceptions.info} info`].filter((x) => !x.startsWith('0 ')).join(' · ') : 'All clear', badge: s.exceptions.held ? <Badge tone="warn" dot>{s.exceptions.held} held</Badge> : undefined, to: 'approvals' },
    { id: 'machines', label: 'Machines down', value: fmtNum(s.machinesDown.count), sub: s.machinesDown.count ? s.machinesDown.machines.join(', ') : 'None reported' },
    { id: 'stock', label: 'Stock on hand', value: fmtNum(s.stock.bundles), sub: `${fmtKg(Math.round(s.stock.kg))} · ${plural(s.stock.products, 'product')}` },
    s.receivables
      ? { id: 'recv', label: `Receivables, ${s.currency}`, value: compact(s.receivables.open), sub: plural(s.receivables.count, 'open invoice'), to: 'credit' }
      : { id: 'recv', label: 'Receivables', value: <Dash why="The server has no invoicing figures for this site" />, sub: 'No invoicing figures for this site' },
  ];
}

export function CockpitServer() {
  const go = useGo(); const today = useStore((s) => s.today);
  const c = useServerGroup((s) => s.cockpit); const loaded = useServerGroup((s) => s.cockpitLoaded);
  const [busy, setBusy] = useState(false);
  const refresh = async () => { setBusy(true); try { await loadCockpit(); } catch { /* shown as unavailable */ } finally { setBusy(false); } };
  useEffect(() => { void refresh(); const t = setInterval(() => { void loadCockpit().catch(() => undefined); }, 60_000); return () => clearInterval(t); }, []);
  const total = c?.sites.reduce((n, x) => n + x.exceptions.total, 0) ?? 0; const critical = c?.sites.reduce((n, x) => n + x.exceptions.critical, 0) ?? 0;
  return (
    <div className="flex flex-col gap-[var(--gap)]">
      <PageHeader title="Group overview" subtitle={c ? `All sites · ${fmtDayLong(c.day || today)}` : 'All sites'}
        actions={<><Button icon={RefreshCw} disabled={busy} onClick={refresh}>{busy ? 'Refreshing' : 'Refresh'}</Button><Button variant="primary" icon={Inbox} onClick={() => go.group('approvals')}>Approvals{total > 0 && <span className="num t-xs font-semibold opacity-80">{total}</span>}</Button></>} />
      {!c ? <Panel><Empty title={loaded ? 'The overview is not available' : 'Loading the overview'} hint={loaded ? 'Only the CEO and the group admin can read it, or the server could not be reached.' : undefined} /></Panel> : (<>
        <KpiStrip>
          <Stat label="To decide" tone={critical ? 'bad' : total ? 'warn' : 'ok'} value={fmtNum(total)} sub={critical ? `${critical} critical` : total ? 'Nothing critical' : 'All clear'} onClick={() => go.group('approvals')} />
          <Stat label="On shift today" value={fmtNum(c.sites.reduce((n, x) => n + x.headcount.total, 0))} sub={plural(c.sites.length, 'site')} />
          <Stat label="Machines down" tone={c.sites.some((x) => x.machinesDown.count) ? 'warn' : 'neutral'} value={fmtNum(c.sites.reduce((n, x) => n + x.machinesDown.count, 0))} sub="across the group" />
          <Stat label="Held at the gate" tone={c.sites.some((x) => x.dispatches.held) ? 'bad' : 'neutral'} value={fmtNum(c.sites.reduce((n, x) => n + x.dispatches.held, 0))} sub="dispatches" />
        </KpiStrip>
        <div className="grid gap-[var(--gap)] xl:grid-cols-3 items-start">
          {c.sites.map((s, i) => (
            <Panel key={s.siteId} pad={false} title={<span className="inline-flex items-center gap-2"><SiteMark site={s.siteCode} size={24} />{s.name}</span>}
              actions={<Button size="sm" onClick={() => go.site('dashboard', s.siteCode)} hotkey={String(i + 1)} hotkeyLabel={`Open the ${s.name} portal`}>Open portal</Button>}>
              {tilesFor(s, c.day).map((t) => (
                <button key={t.id} disabled={!t.to} onClick={() => t.to && go.group(t.to)} className="w-full min-w-0 flex items-center gap-2.5 px-[var(--pad)] py-1.5 touch:py-2 text-left border-b border-[var(--line)] last:border-b-0 enabled:hover:bg-[var(--hover)] disabled:cursor-default">
                  <span className="min-w-0 flex-1"><span className="block t-sm font-medium text-[var(--text-2)] truncate">{t.label}</span><span className="block t-xs text-[var(--text-3)] truncate">{t.sub}</span></span>
                  <span className="shrink-0 min-w-[62px] flex flex-col items-end gap-0.5"><span className="num t-lg font-semibold leading-tight">{t.value}</span>{t.badge}</span>
                </button>
              ))}
            </Panel>
          ))}
        </div>
      </>)}
    </div>
  );
}
