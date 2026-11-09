import { useCallback, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { CalendarDays, Camera, CheckCircle2, ChevronLeft, ChevronRight, Download, LineChart as LineChartIcon, Lock, Plus, Printer, Receipt, ScrollText, ShieldCheck, Unlock, Wallet } from 'lucide-react';
import { useStore } from '@/data/store';
import { useSite, useGo, useSiteSettings } from '@/app/hooks';
import { Avatar, Badge, Button, Combobox, Empty, Field, Input, Note, NumberPad, PageHeader, Panel, PinPad, Sheet, Stat, Status, cn } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { cashDayFor, cashSummary, dailySeries, lastWorkingDays } from '@/data/selectors';
import { compact, fmtDay, fmtDayLong, fmtDayShort, fmtNum, fmtUGX, fmtUSD } from '@/data/format';
import { PRINT_CSS, SITE_MANAGER_PIN, downloadCsv, stampOf, timeOf, useExpenseCategories } from './categories';
import { CloseDaySheet, varianceTone } from './CloseDaySheet';
import { LedgerTable, LineSheet, type LedgerGroup } from './Ledger';

type Overlay = 'none' | 'close' | 'reopen' | 'receipt';

/** The daily cash-and-sales page: the paper page as a live ledger, with the money-out split beside it and a signed close at the end. */
export function CashPage() {
  const s = useStore(); const site = useSite(); const go = useGo();
  const addCashEntry = useStore((x) => x.addCashEntry); const closeCashDay = useStore((x) => x.closeCashDay); const reopenCashDay = useStore((x) => x.reopenCashDay); const toast = useStore((x) => x.toast);
  const [day, setDay] = useState(s.today);
  const [panel, setPanel] = useState<Overlay>('none');
  const [lineId, setLineId] = useState<string | null>(null);
  const closePanel = useCallback(() => setPanel('none'), []);
  const idle = panel === 'none' && !lineId;
  const key = (k: string) => (idle ? k : undefined);

  // Day list: last 14 working days plus any other day that has a page (e.g. the categorised example page from August).
  const days = useMemo(() => {
    const set = new Set<string>(lastWorkingDays(site.id, s.today, 14));
    for (const c of s.cashDays) if (c.siteId === site.id) set.add(c.day);
    return [...set].sort();
  }, [s.cashDays, s.today, site.id]);
  const idx = days.indexOf(day);
  const pick = (d: string) => { setDay(d); setLineId(null); };
  const cd = cashDayFor(s, site.id, day);
  const sum = cd ? cashSummary(s, cd.id) : undefined;
  const me = s.users.find((u) => u.id === s.session?.userId); const cashierName = me?.name ?? 'Cashier';
  const signerUser = cd?.signedBy ? s.users.find((u) => u.id === cd.signedBy) : undefined;
  const signer = cd?.signedBy ? signerUser?.name ?? cd.signedBy : undefined;
  const isOpen = !cd || cd.status !== 'closed';
  // Tolerance for the count and the category names are master data (Settings), never written here.
  const threshold = useSiteSettings().cashVariance; const categoryLabel = useExpenseCategories().label;
  const vTone = sum?.variance !== undefined ? varianceTone(sum.variance, threshold) : 'ok';

  const dayOptions = useMemo(() => [...days].reverse().map((d) => {
    const c = s.cashDays.find((x) => x.siteId === site.id && x.day === d);
    return { value: d, label: `${fmtDay(d)}${d.slice(0, 4) !== s.today.slice(0, 4) || d.slice(0, 7) < s.today.slice(0, 7) ? ` ${d.slice(0, 4)}` : ''}`, sub: d === s.today ? 'today' : undefined, words: `${d} ${fmtDayLong(d)}`, left: <CalendarDays className="size-4 text-[var(--text-3)]" />, right: <span className="t-sm text-[var(--text-2)]"><Status tone={!c ? 'neutral' : c.status === 'closed' ? 'ok' : c.status === 'reopened' ? 'warn' : 'accent'}>{!c ? 'No page' : c.status === 'closed' ? 'Closed' : c.status === 'reopened' ? 'Reopened' : 'Open'}</Status></span> };
  }), [days, s.cashDays, s.today, site.id]);

  const entries = useMemo(() => [...(sum?.entries ?? [])].sort((a, b) => stampOf(a.at).localeCompare(stampOf(b.at))), [sum?.entries]);
  const sales = entries.filter((e) => e.category === 'sale');
  const receipts = entries.filter((e) => e.direction === 'in' && e.category !== 'sale');
  const expenses = entries.filter((e) => e.direction === 'out' && e.category !== 'wages' && e.category !== 'payout');
  const wages = entries.filter((e) => e.category === 'wages' || e.category === 'payout');
  const outLines = entries.filter((e) => e.direction === 'out');
  const usdIn = entries.filter((e) => e.currency === 'USD' && e.direction === 'in').reduce((t, e) => t + e.amount, 0);
  const usdOut = entries.filter((e) => e.currency === 'USD' && e.direction === 'out').reduce((t, e) => t + e.amount, 0);
  const hasUsd = entries.some((e) => e.currency === 'USD');
  const moneyIn = sum ? sum.sales + sum.receipts : 0; const moneyOut = sum ? sum.expenses + sum.wages : 0;

  // The ledger in page order, with the balance after every line.
  const groups: LedgerGroup[] = [];
  if (cd && sum) {
    let bal = cd.opening;
    const add = (key: string, title: string, sign: 1 | -1, list: typeof entries, total: number, none: string) => groups.push({ key, title, sign, total, none, rows: list.map((e) => { bal += sign * e.amount; return { e, balance: bal }; }) });
    add('sales', 'Cash sales', 1, sales, sum.sales, 'None recorded');
    add('receipts', 'Other receipts', 1, receipts, sum.receipts, 'None recorded');
    add('expenses', 'Expenses', -1, expenses, sum.expenses, 'None yet');
    add('wages', 'Wages and payouts', -1, wages, sum.wages, 'None paid');
  }
  const line = lineId ? groups.flatMap((g) => g.rows).find((r) => r.e.id === lineId) : undefined;

  // Where the money went: the day, and the month up to and including the day.
  const mtd = useMemo(() => {
    const month = day.slice(0, 7); const ids = new Set(s.cashDays.filter((c) => c.siteId === site.id && c.day.startsWith(month) && c.day <= day).map((c) => c.id));
    const list = s.cashEntries.filter((e) => ids.has(e.cashDayId) && e.direction === 'out');
    return { total: list.reduce((t, e) => t + e.amount, 0), days: ids.size };
  }, [s.cashDays, s.cashEntries, site.id, day]);
  const trend = useMemo(() => dailySeries(s, site.id, lastWorkingDays(site.id, day, 7)), [s, site.id, day]);
  const photos = outLines.filter((e) => e.hasReceiptPhoto).length;

  const onClose = (counted: number, denominations: Record<string, number>) => {
    if (!cd) return; const { variance } = closeCashDay(cd.id, counted, denominations); setPanel('none');
    const tone = varianceTone(variance, threshold);
    toast({ kind: tone === 'ok' ? 'ok' : 'warn', title: tone === 'ok' ? 'Day closed and signed · balanced' : `Day closed · ${variance < 0 ? 'short' : 'over'} by ${fmtUGX(Math.abs(variance))}`, detail: tone === 'bad' ? 'Exception raised for the Site Manager.' : tone === 'warn' ? 'Within tolerance · noted in the audit log.' : `${fmtUGX(counted)} carried forward to tomorrow.` });
  };
  const exportCsv = () => {
    if (!cd) return;
    downloadCsv(`cash-page-${site.id}-${day}`, ['Section', 'Time', 'Paid to or received from', 'Category', 'Kind', 'Machine', 'Receipt photo', 'In', 'Out', 'Balance'], [
      ['Opening balance', '', '', '', '', '', '', '', '', cd.opening],
      ...groups.flatMap((g) => g.rows.map(({ e, balance }) => [g.title, timeOf(e.at), e.payee ?? e.note ?? '', categoryLabel(e.category), e.subcategory ?? '', s.machines.find((m) => m.id === e.machineId)?.code ?? '', e.hasReceiptPhoto ? 'yes' : 'no', e.direction === 'in' ? e.amount : '', e.direction === 'out' ? e.amount : '', balance])),
      ['Expected closing', '', '', '', '', '', '', moneyIn, moneyOut, sum?.expectedClosing ?? ''],
    ]);
    toast({ kind: 'ok', title: `cash-page-${site.id}-${day}.csv downloaded` });
  };

  const statusBadge = !cd ? <Badge tone="neutral">Not opened</Badge>
    : cd.status === 'closed' ? <Badge tone="ok" dot>Closed by {signer} at {cd.closedAt ? timeOf(cd.closedAt) : '—'}</Badge>
    : cd.status === 'reopened' ? <Badge tone="warn" dot>Reopened · sign again</Badge>
    : <Badge tone="accent" dot>Open · {entries.length} {entries.length === 1 ? 'line' : 'lines'}</Badge>;
  const figures: { label: string; value?: number; sub: ReactNode; strong?: boolean }[] = [
    { label: 'Opening balance', value: cd?.opening, sub: 'Carried forward from the last signed close' },
    { label: 'Money in', value: sum ? moneyIn : undefined, sub: sum ? `Sales ${compact(sum.sales)} · receipts ${compact(sum.receipts)}` : 'No page for this day' },
    { label: 'Money out', value: sum ? moneyOut : undefined, sub: sum ? `Expenses ${compact(sum.expenses)} · wages ${compact(sum.wages)}` : 'No page for this day' },
    { label: 'Expected closing', value: sum?.expectedClosing, strong: true, sub: cd?.countedClosing !== undefined && sum?.variance !== undefined ? `Counted ${fmtNum(cd.countedClosing)} · ${sum.variance === 0 ? 'balanced' : `${sum.variance > 0 ? 'over' : 'short'} ${fmtNum(Math.abs(sum.variance))}`}` : 'What the drawer should hold now' },
  ];

  return (
    <div className="flex flex-col gap-[var(--gap)]">
      <style>{PRINT_CSS}</style>

      <div className="no-print">
        <PageHeader title="Cash book" meta={statusBadge}
          subtitle={`${site.name} · ${fmtDayLong(day)}${sum ? ` · ${entries.length} ${entries.length === 1 ? 'line' : 'lines'}` : ' · no page yet'}`}
          actions={<>
            <Button icon={ChevronLeft} aria-label="Previous day" title="Previous day" hotkey={key('[')} hotkeyLabel="Previous day" disabled={idx <= 0} onClick={() => pick(days[idx - 1])} />
            <div className="w-[204px]"><Combobox options={dayOptions} value={day} onChange={(v) => { if (v) pick(v); }} placeholder="Pick a day" /></div>
            <Button icon={ChevronRight} aria-label="Next day" title="Next day" hotkey={key(']')} hotkeyLabel="Next day" disabled={idx < 0 || idx >= days.length - 1} onClick={() => pick(days[idx + 1])} />
            {day !== s.today && <Button hotkey={key('t')} hotkeyLabel="Back to today" onClick={() => pick(s.today)}>Today</Button>}
            <Button variant="secondary" icon={Plus} hotkey={key('r')} hotkeyLabel="Add receipt" disabled={!isOpen} onClick={() => setPanel('receipt')}>Add receipt</Button>
            <Button variant="secondary" icon={Receipt} hotkey={key('n')} hotkeyLabel="Record expense" onClick={() => go.site('cash/expense')}>Record expense</Button>
            {cd && isOpen && <Button variant="primary" icon={Lock} hotkey={key('c')} hotkeyLabel="Close day" onClick={() => setPanel('close')}>Count and close day</Button>}
            {cd && !isOpen && <Button icon={Unlock} hotkey={key('o')} hotkeyLabel="Reopen day" onClick={() => setPanel('reopen')}>Reopen day</Button>}
          </>} />
        <div className="surface grid grid-cols-2 xl:grid-cols-4 divide-x divide-[var(--line)] max-xl:[&>*:nth-child(3)]:border-l-0 max-xl:[&>*:nth-child(n+3)]:border-t max-xl:[&>*:nth-child(n+3)]:border-[var(--line)]">
          {figures.map((f) => (
            <div key={f.label} className="px-4 py-3 min-w-0">
              <div className="t-sm text-[var(--text-3)]">{f.label}</div>
              <div className={cn('num text-[18px] mt-0.5 whitespace-nowrap', f.strong ? 'font-bold' : 'font-semibold')}>{f.value === undefined ? <span className="text-[var(--text-3)]">—</span> : fmtUGX(f.value)}</div>
              <div className="t-xs text-[var(--text-3)] truncate mt-0.5">{f.sub}</div>
            </div>
          ))}
        </div>
        {mtd.total > 0 && <p className="t-sm text-[var(--text-2)] mt-2">Month to date: {fmtUGX(mtd.total)} paid out over {mtd.days} {mtd.days === 1 ? 'day' : 'days'} to {fmtDayShort(day)}.</p>}
      </div>

      {!isOpen && cd && (
        <Note tone="ok" icon={Lock} className="items-center no-print">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="flex-1 min-w-[240px]"><strong>Closed and signed</strong> by {signer} at {cd.closedAt ? timeOf(cd.closedAt) : '—'}. The page is locked: {fmtUGX(cd.countedClosing ?? 0)} carried forward{sum?.variance !== undefined && sum.variance !== 0 && <>, variance <span className={cn('num font-semibold', vTone === 'bad' ? 'text-[var(--bad)]' : 'text-[var(--warn)]')}>{sum.variance > 0 ? '+' : '−'}{fmtNum(Math.abs(sum.variance))}</span></>}.</span>
            <Button size="sm" icon={Unlock} onClick={() => setPanel('reopen')}>Reopen with Site Manager PIN</Button>
          </div>
        </Note>
      )}
      {cd?.status === 'reopened' && <Note tone="warn" icon={Unlock} className="no-print"><strong>Reopened</strong> by the Site Manager. Add the missing line, then close and sign again.</Note>}

      <div className="grid gap-[var(--gap)] xl:grid-cols-[minmax(0,1fr)_360px] items-start">
        <div className="flex flex-col gap-[var(--gap)] min-w-0">
          {!cd || !sum ? (
            <Panel icon={ScrollText} title="Ledger" sub={fmtDay(day)}>
              <Empty icon={Wallet} title={`No page for ${fmtDay(day)}`} hint={day > s.today ? 'This day has not happened yet.' : 'Record the first receipt or expense and the page opens with the carried-forward balance.'}
                action={day <= s.today ? <div className="flex flex-wrap justify-center gap-2"><Button icon={Plus} onClick={() => setPanel('receipt')}>Add receipt</Button><Button icon={Receipt} variant="primary" onClick={() => go.site('cash/expense')}>New expense</Button></div> : undefined} />
            </Panel>
          ) : (
            <Panel icon={ScrollText} title="Ledger" sub={`${entries.length} ${entries.length === 1 ? 'line' : 'lines'} · UGX`} pad={false} className="print-area"
              actions={<span className="flex items-center gap-1 no-print"><Button size="sm" variant="ghost" icon={Download} hotkey={key('e')} hotkeyLabel="Export the ledger as CSV" onClick={exportCsv}>CSV</Button><Button size="sm" variant="ghost" icon={Printer} hotkey={key('p')} hotkeyLabel="Print the page" onClick={() => window.print()}>Print</Button></span>}
              footer={<div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1"><span>{fmtDayLong(day)} · {site.legalName}</span><span className="no-print text-[var(--text-3)]">Click any row in the ledger to inspect full details.</span></div>}>
              <LedgerTable opening={cd.opening} groups={groups} expected={sum.expectedClosing} counted={cd.countedClosing} variance={sum.variance} threshold={threshold} machines={s.machines} onOpen={setLineId} selectedId={lineId}
                note={hasUsd ? <>USD lines: in {fmtUSD(usdIn)} · out {fmtUSD(usdOut)} · at {fmtNum(s.fx)} UGX/USD</> : undefined} />
            </Panel>
          )}

          <Panel icon={LineChartIcon} title="Cash in and out" sub={`UGX · 7 working days to ${fmtDayShort(day)}`} className="no-print">
            <LineChart height={176} labels={trend.map((d) => fmtDayShort(d.day))} partialLast={day === s.today && isOpen} series={[{ name: 'Cash in', color: 'var(--series-3)', data: trend.map((d) => d.cashIn), area: true }, { name: 'Cash out', color: 'var(--series-2)', data: trend.map((d) => d.cashOut) }]} />
          </Panel>
        </div>

        <aside className="grid gap-[var(--gap)] md:grid-cols-2 xl:grid-cols-1 min-w-0 no-print">
          <Panel icon={ShieldCheck} title="Day status" actions={<span className="pr-1.5">{!cd ? <Badge tone="neutral">Not opened</Badge> : cd.status === 'closed' ? <Badge tone="ok" dot>Closed</Badge> : cd.status === 'reopened' ? <Badge tone="warn" dot>Reopened</Badge> : <Badge tone="accent" dot>Open</Badge>}</span>}>
            {!cd ? <div className="t-sm text-[var(--text-3)]">The page opens with the first receipt or expense of the day.</div> : cd.status === 'closed' ? (
              <div className="flex flex-col gap-2.5">
                <div className="flex items-center gap-2.5"><Avatar name={signer ?? '?'} seed={signerUser?.avatarSeed} size={32} ring="ok" /><div className="min-w-0 flex-1"><div className="t-base font-semibold truncate">{signer}</div><div className="t-sm text-[var(--text-3)]">Signed the count at {cd.closedAt ? timeOf(cd.closedAt) : '—'}</div></div><CheckCircle2 className="size-5 text-[var(--ok)] shrink-0" /></div>
                <div className="border-t border-[var(--line)] pt-1.5">
                  <div className="flex items-center justify-between h-6 t-sm"><span className="text-[var(--text-2)]">Counted</span><span className="num font-medium">{fmtUGX(cd.countedClosing ?? 0)}</span></div>
                  <div className="flex items-center justify-between h-6 t-sm"><span className="text-[var(--text-2)]">Variance</span><Badge tone={vTone} dot>{!sum?.variance ? 'Balanced' : `${sum.variance > 0 ? 'Over' : 'Short'} ${fmtNum(Math.abs(sum.variance))}`}</Badge></div>
                  <div className="flex items-center justify-between h-6 t-sm"><span className="text-[var(--text-2)]">Tolerance</span><span className="num text-[var(--text-3)]">± {fmtNum(threshold)}</span></div>
                </div>
                <Button block icon={Unlock} onClick={() => setPanel('reopen')}>Reopen · Site Manager PIN</Button>
              </div>
            ) : (
              <div className="flex flex-col gap-2.5">
                <div className="flex items-center gap-2.5"><span className="size-8 rounded-lg grid place-items-center shrink-0" style={{ background: `color-mix(in srgb, ${cd.status === 'reopened' ? 'var(--warn)' : 'var(--accent)'} 14%, transparent)`, color: cd.status === 'reopened' ? 'var(--warn)' : 'var(--accent-strong)' }}><Unlock className="size-4" /></span><div className="min-w-0 flex-1"><div className="t-base font-semibold">{cd.status === 'reopened' ? 'Reopened, waiting for a new signature' : 'Open, not yet counted'}</div><div className="t-sm text-[var(--text-3)]">{entries.length} {entries.length === 1 ? 'line' : 'lines'} so far · {cashierName} signs</div></div></div>
                <div className="border-t border-[var(--line)] pt-1.5">
                  <div className="flex items-center justify-between h-6 t-sm"><span className="text-[var(--text-2)]">Expected in drawer</span><span className="num font-medium">{fmtUGX(sum?.expectedClosing ?? 0)}</span></div>
                  <div className="flex items-center justify-between h-6 t-sm"><span className="text-[var(--text-2)]">Tolerance</span><span className="num text-[var(--text-3)]">± {fmtNum(threshold)}</span></div>
                </div>
                <Button block variant="primary" icon={Lock} onClick={() => setPanel('close')}>Count and close the day</Button>
              </div>
            )}
          </Panel>

          <div className="grid grid-cols-2 gap-[var(--gap)] content-start">
            <Stat label="Uncategorised" icon={CheckCircle2} tone="ok" value={0} sub="Category is required" />
            <Stat label="Receipt photos" icon={Camera} accent="var(--series-1)" value={outLines.length ? <>{photos}<span className="text-[var(--text-3)] font-medium">/{outLines.length}</span></> : '—'} sub={outLines.length ? `${Math.round((photos / outLines.length) * 100)}% of money out` : 'No money out yet'} />
          </div>
        </aside>
      </div>

      {cd && sum && <CloseDaySheet open={panel === 'close'} onClose={closePanel} expected={sum.expectedClosing} threshold={threshold} cashierName={cashierName} dayLabel={fmtDay(day)} onConfirm={onClose} />}
      <ReopenSheet open={panel === 'reopen'} dayLabel={fmtDay(day)} onClose={closePanel} onReopen={(reason) => { if (!cd) return; reopenCashDay(cd.id, reason); setPanel('none'); toast({ kind: 'warn', title: `${fmtDay(day)} reopened`, detail: reason }); }} />
      <ReceiptSheet open={panel === 'receipt'} dayLabel={fmtDay(day)} onClose={closePanel} onSave={(amount, payee) => { addCashEntry({ siteId: site.id, day, direction: 'in', category: 'receipt', amount, payee, hasReceiptPhoto: false }); setPanel('none'); toast({ kind: 'ok', title: `Receipt ${fmtUGX(amount)} recorded`, detail: payee }); }} />
      <LineSheet row={line} onClose={() => setLineId(null)} machines={s.machines} users={s.users} fx={s.fx} />
    </div>
  );
}

/* ------------------------------------------------------------------ Reopen (reason, then PIN) */
function ReopenSheet({ open, onClose, onReopen, dayLabel }: { open: boolean; onClose: () => void; onReopen: (reason: string) => void; dayLabel: string }) {
  const [reason, setReason] = useState(''); const [wrong, setWrong] = useState(false); const [step, setStep] = useState<'reason' | 'pin'>('reason');
  const toast = useStore((x) => x.toast);
  const ready = reason.trim().length >= 4;
  const leave = () => { setReason(''); setWrong(false); setStep('reason'); };
  const submit = useCallback((pin: string) => { if (pin === SITE_MANAGER_PIN) { onReopen(reason.trim()); leave(); } else { setWrong(true); toast({ kind: 'error', title: 'Wrong PIN', detail: 'Only the Site Manager can reopen a signed day.' }); } }, [reason, onReopen, toast]);
  return (
    <Sheet open={open} onClose={() => { leave(); onClose(); }} title="Reopen a signed day" sub={dayLabel} width={440}>
      <Note tone="warn" className="mb-3">Reopening is logged in the audit trail with your reason. The day must be closed and signed again afterwards.</Note>
      <Field label="1 · Why is it being reopened?" hint="At least a few words, e.g. “Fuel receipt found in the drawer”. Press Enter to go on to the PIN.">
        <Input value={reason} onChange={(e) => { setReason(e.target.value); setStep('reason'); }} onKeyDown={(e) => { if (e.key === 'Enter' && ready) { e.preventDefault(); setStep('pin'); } }} placeholder="Reason" data-autofocus="" />
      </Field>
      <div className="mt-4 pt-4 border-t border-[var(--line)]">
        <div className="t-sm font-medium text-[var(--text-2)] mb-2">2 · Site Manager PIN</div>
        {step === 'pin' && ready ? <PinPad title="Site Manager PIN" error={wrong ? 'Wrong PIN. Try again.' : undefined} onSubmit={submit} />
          : <div className="flex flex-col items-center gap-2 py-3"><div className="t-sm text-[var(--text-3)] text-center">{ready ? 'Hand the keyboard to the Site Manager.' : 'Give a reason first.'}</div><Button variant="primary" icon={ShieldCheck} disabled={!ready} onClick={() => setStep('pin')}>Enter the PIN</Button></div>}
      </div>
    </Sheet>
  );
}

/* ------------------------------------------------------------------ Quick receipt (money in) */
function ReceiptSheet({ open, onClose, onSave, dayLabel }: { open: boolean; onClose: () => void; onSave: (amount: number, payee: string) => void; dayLabel: string }) {
  const [amount, setAmount] = useState(''); const [payee, setPayee] = useState('');
  const n = parseInt(amount || '0', 10);
  const ok = n > 0 && !!payee.trim();
  const save = () => { if (ok) { onSave(n, payee.trim()); setAmount(''); setPayee(''); } };
  return (
    <Sheet open={open} onClose={onClose} title="Add a receipt" sub={`Money in · ${dayLabel}`} width={440}
      footer={<><span className="mr-auto t-sm text-[var(--text-3)]">{!payee.trim() ? 'Say who paid' : !(n > 0) ? 'Enter the amount' : 'Ready to record'}</span><Button onClick={onClose}>Cancel</Button><Button variant="primary" icon={Plus} disabled={!ok} hotkey="mod+enter" hotkeyLabel="Record the receipt" onClick={save}>Record {n > 0 ? fmtUGX(n) : 'receipt'}</Button></>}>
      <Field label="Received from" className="mb-3"><Input value={payee} onChange={(e) => setPayee(e.target.value)} placeholder="Name or reason, e.g. Client deposit" data-autofocus="" /></Field>
      <div onKeyDown={(e) => { if (e.key === 'Enter' && !(e.ctrlKey || e.metaKey) && ok) { e.preventDefault(); save(); } }}><NumberPad value={amount} onChange={setAmount} suffix="UGX" label="Amount" max={999_999_999} /></div>
    </Sheet>
  );
}
