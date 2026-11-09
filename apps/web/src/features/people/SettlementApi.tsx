import { useEffect, useMemo, useState } from 'react';
import { Ban, Check, HandCoins, Lock, PenLine, Plus, Wallet } from 'lucide-react';
import { useStore } from '@/data/store';
import { useSite } from '@/app/hooks';
import { Badge, Button, DataGrid, Empty, Field, Input, Note, PageHeader, Panel, Sheet, Tabs, cn, type Column } from '@/components/ui';
import { addDays, dow } from '@/data/selectors';
import { fmtDayShort, fmtNum, fmtUGX } from '@/data/format';
import { dismissRejected, useSync } from '@/data/api/sync';
import {
  advanceEnvelope, approveEnvelope, cancelEnvelope, canCancel, createRunEnvelope, isSettlementType, lineSettled, loadSettlement, payoutEnvelope, submitSettlement, useSettlement,
  type Bonus, type PayMethod, type RunKind, type SettlementLineRow, type SettlementRunRow,
} from '@/data/api/settlement';

const KIND_LABEL: Record<RunKind, string> = { weekly_casual: 'Weekly casuals', end_of_term: 'End-of-term contracts', monthly: 'Monthly' };
const STATUS: Record<SettlementRunRow['status'], { label: string; tone: 'neutral' | 'ok' | 'warn' | 'bad' }> = {
  created: { label: 'Awaiting approval', tone: 'warn' }, approved: { label: 'Approved', tone: 'ok' }, paid: { label: 'Paid', tone: 'ok' }, cancelled: { label: 'Cancelled', tone: 'neutral' },
};
const METHODS: { value: PayMethod; label: string }[] = [{ value: 'cash', label: 'Cash' }, { value: 'mobile_money', label: 'Mobile money' }, { value: 'bank', label: 'Bank' }];
const say = (t: { kind: 'ok' | 'warn' | 'error' | 'info'; title: string; detail?: string }) => useStore.getState().toast(t);

/** Default period for a new run of each kind, from the store's clock. */
function defaultPeriod(kind: RunKind, today: string): { from: string; to: string } {
  if (kind === 'monthly') return { from: today.slice(0, 8) + '01', to: today };
  if (kind === 'end_of_term') return { from: addDays(today, -30), to: addDays(today, 7) };
  const monday = addDays(today, -((dow(today) + 6) % 7));
  return { from: monday, to: addDays(monday, 5) };
}

/** Real-mode Settlement: the server computes, approves and pays the runs; this screen sends the commands and shows what came back. */
export function SettlementApi() {
  const s = useStore(); const site = useSite();
  const { runs: allRuns, advances: allAdvances, queued, error } = useSettlement();
  const rejected = useSync((x) => x.rejected);
  const [kind, setKind] = useState<RunKind>('weekly_casual');
  const [runId, setRunId] = useState<string | null>(null);
  const [sheet, setSheet] = useState<'new' | 'advance' | 'cancel' | null>(null);
  const [payLine, setPayLine] = useState<SettlementLineRow | null>(null);

  useEffect(() => { void loadSettlement([site.id]); }, [site.id]);

  const runs = useMemo(() => allRuns.filter((r) => r.siteCode === site.id && r.kind === kind), [allRuns, site.id, kind]);
  const run = runs.find((r) => r.id === runId) ?? runs[0] ?? null;
  const advances = useMemo(() => [...queued.advances.filter((a) => a.siteCode === site.id), ...allAdvances.filter((a) => a.siteCode === site.id)], [queued, allAdvances, site.id]);
  const nameOf = (id: string) => s.people.find((p) => p.id === id)?.fullName ?? 'Unknown person';
  const issues = rejected.filter((r) => r.siteCode === site.id && isSettlementType(r.envelope.type));
  const approving = !!run && queued.approved.includes(run.id);
  const cancelling = !!run && queued.cancelled.includes(run.id);
  const payQueued = (l: SettlementLineRow) => queued.payouts.includes(`${l.runId}|${l.personId}`);
  const cancellable = !!run && canCancel(run, queued);
  const outstanding = advances.reduce((t, a) => t + a.balance, 0);

  const send = async (env: Parameters<typeof submitSettlement>[1], title: string, detail?: string) => { await submitSettlement(site.id, env); say({ kind: 'info', title, detail }); };

  const columns: Column<SettlementLineRow>[] = [
    { key: 'name', header: 'Name', sticky: true, sort: (l) => nameOf(l.personId), cell: (l) => <span className="font-medium">{nameOf(l.personId)}</span> },
    { key: 'period', header: 'Period', cell: (l) => <span className="text-[var(--text-2)]">{fmtDayShort(l.periodFrom)} – {fmtDayShort(l.periodTo)}</span> },
    { key: 'rate', header: 'Rate', align: 'right', mono: true, cell: (l) => <>{fmtNum(l.rate)}<span className="t-sm text-[var(--text-3)]"> /{l.rateBasis}</span></> },
    { key: 'units', header: 'Units', align: 'right', mono: true, sort: (l) => l.units, cell: (l) => fmtNum(l.units) },
    { key: 'ot', header: 'OT h', align: 'right', mono: true, cell: (l) => (l.overtimeHours ? l.overtimeHours : '–') },
    { key: 'gross', header: 'Gross', align: 'right', mono: true, sort: (l) => l.gross, cell: (l) => fmtNum(l.gross) },
    { key: 'overtime', header: 'Overtime', align: 'right', mono: true, cell: (l) => (l.overtime ? fmtNum(l.overtime) : '–') },
    { key: 'bonus', header: 'Bonus', align: 'right', mono: true, cell: (l) => (l.bonus ? fmtNum(l.bonus) : '–') },
    { key: 'adv', header: 'Advances', align: 'right', mono: true, cell: (l) => (l.advances ? `−${fmtNum(l.advances)}` : '–') },
    { key: 'net', header: 'Net', align: 'right', mono: true, sort: (l) => l.net, cell: (l) => <span className="font-semibold">{fmtNum(l.net)}</span> },
    { key: 'pay', header: 'Payout', align: 'right', cell: (l) => {
      if (l.status === 'paid') return <Badge tone="ok"><Check className="size-3" strokeWidth={3} />Paid</Badge>;
      if (lineSettled(l)) return <Badge tone="neutral"><Check className="size-3" strokeWidth={3} />Settled · nothing to pay</Badge>;
      if (payQueued(l)) return <Badge tone="warn" dot>Waiting to sync</Badge>;
      if (run?.status !== 'approved') return <span className="t-sm text-[var(--text-4)]">{run?.status === 'created' ? 'Approve first' : '–'}</span>;
      return <Button size="sm" icon={Wallet} disabled={l.net <= 0} onClick={() => setPayLine(l)}>Pay out</Button>;
    } },
  ];

  const lines = run?.lines ?? [];
  const paid = lines.filter(lineSettled);
  return (
    <div>
      <PageHeader eyebrow={`People · ${site.name}`} title="Settlement"
        meta={run ? <Badge tone={STATUS[run.status].tone} dot>{STATUS[run.status].label}</Badge> : undefined}
        subtitle={run ? `Period ${fmtDayShort(run.from)} – ${fmtDayShort(run.to)}. Computed by the server from attendance and advances.` : 'Runs are computed by the server from attendance and advances.'}
        actions={<>
          <Button icon={HandCoins} onClick={() => setSheet('advance')}>Give advance</Button>
          {cancellable && <Button icon={Ban} onClick={() => setSheet('cancel')}>Cancel run</Button>}
          {run?.status === 'created' && <Button variant="primary" icon={PenLine} disabled={approving} onClick={() => void send(approveEnvelope(run.id), 'Approval queued', 'Sent to the server to sign off')}>{approving ? 'Approval waiting to sync' : 'Approve run'}</Button>}
          <Button variant={run ? 'secondary' : 'primary'} icon={Plus} onClick={() => setSheet('new')}>New run</Button>
        </>}
        tabs={<Tabs variant="underline" value={kind} onChange={(k) => { setKind(k); setRunId(null); }} tabs={(Object.keys(KIND_LABEL) as RunKind[]).map((k) => ({ value: k, label: KIND_LABEL[k], count: allRuns.filter((r) => r.siteCode === site.id && r.kind === k && r.status !== 'cancelled').length }))} />} />

      {error && <Note tone="warn" className="mb-[var(--gap)]">Could not refresh from the server: {error}. Showing the last loaded data.</Note>}
      {issues.map((r) => (
        <Note key={r.key} tone="warn" className="mb-[var(--gap)]"><b>Not accepted:</b> {r.reason ?? r.code}{' '}
          <button className="underline" onClick={() => void dismissRejected(r.key!)}>Dismiss</button></Note>
      ))}
      {queued.creates > 0 && <Note tone="neutral" className="mb-[var(--gap)]">{queued.creates} new run{queued.creates === 1 ? '' : 's'} waiting to sync. The lines appear once the server has computed them.</Note>}
      {cancelling && <Note tone="neutral" className="mb-[var(--gap)]">Cancellation waiting to sync.</Note>}

      {runs.length > 1 && (
        <div className="mb-[var(--gap)] flex flex-wrap gap-2">
          {runs.map((r) => (
            <button key={r.id} onClick={() => setRunId(r.id)} className={cn('rounded-md border px-2.5 py-1 t-sm', r.id === run?.id ? 'border-[var(--accent)] bg-[var(--surface-2)]' : 'border-[var(--line-2)]')}>
              {fmtDayShort(r.from)} – {fmtDayShort(r.to)} · {STATUS[r.status].label}
            </button>
          ))}
        </div>
      )}

      {run ? (
        <>
          {run.status === 'created' && <Note tone="neutral" className="mb-[var(--gap)]">Someone other than the person who created this run must approve it.</Note>}
          {run.status === 'cancelled' && <Note tone="neutral" className="mb-[var(--gap)]">Cancelled. Advances this run deducted were given back.</Note>}
          {run.status === 'approved' && <Note tone="ok" icon={Lock} className="mb-[var(--gap)]"><b>Approved.</b> Lines are fixed; attendance changes do not alter them.</Note>}
          <DataGrid rows={lines} columns={columns} rowKey={(l) => l.id} dense maxHeight="max(340px, calc(100dvh - 449px))"
            empty={<Empty icon={Wallet} title="No lines" />}
            footer={lines.length ? <tr><td colSpan={5}>Total · {lines.length} worker{lines.length === 1 ? '' : 's'}</td><td className="text-right num">{fmtNum(run.totalGross)}</td><td /><td /><td className="text-right num">{run.totalAdvances ? `−${fmtNum(run.totalAdvances)}` : '–'}</td><td className="text-right num">{fmtNum(run.totalNet)}</td><td className="text-right t-sm font-normal text-[var(--text-2)] num">{paid.length}/{lines.length} paid</td></tr> : undefined} />
        </>
      ) : (
        <Empty icon={Wallet} title={`No ${KIND_LABEL[kind].toLowerCase()} run yet`} hint="Create a run for a period. The server works out each line from attendance and deducts outstanding advances." action={<Button variant="primary" icon={Plus} onClick={() => setSheet('new')}>New run</Button>} />
      )}

      <Panel className="mt-[var(--gap)]" icon={HandCoins} title="Advances" sub={`${fmtUGX(outstanding)} outstanding`} pad={false}>
        {advances.length === 0 ? <div className="px-[var(--pad)] py-4 t-sm text-[var(--text-3)]">No advances given.</div> : (
          <div className="divide-y divide-[var(--line)]">
            {advances.map((a) => (
              <div key={a.id} className="px-[var(--pad)] py-2 flex items-center justify-between gap-3 t-base">
                <span className="min-w-0"><span className="font-medium">{nameOf(a.personId)}</span> <span className="text-[var(--text-3)] t-sm">· {a.reason} · {fmtDayShort(a.givenOn)}</span></span>
                <span className="num shrink-0 flex items-center gap-2">{a.queued && <Badge tone="warn" dot>Waiting to sync</Badge>}{fmtNum(a.amount)}{a.deducted > 0 && <span className="t-sm text-[var(--text-3)]"> · balance {fmtNum(a.balance)}</span>}</span>
              </div>
            ))}
          </div>
        )}
      </Panel>

      <NewRunSheet open={sheet === 'new'} kind={kind} today={s.today} onClose={() => setSheet(null)} people={s.people.filter((p) => p.status === 'active' && (p.homeSiteId === site.id || p.sharedSites.includes(site.id)))} onSubmit={(from, to, bonuses) => { setSheet(null); void send(createRunEnvelope(kind, from, to, undefined, bonuses), 'Run queued', 'The server will compute the lines'); }} />
      <AdvanceSheet open={sheet === 'advance'} people={s.people.filter((p) => p.status === 'active' && (p.homeSiteId === site.id || p.sharedSites.includes(site.id)))} onClose={() => setSheet(null)}
        onSubmit={(pid, amount, reason) => { setSheet(null); void send(advanceEnvelope(pid, amount, reason), 'Advance queued', `${nameOf(pid)} · ${fmtUGX(amount)}`); }} />
      <CancelSheet open={sheet === 'cancel' && !!run} onClose={() => setSheet(null)} onSubmit={(reason) => { setSheet(null); if (run) void send(cancelEnvelope(run.id, reason), 'Cancellation queued'); }} />
      <PaySheet line={payLine} name={payLine ? nameOf(payLine.personId) : ''} onClose={() => setPayLine(null)} onSubmit={(method, reference) => { const l = payLine; setPayLine(null); if (l) void send(payoutEnvelope(l.runId, l.personId, method, reference), 'Payout queued', `${nameOf(l.personId)} · ${fmtUGX(l.net)}`); }} />
    </div>
  );
}

function NewRunSheet({ open, kind, today, people, onClose, onSubmit }: { open: boolean; kind: RunKind; today: string; people: { id: string; fullName: string }[]; onClose: () => void; onSubmit: (from: string, to: string, bonuses: Bonus[]) => void }) {
  const [bonus, setBonus] = useState<Record<string, string>>({}); const [addId, setAddId] = useState('');
  const d = defaultPeriod(kind, today);
  const [from, setFrom] = useState(d.from); const [to, setTo] = useState(d.to);
  useEffect(() => { if (open) { const p = defaultPeriod(kind, today); setFrom(p.from); setTo(p.to); setBonus({}); setAddId(''); } }, [open, kind, today]);
  const ok = !!from && !!to && to >= from;
  return (
    <Sheet open={open} onClose={onClose} title="New settlement run" sub={KIND_LABEL[kind]} width={420}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button variant="primary" disabled={!ok} onClick={() => onSubmit(from, to, Object.entries(bonus).map(([personId, v]) => ({ personId, amount: Number(v) })))}>Create run</Button></>}>
      <div className="flex flex-col gap-3">
        <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="To"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
        <Field label="Bonuses" hint="Optional, whole shillings per worker">
          <div className="flex flex-col gap-2">
            {Object.keys(bonus).map((id) => (
              <div key={id} className="flex items-center gap-2"><span className="flex-1 min-w-0 truncate t-base">{people.find((p) => p.id === id)?.fullName ?? 'Unknown person'}</span>
                <Input type="number" min={0} step="1" inputMode="numeric" aria-label="Bonus amount" className="w-[120px]" value={bonus[id]} onChange={(e) => setBonus({ ...bonus, [id]: e.target.value })} />
                <Button size="sm" variant="ghost" onClick={() => setBonus(Object.fromEntries(Object.entries(bonus).filter(([k]) => k !== id)))}>Remove</Button></div>
            ))}
            <select aria-label="Add a bonus for" className="ctl w-full rounded-md border border-[var(--line-2)] bg-[var(--surface)] px-2.5 t-base" value={addId} onChange={(e) => { if (e.target.value) setBonus({ ...bonus, [e.target.value]: '' }); setAddId(''); }}>
              <option value="">Add a bonus for…</option>{people.filter((p) => !(p.id in bonus)).map((p) => <option key={p.id} value={p.id}>{p.fullName}</option>)}
            </select>
          </div>
        </Field>
        {!ok && <Note tone="warn">The end date must not be before the start date.</Note>}
        <Note tone="neutral">The server counts attendance in this period and deducts each worker's outstanding advances.</Note>
      </div>
    </Sheet>
  );
}

function AdvanceSheet({ open, people, onClose, onSubmit }: { open: boolean; people: { id: string; fullName: string }[]; onClose: () => void; onSubmit: (personId: string, amount: number, reason: string) => void }) {
  const [pid, setPid] = useState(''); const [amount, setAmount] = useState(''); const [reason, setReason] = useState('');
  useEffect(() => { if (open) { setPid(''); setAmount(''); setReason(''); } }, [open]);
  const n = Number(amount); const ok = !!pid && n > 0 && reason.trim().length > 0;
  return (
    <Sheet open={open} onClose={onClose} title="Give advance" sub="Deducted from the worker's next run" width={420}
      footer={<><Button variant="ghost" onClick={onClose}>Cancel</Button><Button variant="primary" disabled={!ok} onClick={() => onSubmit(pid, n, reason)}>Give advance</Button></>}>
      <div className="flex flex-col gap-3">
        <Field label="Worker"><select className="ctl w-full rounded-md border border-[var(--line-2)] bg-[var(--surface)] px-2.5 t-base" value={pid} onChange={(e) => setPid(e.target.value)}><option value="">Choose a worker</option>{people.map((p) => <option key={p.id} value={p.id}>{p.fullName}</option>)}</select></Field>
        <Field label="Amount (UGX)"><Input type="number" min={0} step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field label="Reason"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why the advance is needed" /></Field>
      </div>
    </Sheet>
  );
}

function CancelSheet({ open, onClose, onSubmit }: { open: boolean; onClose: () => void; onSubmit: (reason: string) => void }) {
  const [reason, setReason] = useState('');
  useEffect(() => { if (open) setReason(''); }, [open]);
  return (
    <Sheet open={open} onClose={onClose} title="Cancel this run" sub="Advances it deducted are given back" width={420}
      footer={<><Button variant="ghost" onClick={onClose}>Keep run</Button><Button variant="primary" icon={Ban} onClick={() => onSubmit(reason)}>Cancel run</Button></>}>
      <Field label="Reason" hint="Optional"><Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why is this run being cancelled?" /></Field>
    </Sheet>
  );
}

function PaySheet({ line, name, onClose, onSubmit }: { line: SettlementLineRow | null; name: string; onClose: () => void; onSubmit: (m: PayMethod, reference?: string) => void }) {
  const [method, setMethod] = useState<PayMethod>('cash'); const [reference, setReference] = useState('');
  useEffect(() => { setMethod('cash'); setReference(''); }, [line?.id]);
  return (
    <Sheet open={!!line} onClose={onClose} title="Record payout" sub={name} width={420}
      footer={line && <><Button variant="ghost" onClick={onClose}>Cancel</Button><Button variant="primary" icon={Check} onClick={() => onSubmit(method, reference)}>Confirm {fmtUGX(line.net)}</Button></>}>
      <div className="flex flex-col gap-3">
        <Field label="Paid by"><div className="flex gap-2">{METHODS.map((m) => <Button key={m.value} variant={method === m.value ? 'primary' : 'secondary'} onClick={() => setMethod(m.value)}>{m.label}</Button>)}</div></Field>
        <Field label="Reference" hint="Optional, for example a mobile money transaction id"><Input value={reference} maxLength={64} onChange={(e) => setReference(e.target.value)} /></Field>
        {method === 'cash' && <Note tone="neutral">A cash payout is posted to today's cash book, so the day must be open.</Note>}
      </div>
    </Sheet>
  );
}

