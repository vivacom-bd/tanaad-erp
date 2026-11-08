import { useMemo, useState } from 'react';
import { Download, Printer } from 'lucide-react';
import type { CashEntry, ExceptionKind, Product } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { useSite } from '@/app/hooks';
import { DEMO_HOUR, addDays, dailySeries, dow, lastWorkingDays } from '@/data/selectors';
import { fmtDay, fmtDayShort, fmtNum, fmtPct } from '@/data/format';
import { Button, DataGrid, Empty, Note, PageHeader, Panel, Tabs, useHotkey, useMedia, type Column } from '@/components/ui';
import { PRINT_CSS, downloadCsv, spanLabel, useExpenseCategories } from '@/features/cash/categories';

type Range = 'today' | 'week' | 'last7' | 'month';
const RANGES: { value: Range; label: string; short: string }[] = [{ value: 'today', label: 'Today', short: 'Today' }, { value: 'week', label: 'This week', short: 'Week' }, { value: 'last7', label: 'Last 7 working days', short: '7 days' }, { value: 'month', label: 'This month', short: 'Month' }];
/** What one counted unit of output is called, by the kind of product (a fixed domain enum, not a site list). */
const KIND_UNIT: Record<Product['kind'], string> = { mat: 'mats', bag_pack: 'packs', pellet: 'sacks', roll: 'rolls', material: 'units' };
const KIND_LABEL: Record<ExceptionKind, string> = { gate_variance: 'Gate variance', mass_gap: 'Mass gap', cash_variance: 'Cash variance', below_benchmark: 'Below benchmark', pod_discrepancy: 'Delivery discrepancy', credit_over_limit: 'Credit over limit', ghost_worker: 'Ghost worker', contract_expiring: 'Contract expiring', low_stock: 'Low stock', not_logged: 'Not logged' };

const GATE_LABEL: Record<string, string> = { pass: 'Passed', hold: 'Held', overridden: 'Overridden', pending: 'Not weighed' };
const csv = (name: string, header: string[], rows: (string | number)[][]) => { downloadCsv(name, header, rows); useStore.getState().toast({ kind: 'ok', title: `${name}.csv downloaded` }); };
const CsvButton = ({ onClick }: { onClick: () => void }) => <Button size="sm" variant="ghost" icon={Download} onClick={onClick} className="no-print">CSV</Button>;
const dash = <span className="text-[var(--text-4)]">–</span>;

/** Site reports for the Site Manager: plain tables over a chosen period, each printable and exportable as CSV. */
export function Reports() {
  const s = useStore(); const site = useSite(); const roomy = useMedia('(min-width: 640px)');
  const cats = useExpenseCategories(); const categoryLabel = cats.label;
  const [range, setRange] = useState<Range>('last7');
  const days = useMemo(() => {
    if (range === 'today') return [s.today]; if (range === 'last7') return lastWorkingDays(site.id, s.today, 7);
    const start = range === 'week' ? addDays(s.today, -((dow(s.today) + 6) % 7)) : s.today.slice(0, 8) + '01';
    const out: string[] = []; for (let d = start; d <= s.today; d = addDays(d, 1)) if (!site.closedDays.includes(dow(d))) out.push(d); return out.length ? out : [s.today];
  }, [range, s.today, site]);
  const inRange = (day: string) => days.includes(day); const label = days.length === 1 ? fmtDay(days[0]) : spanLabel(days[0], days[days.length - 1], fmtDayShort); const slug = `${site.id}-${range}-${s.today}`;
  useHotkey(['1', '2', '3', '4'], (e) => setRange(RANGES[+e.key - 1].value), { label: 'Switch range: today, week, 7 days, month' });

  const series = useMemo(() => dailySeries(s, site.id, days), [s, site.id, days]);
  const siteKinds = s.products.filter((p) => p.siteId === site.id && p.kind !== 'material' && p.active !== false && s.production.some((e) => e.productId === p.id)).map((p) => p.kind);
  const unit = KIND_UNIT[siteKinds.sort((a, b) => siteKinds.filter((k) => k === b).length - siteKinds.filter((k) => k === a).length)[0] ?? 'material']; const Unit = unit[0].toUpperCase() + unit.slice(1);

  // 1. Output per product per machine
  const prod = s.production.filter((p) => p.siteId === site.id && inRange(p.day) && p.productId && p.goodUnits > 0);
  const products = s.products.filter((p) => prod.some((e) => e.productId === p.id));
  const pivot = [...new Set(prod.map((e) => e.machineId))].map((mid) => {
    const m = s.machines.find((x) => x.id === mid); const by: Record<string, number> = {}; const byKg: Record<string, number> = {};
    for (const e of prod.filter((x) => x.machineId === mid)) { by[e.productId!] = (by[e.productId!] ?? 0) + e.goodUnits; byKg[e.productId!] = (byKg[e.productId!] ?? 0) + e.outputKg; }
    return { id: mid, code: m?.code ?? mid, stage: site.stages.find((st) => st.code === m?.stageCode)?.name ?? m?.stageCode ?? '', by, byKg, total: Object.values(by).reduce((t, v) => t + v, 0), kg: Object.values(byKg).reduce((t, v) => t + v, 0) };
  }).sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true }));
  type PivotRow = (typeof pivot)[number];
  const unitsTotal = pivot.reduce((t, r) => t + r.total, 0); const kgTotal = pivot.reduce((t, r) => t + r.kg, 0);
  const pivotCols: Column<PivotRow>[] = [
    { key: 'm', header: 'Machine', sticky: true, sort: (r) => r.code.replace(/\d+/, (x) => x.padStart(3, '0')), cell: (r) => <span>{r.code}<span className="text-[var(--text-3)]"> · {r.stage}</span></span> },
    ...products.map((p) => ({ key: p.id, header: p.internalName, align: 'right' as const, mono: true, sort: (r: PivotRow) => r.by[p.id] ?? 0, cell: (r: PivotRow) => (r.by[p.id] ? fmtNum(r.by[p.id]) : dash) })),
    { key: 't', header: `Total ${unit}`, align: 'right', mono: true, sort: (r) => r.total, cell: (r) => <span className="font-medium">{fmtNum(r.total)}</span> },
    { key: 'kg', header: 'Output kg', align: 'right', mono: true, sort: (r) => r.kg, cell: (r) => fmtNum(Math.round(r.kg)) },
  ];

  // 3. Cash by category
  const cash = s.cashEntries.filter((e) => e.siteId === site.id && inRange(e.day));
  const catRows = [...new Set(cash.map((e) => e.category))].map((c) => { const es = cash.filter((e) => e.category === c); const sum = (f: (e: CashEntry) => boolean) => es.filter(f).reduce((t, e) => t + e.amount, 0); return { category: c, inAmt: sum((e) => e.direction === 'in'), outAmt: sum((e) => e.direction === 'out'), count: es.length }; }).sort((a, b) => b.outAmt + b.inAmt - (a.outAmt + a.inAmt));
  const totalIn = catRows.reduce((t, r) => t + r.inAmt, 0); const totalOut = catRows.reduce((t, r) => t + r.outAmt, 0);

  // 5. Dispatches
  const dsp = s.dispatches.filter((d) => d.siteId === site.id && inRange(d.createdAt.slice(0, 10))).map((d) => { const o = s.orders.find((x) => x.id === d.orderId); return { d, o, client: s.clients.find((c) => c.id === o?.clientId)?.name ?? '—', bundles: o?.lines.reduce((t, l) => t + l.bundles, 0) ?? 0 }; }).sort((a, b) => b.d.createdAt.localeCompare(a.d.createdAt));
  

  // 6. Exceptions by kind
  const ex = s.exceptions.filter((e) => e.siteId === site.id && inRange(e.raisedAt.slice(0, 10)));
  const exRows = (Object.keys(KIND_LABEL) as ExceptionKind[]).map((k) => { const es = ex.filter((e) => e.kind === k); return { kind: k, total: es.length, open: es.filter((e) => e.status === 'open').length, critical: es.filter((e) => e.severity === 'critical').length }; }).filter((r) => r.total > 0).sort((a, b) => b.total - a.total); 

  // A day still running is only measured against what is due by now.
  const progress = Math.max(0.1, Math.min(1, (DEMO_HOUR - 7) / 12));
  const dueOf = (d: (typeof series)[number]) => Math.round(d.target * (d.partial ? progress : 1));
  const sum = (f: (d: (typeof series)[number]) => number) => series.reduce((t, d) => t + f(d), 0);
  const totKgIn = sum((d) => d.kgIn); const totWasteKg = sum((d) => (d.kgIn * d.wastePct) / 100);

  const exportOutput = () => csv(`output-${slug}`, ['Machine', 'Stage', ...products.map((p) => p.internalName), 'Total', 'Output kg'], pivot.map((r) => [r.code, r.stage, ...products.map((p) => r.by[p.id] ?? 0), r.total, Math.round(r.kg)]));
  const exportCash = () => csv(`cash-${slug}`, ['Category', 'In (UGX)', 'Out (UGX)', 'Entries'], catRows.map((r) => [categoryLabel(r.category), r.inAmt, r.outAmt, r.count]));
  const exportDispatches = () => csv(`dispatches-${slug}`, ['Delivery note', 'Day', 'Client', 'Order', 'Bundles', 'Expected kg', 'Gate kg', 'Variance %', 'Gate'], dsp.map((r) => [r.d.deliveryNoteNo, r.d.createdAt.slice(0, 10), r.client, r.o?.number ?? '', r.bundles, r.d.expectedKg, r.d.gateKg ?? '', r.d.variancePct ?? '', r.d.gateStatus]));
  const exportExceptions = () => csv(`exceptions-${slug}`, ['Kind', 'Raised', 'Open', 'Critical'], exRows.map((r) => [KIND_LABEL[r.kind], r.total, r.open, r.critical]));
  const exportDaily = () => csv(`daily-${slug}`, ['Day', Unit, 'Benchmark', 'Kg in', 'Kg out', 'Waste %', 'Cash in (UGX)', 'Cash out (UGX)', 'Headcount', 'Dispatches', 'Downtime min'], series.map((d) => [d.day, d.units, d.target, d.kgIn, d.kgOut, d.wastePct, d.cashIn, d.cashOut, d.headcount, d.dispatches, d.downtimeMin]));

  type Day = (typeof series)[number];
  const dailyCols: Column<Day>[] = [
    { key: 'd', header: 'Day', sort: (d) => d.day, cell: (d) => <span>{fmtDayShort(d.day)}{d.partial && <span className="text-[var(--text-3)]"> · so far</span>}</span> },
    { key: 'u', header: Unit, align: 'right', mono: true, sort: (d) => d.units, cell: (d) => fmtNum(d.units) },
    { key: 'b', header: 'Due', align: 'right', mono: true, sort: dueOf, cell: (d) => (d.target ? fmtNum(dueOf(d)) : dash) },
    { key: 'in', header: 'Kg in', align: 'right', mono: true, sort: (d) => d.kgIn, cell: (d) => (d.kgIn ? fmtNum(d.kgIn) : dash) },
    { key: 'w', header: 'Waste', align: 'right', mono: true, sort: (d) => d.wastePct, cell: (d) => (d.kgIn ? fmtPct(d.wastePct) : dash) },
    { key: 'dt', header: 'Downtime min', align: 'right', mono: true, sort: (d) => d.downtimeMin, cell: (d) => fmtNum(d.downtimeMin) },
    { key: 'hc', header: 'On shift', align: 'right', mono: true, sort: (d) => d.headcount, cell: (d) => fmtNum(d.headcount) },
    { key: 'ci', header: 'Cash in', align: 'right', mono: true, sort: (d) => d.cashIn, cell: (d) => fmtNum(d.cashIn) },
    { key: 'co', header: 'Cash out', align: 'right', mono: true, sort: (d) => d.cashOut, cell: (d) => fmtNum(d.cashOut) },
    { key: 'ds', header: 'Loads out', align: 'right', mono: true, sort: (d) => d.dispatches, cell: (d) => fmtNum(d.dispatches) },
  ];
  const period = `${label} · ${days.length} working day${days.length === 1 ? '' : 's'}`;

  return (
    <div className="print-area flex flex-col gap-[var(--gap)]">
      <style>{PRINT_CSS}</style>
      <div className="hidden print:block"><div className="t-title">{site.legalName} · Reports</div><div className="t-sm">{period}</div></div>

      <div className="no-print">
        <PageHeader title="Reports" subtitle={period}
          actions={<>
            <Tabs value={range} onChange={setRange} tabs={RANGES.map((r) => ({ value: r.value, label: roomy ? r.label : r.short }))} />
            <Button icon={Printer} onClick={() => window.print()}>Print</Button>
          </>} />
      </div>
      {site.toBeConfirmed && <Note tone="warn">{site.toBeConfirmed}</Note>}

      <Panel title="Daily summary" sub="UGX for cash, kg for weights" pad={false} actions={<CsvButton onClick={exportDaily} />}>
        <DataGrid bare dense rows={series} rowKey={(d) => d.day} columns={dailyCols}
          footer={<tr><td>Total</td><td className="text-right num">{fmtNum(sum((d) => d.units))}</td><td className="text-right num">{fmtNum(sum(dueOf))}</td><td className="text-right num">{fmtNum(totKgIn)}</td><td className="text-right num">{totKgIn ? fmtPct((totWasteKg / totKgIn) * 100) : '–'}</td><td className="text-right num">{fmtNum(sum((d) => d.downtimeMin))}</td><td /><td className="text-right num">{fmtNum(sum((d) => d.cashIn))}</td><td className="text-right num">{fmtNum(sum((d) => d.cashOut))}</td><td className="text-right num">{fmtNum(sum((d) => d.dispatches))}</td></tr>} />
      </Panel>

      <Panel title="Output by machine" sub={`${unit}, ${label}`} pad={false} actions={<CsvButton onClick={exportOutput} />}>
        {pivot.length === 0 ? <div className="p-[var(--pad)]"><Empty title="No output logged in this period" /></div>
          : <DataGrid bare dense rows={pivot} columns={pivotCols} rowKey={(r) => r.id}
            footer={<tr><td className="sticky left-0 bg-[var(--surface-2)]">Total</td>{products.map((p) => <td key={p.id} className="text-right num">{fmtNum(pivot.reduce((t, r) => t + (r.by[p.id] ?? 0), 0))}</td>)}<td className="text-right num">{fmtNum(unitsTotal)}</td><td className="text-right num">{fmtNum(Math.round(kgTotal))}</td></tr>} />}
      </Panel>

      <div className="grid gap-[var(--gap)] xl:grid-cols-2 items-start">
        <Panel title="Cash by category" sub={`UGX, ${label}`} pad={false} actions={<CsvButton onClick={exportCash} />}>
          {catRows.length === 0 ? <div className="p-[var(--pad)]"><Empty title="No cash entries in this period" /></div>
            : <DataGrid bare dense rows={catRows} rowKey={(r) => r.category} columns={[
              { key: 'c', header: 'Category', sort: (r) => categoryLabel(r.category), cell: (r) => categoryLabel(r.category) },
              { key: 'in', header: 'In', align: 'right', mono: true, sort: (r) => r.inAmt, cell: (r) => (r.inAmt ? fmtNum(r.inAmt) : dash) },
              { key: 'out', header: 'Out', align: 'right', mono: true, sort: (r) => r.outAmt, cell: (r) => (r.outAmt ? fmtNum(r.outAmt) : dash) },
              { key: 'sh', header: 'Share of out', align: 'right', mono: true, sort: (r) => r.outAmt, cell: (r) => (r.outAmt ? fmtPct((r.outAmt / Math.max(1, totalOut)) * 100) : dash) },
              { key: 'n', header: 'Entries', align: 'right', mono: true, sort: (r) => r.count, cell: (r) => fmtNum(r.count) },
            ]} footer={<tr><td>Total</td><td className="text-right num">{fmtNum(totalIn)}</td><td className="text-right num">{fmtNum(totalOut)}</td><td /><td className="text-right num">{fmtNum(cash.length)}</td></tr>} />}
        </Panel>

        <Panel title="Exceptions raised" sub={label} pad={false} actions={<CsvButton onClick={exportExceptions} />}>
          {exRows.length === 0 ? <div className="p-[var(--pad)]"><Empty title="No exceptions raised in this period" /></div>
            : <DataGrid bare dense rows={exRows} rowKey={(r) => r.kind} columns={[
              { key: 'k', header: 'Kind', sort: (r) => KIND_LABEL[r.kind], cell: (r) => KIND_LABEL[r.kind] },
              { key: 't', header: 'Raised', align: 'right', mono: true, sort: (r) => r.total, cell: (r) => fmtNum(r.total) },
              { key: 'o', header: 'Still open', align: 'right', mono: true, sort: (r) => r.open, cell: (r) => (r.open ? fmtNum(r.open) : dash) },
              { key: 'c', header: 'Critical', align: 'right', mono: true, sort: (r) => r.critical, cell: (r) => (r.critical ? <span className="text-[var(--bad)]">{fmtNum(r.critical)}</span> : dash) },
            ]} />}
        </Panel>
      </div>

      <Panel title="Dispatches" sub={label} pad={false} actions={<CsvButton onClick={exportDispatches} />}>
        {dsp.length === 0 ? <div className="p-[var(--pad)]"><Empty title="No dispatches in this period" /></div>
          : <DataGrid bare dense rows={dsp} rowKey={(r) => r.d.id} columns={[
            { key: 'n', header: 'Delivery note', sort: (r) => r.d.deliveryNoteNo, cell: (r) => r.d.deliveryNoteNo },
            { key: 'd', header: 'Day', sort: (r) => r.d.createdAt, cell: (r) => fmtDayShort(r.d.createdAt.slice(0, 10)) },
            { key: 'c', header: 'Client', sort: (r) => r.client, cell: (r) => r.client },
            { key: 'o', header: 'Order', sort: (r) => r.o?.number ?? '', cell: (r) => r.o?.number ?? dash },
            { key: 'b', header: 'Bundles', align: 'right', mono: true, sort: (r) => r.bundles, cell: (r) => fmtNum(r.bundles) },
            { key: 'e', header: 'Expected kg', align: 'right', mono: true, sort: (r) => r.d.expectedKg, cell: (r) => fmtNum(Math.round(r.d.expectedKg)) },
            { key: 'g', header: 'Gate kg', align: 'right', mono: true, sort: (r) => r.d.gateKg ?? 0, cell: (r) => (r.d.gateKg !== undefined ? fmtNum(Math.round(r.d.gateKg)) : dash) },
            { key: 'v', header: 'Difference', align: 'right', mono: true, sort: (r) => r.d.variancePct ?? 0, cell: (r) => (r.d.variancePct !== undefined ? fmtPct(r.d.variancePct, true) : dash) },
            { key: 's', header: 'Gate', sort: (r) => r.d.gateStatus, cell: (r) => <span className={r.d.gateStatus === 'hold' ? 'text-[var(--bad)]' : r.d.gateStatus === 'overridden' ? 'text-[var(--warn)]' : undefined}>{GATE_LABEL[r.d.gateStatus] ?? r.d.gateStatus}</span> },
          ]} />}
      </Panel>
    </div>
  );
}
