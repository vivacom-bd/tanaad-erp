// Real-mode costing: the server's P&L and unit costs, with a Provisional badge while a period is open, and close/reopen for the people who may.
// Figures come straight from the API; where one is unavailable the screen shows a dash and says why.
import { Fragment, useCallback, useEffect, useState, type ReactNode } from 'react';
import { ChevronLeft, ChevronRight, Lock, RotateCcw } from 'lucide-react';
import type { SiteCode } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { useRefList } from '@/app/hooks';
import { Badge, Button, DataGrid, Empty, Field, Input, KpiStrip, Note, PageHeader, Panel, Sheet, Stat, cn, type Column } from '@/components/ui';
import { SITE_LIST } from '@/config/sites';
import { fmtNum } from '@/data/format';
import { sessionSites } from '@/data/api/sync';
import { canCloseCosting, canReadGroupViews, closePeriod, loadGroupPnl, loadSiteCosting, pnlKey, reopenPeriod, useServerGroup, writeError } from '@/data/api/group';
import { monthOf, shiftMonth, unitCostGap, type Pnl, type UnitCostRow } from '@/data/api/groupMap';
import { SiteTag, usd0 } from './kit';

const money = (v: number | null | undefined, why?: string) => (v === null || v === undefined ? <span className="text-[var(--text-4)]" title={why}>—</span> : <span className="num">{fmtNum(Math.round(v))}</span>);
const pct = (v: number, has: boolean) => (has ? `${v.toFixed(1)}%` : '—');
const titleize = (k: string) => { const t = k.replace(/_/g, ' '); return t[0].toUpperCase() + t.slice(1); };
const monthLabel = (m: string) => new Date(`${m}-01T00:00:00Z`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });

export function MonthStepper({ month, onChange, max }: { month: string; onChange: (m: string) => void; max: string }) {
  return (
    <span className="inline-flex items-center gap-1">
      <Button size="sm" icon={ChevronLeft} aria-label="Previous month" onClick={() => onChange(shiftMonth(month, -1))} />
      <span className="num t-base font-medium min-w-[120px] text-center">{monthLabel(month)}</span>
      <Button size="sm" icon={ChevronRight} aria-label="Next month" disabled={month >= max} onClick={() => onChange(shiftMonth(month, 1))} />
    </span>
  );
}

export const BasisBadge = ({ p }: { p: Pnl }) => (p.provisional
  ? <Badge tone="warn" dot>{p.status === 'closed' ? 'Provisional' : `Provisional · ${p.openDays} of ${p.daysInRange} days not closed`}</Badge>
  : <Badge tone="ok" dot><Lock className="size-3" />Closed{p.version > 1 ? ` · version ${p.version}` : ''}</Badge>);

/** Close or reopen one site's month. Reopen asks for a reason; the server's refusal is shown as it is worded. */
export function PeriodActions({ site, month, p, onDone }: { site: SiteCode; month: string; p: Pnl | null | undefined; onDone?: () => void }) {
  const toast = useStore((s) => s.toast);
  const [busy, setBusy] = useState(false); const [reopen, setReopen] = useState(false); const [reason, setReason] = useState(''); const [err, setErr] = useState('');
  if (!canCloseCosting() || !p) return null;
  const closed = p.status === 'closed';
  const run = async (fn: () => Promise<unknown>, done: string) => {
    setBusy(true); setErr('');
    try { await fn(); toast({ kind: 'ok', title: done, detail: `${SITE_LIST.find((x) => x.id === site)?.shortName ?? site} · ${monthLabel(month)}` }); setReopen(false); setReason(''); onDone?.(); }
    catch (e) { const m = writeError(e); setErr(m); toast({ kind: 'warn', title: 'Not done', detail: m }); }
    finally { setBusy(false); }
  };
  return (
    <>
      {closed
        ? <Button size="sm" icon={RotateCcw} disabled={busy} onClick={() => setReopen(true)}>Reopen</Button>
        : <Button size="sm" variant="primary" icon={Lock} disabled={busy} onClick={() => run(() => closePeriod(site, month), 'Period closed')}>{busy ? 'Closing' : 'Close period'}</Button>}
      {err && !reopen && <span role="alert" className="t-xs text-[var(--bad)]">{err}</span>}
      <Sheet open={reopen} onClose={() => setReopen(false)} title="Reopen this period" sub={`${monthLabel(month)} · closed figures stay in the record`}
        footer={<><Button variant="ghost" onClick={() => setReopen(false)}>Cancel</Button><Button variant="primary" disabled={busy || !reason.trim()} onClick={() => run(() => reopenPeriod(site, month, reason), 'Period reopened')}>{busy ? 'Reopening' : 'Reopen period'}</Button></>}>
        <div className="flex flex-col gap-3">
          <Field label="Why is it being reopened?" required><Input value={reason} maxLength={1000} onChange={(e) => setReason(e.target.value)} placeholder="For example, a late invoice was found" /></Field>
          <p className="t-sm text-[var(--text-3)]">The reason is written to the audit feed with your name. The period shows as provisional until it is closed again.</p>
          {err && <div role="alert" className="t-sm text-[var(--bad)]">{err}</div>}
        </div>
      </Sheet>
    </>
  );
}

export function useCostingMonth() {
  const today = useStore((s) => s.today); const max = monthOf(today);
  const [month, setMonth] = useState(max);
  return { month, setMonth, max };
}

export function UnitCostTable({ rows, p, emptyHint }: { rows: (UnitCostRow & { site?: SiteCode; provisional?: boolean })[]; p?: Pnl | null; emptyHint?: string }) {
  const cols: Column<(typeof rows)[number]>[] = [
    { key: 'p', header: 'Product', sticky: true, sort: (c) => c.product, cell: (c) => <span className="font-medium">{c.product}</span> },
    ...(rows.some((r) => r.site) ? [{ key: 's', header: 'Site', sort: (c: (typeof rows)[number]) => c.site ?? '', cell: (c: (typeof rows)[number]) => (c.site ? <SiteTag site={c.site} /> : null) }] : []),
    { key: 'u', header: 'Units', align: 'right', mono: true, sort: (c) => c.units, cell: (c) => <>{fmtNum(c.units)}<span className="t-xs text-[var(--text-3)]"> {c.basis === 'kg' ? 'kg' : 'units'}</span></> },
    { key: 'm', header: 'Material', align: 'right', mono: true, sort: (c) => c.material, cell: (c) => fmtNum(c.material) },
    { key: 'o', header: 'Overhead', align: 'right', mono: true, sort: (c) => c.overhead, cell: (c) => fmtNum(c.overhead) },
    { key: 't', header: 'Unit cost', align: 'right', mono: true, sort: (c) => c.total, cell: (c) => <span className="font-semibold">{fmtNum(c.total)}</span> },
    { key: 'pr', header: 'Price', align: 'right', mono: true, sort: (c) => c.priceUgx, cell: (c) => fmtNum(Math.round(c.priceUgx)) },
    { key: 'mg', header: 'Margin', align: 'right', mono: true, sort: (c) => c.marginPct, cell: (c) => <span className={cn('font-semibold', c.marginPct < 0 && 'text-[var(--bad)]')}>{c.marginPct.toFixed(0)}%</span> },
    { key: 'b', header: 'Basis', cell: (c) => (c.provisional ?? p?.provisional ? <Badge tone="warn" dot>Provisional</Badge> : <Badge tone="ok" dot>Closed</Badge>) },
  ];
  return <DataGrid bare rows={rows} columns={cols} rowKey={(c) => `${c.site ?? ''}${c.productId}`} empty={emptyHint ?? (p ? unitCostGap(p) : 'No unit costs for this period.')} />;
}

/** Group finance, CEO and group admin: every site side by side from GET /costing/pnl, with per-site close and reopen. */
export function ServerPnl() {
  const { month, setMonth, max } = useCostingMonth();
  const sg = useServerGroup(); const g = sg.groupPnl[month];
  const refs = useRefList('expense_category');
  const [loading, setLoading] = useState(true); const [failed, setFailed] = useState(false);
  const sites = sessionSites();
  const load = useCallback(async () => {
    setLoading(true); setFailed(false);
    try { const ok = await loadGroupPnl(month); await Promise.all(sites.map((x) => loadSiteCosting(x, month))); if (!ok) setFailed(true); } catch { setFailed(true); } finally { setLoading(false); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [month]);
  useEffect(() => { void load(); }, [load]);

  const label = (k: string) => refs.find((r) => r.id === k)?.label ?? titleize(k);
  const cats = g ? [...new Set([...Object.keys(g.group.byCategory), ...g.sites.flatMap((x) => Object.keys(x.costs.byCategory))])].sort() : [];
  const unitRows = sites.flatMap((x) => (sg.sitePnl[pnlKey(x, month)]?.unitCosts ?? []).map((u) => ({ ...u, site: x, provisional: sg.sitePnl[pnlKey(x, month)]!.provisional })));
  const th = 'px-2.5 h-9 t-xs font-semibold text-[var(--text-3)] uppercase tracking-[0.04em] whitespace-nowrap border-b border-[var(--line)] bg-[var(--surface-2)] text-right';
  const td = 'px-2.5 py-1.5 align-middle text-right border-b border-[var(--line)]';
  const lead = 'sticky left-0 z-[1] bg-[var(--surface)] px-[var(--pad)] py-1.5 text-left border-b border-[var(--line)] whitespace-nowrap';
  const noRate = 'No exchange rate is set for this month';
  const row = (name: ReactNode, f: (p: Pnl) => ReactNode, total: ReactNode, usd?: ReactNode, bold?: boolean) => (
    <tr><td className={cn(lead, bold && 'font-semibold')}>{name}</td>{g!.sites.map((x) => <td key={x.siteCode} className={cn(td, bold && 'font-semibold')}>{f(x)}</td>)}<td className={cn(td, bold && 'font-semibold')}>{total}</td><td className={td}>{usd ?? ''}</td></tr>);

  return (
    <div className="flex flex-col gap-[var(--gap)]">
      <PageHeader title="P&L and unit costs" subtitle={g ? `All sites · ${monthLabel(month)} · UGX` : monthLabel(month)}
        meta={g ? (g.provisional ? <Badge tone="warn" dot>Provisional</Badge> : <Badge tone="ok" dot><Lock className="size-3" />Closed</Badge>) : undefined}
        actions={<MonthStepper month={month} onChange={setMonth} max={max} />} />
      <Note tone="neutral">P&L and unit costs are worked out by the server from closed and signed cash days. While any day in a period is not closed, the figures are marked provisional; closing the period freezes them.</Note>
      {!g ? (
        <Panel><Empty title={loading ? 'Loading the P&L' : failed ? 'The group P&L is not available' : 'Nothing to show'} hint={failed ? 'Only the CEO and the group admin can read it, or the server could not be reached.' : undefined} /></Panel>
      ) : (<>
        <KpiStrip>
          <Stat label="Group revenue" value={<>{money(g.group.revenue)}<span className="t-base text-[var(--text-3)] font-medium"> UGX</span></>} sub={g.group.usd.revenue === null ? noRate : `${usd0(g.group.usd.revenue)} at ${fmtNum(g.group.fx ?? 0)}`} />
          <Stat label="Group costs" value={<>{money(g.group.costs)}<span className="t-base text-[var(--text-3)] font-medium"> UGX</span></>} sub={g.group.usd.costs === null ? noRate : usd0(g.group.usd.costs)} />
          <Stat label={g.group.net >= 0 ? 'Net surplus' : 'Net deficit'} tone={g.group.net >= 0 ? 'ok' : 'bad'} value={<>{money(g.group.net)}<span className="t-base text-[var(--text-3)] font-medium"> UGX</span></>} sub={g.group.usd.net === null ? noRate : usd0(g.group.usd.net)} />
          <Stat label="Margin on revenue" value={pct(g.group.marginPct, g.group.revenue > 0)} sub={g.group.revenue > 0 ? undefined : 'No revenue in this period'} />
        </KpiStrip>
        <Panel title="P&L statement" sub={`UGX · ${monthLabel(month)}`} pad={false} actions={<span className="hidden sm:inline-flex">{g.provisional ? <Badge tone="warn" dot>Provisional</Badge> : <Badge tone="ok" dot>Closed</Badge>}</span>}>
          <div className="overflow-x-auto scrollbar-thin">
            <table className="w-full border-collapse t-base">
              <thead><tr><th className={cn(th, 'sticky left-0 z-[2] text-left pl-[var(--pad)]')}>Line</th>{g.sites.map((x) => <th key={x.siteCode} className={cn(th, 'min-w-[112px]')}>{x.siteCode ? <SiteTag site={x.siteCode} /> : '—'}</th>)}<th className={cn(th, 'min-w-[112px]')}>Group</th><th className={cn(th, 'min-w-[92px]')}>Group USD</th></tr></thead>
              <tbody>
                {row('Cash sales', (x) => money(x.revenue.cashSales), '')}
                {row('Invoiced', (x) => money(x.revenue.invoiced), '')}
                {row('Revenue', (x) => money(x.revenue.total), money(g.group.revenue), g.group.usd.revenue === null ? <span className="text-[var(--text-4)]" title={noRate}>—</span> : usd0(g.group.usd.revenue), true)}
                {cats.map((c) => <Fragment key={c}>{row(label(c), (x) => money(x.costs.byCategory[c] ?? 0), money(g.group.byCategory[c] ?? 0))}</Fragment>)}
                {row('Total costs', (x) => money(x.costs.total), money(g.group.costs), g.group.usd.costs === null ? <span className="text-[var(--text-4)]" title={noRate}>—</span> : usd0(g.group.usd.costs), true)}
                {row('Net', (x) => money(x.net), money(g.group.net), g.group.usd.net === null ? <span className="text-[var(--text-4)]" title={noRate}>—</span> : usd0(g.group.usd.net), true)}
                {row('Margin', (x) => pct(x.marginPct, x.revenue.total > 0), pct(g.group.marginPct, g.group.revenue > 0))}
                {row('Basis', (x) => <BasisBadge p={x} />, '')}
                {row('Unconverted revenue lines', (x) => (x.revenue.unconvertedLines ? <Badge tone="warn" dot>{x.revenue.unconvertedLines} without a rate</Badge> : <span className="text-[var(--text-4)]">—</span>), '')}
                <tr><td className={cn(lead, 'border-b-0')}>Period</td>{g.sites.map((x) => <td key={x.siteCode} className={cn(td, 'border-b-0')}><span className="inline-flex items-center gap-2 justify-end flex-wrap">{x.siteCode && <PeriodActions site={x.siteCode} month={month} p={x} onDone={load} />}</span></td>)}<td className={cn(td, 'border-b-0')} /><td className={cn(td, 'border-b-0')} /></tr>
              </tbody>
            </table>
          </div>
        </Panel>
        <Panel title="Unit cost per product" sub={`UGX per unit · ${monthLabel(month)}`} pad={false}
          footer={<span>{sg.sitePnl[pnlKey(sites[0] ?? 'mats', month)]?.materialPerKg == null ? 'No material cost per kg is available.' : 'Material at the purchase price per kg; overhead is categorised spend allocated by output kg.'}</span>}>
          <UnitCostTable rows={unitRows} emptyHint={g.sites.every((x) => x.closedDays.length === 0) ? 'No cash day is closed in this period yet, so there is no output to cost.' : 'No product with output, a price and more than one unit in this period.'} />
        </Panel>
      </>)}
    </div>
  );
}

/** Site Manager: this site's month, with the same figures, the Provisional badge and close or reopen. Used on the Reports page. */
export function SiteCosting({ site }: { site: SiteCode }) {
  const { month, setMonth, max } = useCostingMonth();
  const p = useServerGroup((s) => s.sitePnl[pnlKey(site, month)]);
  const refs = useRefList('expense_category');
  const [state, setState] = useState<'loading' | 'ok' | 'denied'>('loading');
  const load = useCallback(async () => { setState('loading'); try { setState((await loadSiteCosting(site, month)) ? 'ok' : 'denied'); } catch { setState('denied'); } }, [site, month]);
  useEffect(() => { void load(); }, [load]);
  if (state === 'denied' && !p) return null; // this role does not read costing
  const label = (k: string) => refs.find((r) => r.id === k)?.label ?? titleize(k);
  return (
    <Panel title="P&L and unit costs" sub={`UGX · ${monthLabel(month)}`} pad={false}
      actions={<span className="inline-flex items-center gap-2 flex-wrap">{p && <BasisBadge p={p} />}<MonthStepper month={month} onChange={setMonth} max={max} /><PeriodActions site={site} month={month} p={p} onDone={load} /></span>}>
      {!p ? <div className="p-[var(--pad)]"><Empty title="Loading the P&L" /></div> : (<>
        <div className="px-[var(--pad)] py-2">
          {([['Cash sales', p.revenue.cashSales], ['Invoiced', p.revenue.invoiced], ['Revenue', p.revenue.total], ...Object.entries(p.costs.byCategory).map(([k, v]) => [label(k), v] as [string, number]), ['Total costs', p.costs.total], ['Net', p.net]] as [string, number][]).map(([k, v]) => (
            <div key={k} className="flex items-center justify-between h-7 border-b border-[var(--line)] last:border-b-0 t-base"><span className={['Revenue', 'Total costs', 'Net'].includes(k) ? 'font-semibold' : 'text-[var(--text-2)]'}>{k}</span>{money(v)}</div>
          ))}
          <div className="flex items-center justify-between h-7 t-base"><span className="text-[var(--text-2)]">Margin</span><span className="num">{pct(p.marginPct, p.revenue.total > 0)}</span></div>
          {p.revenue.unconvertedLines > 0 && <p className="t-xs text-[var(--warn)] mt-1">{p.revenue.unconvertedLines} revenue lines are left out because there is no exchange rate for them.</p>}
        </div>
        <div className="t-label px-[var(--pad)] py-1 bg-[var(--surface-2)] border-y border-[var(--line)]">Unit cost per product</div>
        <UnitCostTable rows={p.unitCosts} p={p} />
      </>)}
    </Panel>
  );
}
