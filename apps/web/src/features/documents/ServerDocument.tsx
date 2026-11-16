import { useState } from 'react';
import { FileText, Printer } from 'lucide-react';
import { Button, Note, Sheet } from '@/components/ui';
import { isReal } from '@/data/api/sync';
import { documentTitle, renderDocument, type DocumentKind, type ServerDocument } from '@/data/api/documents';
import { fmtDateTime, fmtNum } from '@/data/format';

type Obj = Record<string, unknown>;
const rec = (v: unknown): Obj => (v && typeof v === 'object' ? (v as Obj) : {});
const arr = (v: unknown): Obj[] => (Array.isArray(v) ? (v as Obj[]) : []);
const txt = (v: unknown) => (v === null || v === undefined || v === '' ? '' : String(v));
const n = (v: unknown) => fmtNum(Number(v ?? 0));
const when = (v: unknown) => (txt(v) ? fmtDateTime(txt(v).includes('T') ? txt(v) : txt(v).replace(' ', 'T') + 'Z') : '');
const PRINT = '@media print { body * { visibility: hidden !important; } .print-area, .print-area * { visibility: visible !important; } .print-area { position: absolute !important; left: 0; top: 0; width: 100%; box-shadow: none !important; border: 0 !important; } .no-print { display: none !important; } }';

const L = ({ children }: { children: React.ReactNode }) => <div className="font-sans uppercase tracking-[0.08em] text-neutral-500 text-[0.82em] font-medium">{children}</div>;
const Row = ({ k, v, strong }: { k: string; v: React.ReactNode; strong?: boolean }) => <div className="flex justify-between gap-3 py-[3px] border-b border-dashed border-neutral-300 last:border-0"><span className="text-neutral-500 font-sans">{k}</span><span className={strong ? 'text-right font-bold' : 'text-right'}>{v || '—'}</span></div>;

/** Company letterhead from the server's company profile. A blank detail prints as a visible gap. */
function Letterhead({ doc, title, no }: { doc: ServerDocument; title: string; no?: string }) {
  const c = rec(doc.company); const blank = <span className="italic text-neutral-400">to be provided</span>;
  return (
    <div className="pb-2.5 border-b-2 border-neutral-800">
      <div className="font-sans font-black tracking-[0.18em] italic leading-none t-lg">TANAAD</div>
      <div className="font-sans text-neutral-600 t-xs mt-0.5 font-medium">{txt(c.name)}</div>
      <div className="font-sans text-neutral-500 t-xs leading-snug mt-0.5">
        {([['Address', c.address], ['Tel', c.phone], ['Email', c.email], ['TIN', c.tin]] as const).map(([k, v]) => <div key={k}>{k} {txt(v) ? <span className="text-neutral-700">{txt(v)}</span> : blank}</div>)}
      </div>
      <div className="mt-2"><L>{title}</L>{no && <div className="font-bold t-lg leading-tight">{no}</div>}</div>
    </div>
  );
}

function DeliveryNote({ doc }: { doc: ServerDocument }) {
  const lines = arr(doc.lines); const t = rec(doc.totals); const r = rec(doc.receipt); const codes = Array.isArray(doc.bundle_codes) ? doc.bundle_codes.map(String) : [];
  return <>
    <Letterhead doc={doc} title="Delivery note" no={txt(doc.number)} />
    <div className="grid grid-cols-2 gap-3 py-2.5"><div><L>Deliver to</L><div className="font-bold">{txt(doc.client_name)}</div></div><div className="text-right"><L>Order</L><div className="font-bold">{txt(doc.order_ref)}</div><div className="text-neutral-600">{when(doc.issued_at)}</div></div></div>
    <table className="w-full mb-2"><thead><tr className="border-y border-neutral-400"><th className="text-left py-1 font-normal"><L>Item</L></th><th className="text-right font-normal"><L>Bundles</L></th><th className="text-right font-normal pl-2"><L>Std kg</L></th></tr></thead>
      <tbody>{lines.map((l, i) => <tr key={i} className="border-b border-dashed border-neutral-300"><td className="py-1">{txt(l.product_name) || txt(l.product_code)}</td><td className="text-right">{n(l.bundles)}</td><td className="text-right pl-2">{txt(l.std_bundle_weight_kg) ? n(l.std_bundle_weight_kg) : '—'}</td></tr>)}</tbody></table>
    <Row k="Bundles ordered / scanned" v={`${n(t.ordered_bundles)} / ${n(t.scanned_bundles)}`} /><Row k="Expected weight" v={txt(t.expected_kg) ? `${n(t.expected_kg)} kg` : ''} />
    <Row k="Gate scale" v={txt(t.gate_kg) ? `${n(t.gate_kg)} kg` : 'not weighed'} strong /><Row k="Variance" v={txt(t.variance_pct) ? `${t.variance_pct}%` : ''} />
    <Row k="Driver" v={txt(doc.driver)} /><Row k="Vehicle" v={txt(doc.vehicle)} /><Row k="Released" v={txt(doc.released_via)} />
    {txt(r.received_by) && <Row k="Received by" v={`${txt(r.received_by)} · ${n(r.bundles_received)} bundles${txt(r.delivered_at) ? ` · ${when(r.delivered_at)}` : ''}`} />}
    {codes.length > 0 && <div className="mt-2"><L>Bundle labels</L><div className="text-neutral-600 break-words">{codes.join(', ')}</div></div>}
    <div className="grid grid-cols-2 gap-3 mt-6 font-sans t-xs text-neutral-500"><div className="border-t border-neutral-500 pt-1">Driver signature</div><div className="border-t border-neutral-500 pt-1">{txt(r.signature_hash) ? 'Receiver signed' : 'Receiver signature'}</div></div>
  </>;
}

function Invoice({ doc }: { doc: ServerDocument }) {
  const inv = rec(doc.invoice); const lines = arr(doc.lines); const c = rec(doc.company); const cur = txt(inv.currency);
  return <>
    <Letterhead doc={doc} title="Invoice" no={txt(inv.number)} />
    <div className="py-2"><Row k="Order" v={txt(inv.order_number)} /><Row k="Issued" v={when(inv.issued_at) || txt(inv.issued_day)} /></div>
    {lines.length > 0 && <table className="w-full mb-2"><tbody>{lines.map((l, i) => <tr key={i} className="border-b border-dashed border-neutral-300"><td className="py-1">{txt(l.description) || txt(l.product_name)}</td><td className="text-right">{txt(l.quantity ?? l.bundles)}</td><td className="text-right pl-2">{txt(l.amount ?? l.total) ? n(l.amount ?? l.total) : ''}</td></tr>)}</tbody></table>}
    <Row k={`Total ${cur}`} v={n(inv.total)} strong /><Row k={`Paid ${cur}`} v={n(inv.paid_amount)} /><Row k={`Balance ${cur}`} v={n(inv.balance)} strong />
    {Number(c.vat_rate_pct) > 0 && <Row k="VAT rate" v={`${c.vat_rate_pct}%`} />}
    {txt(inv.efris_fdn) && <Row k="EFRIS FDN" v={txt(inv.efris_fdn)} />}
    <div className="mt-3"><L>How to pay</L>{txt(c.bank_details) ? <div className="whitespace-pre-line">{txt(c.bank_details)}</div> : <span className="italic text-neutral-400">bank details to be provided</span>}{txt(c.mobile_money) && <div>Mobile money {txt(c.mobile_money)}</div>}</div>
  </>;
}

function Statement({ doc }: { doc: ServerDocument }) {
  const rows = arr(doc.invoices); const cur = txt(rows[0]?.currency);
  const total = rows.reduce((t, r) => t + Number(r.total ?? 0), 0); const bal = rows.reduce((t, r) => t + Number(r.balance ?? 0), 0);
  return <>
    <Letterhead doc={doc} title="Statement of account" />
    <table className="w-full my-2"><thead><tr className="border-y border-neutral-400"><th className="text-left py-1 font-normal"><L>Invoice</L></th><th className="text-left font-normal"><L>Issued</L></th><th className="text-right font-normal"><L>Total</L></th><th className="text-right font-normal pl-2"><L>Balance</L></th></tr></thead>
      <tbody>{rows.map((r, i) => <tr key={i} className="border-b border-dashed border-neutral-300"><td className="py-1">{txt(r.number)}</td><td>{txt(r.issued_day)}</td><td className="text-right">{n(r.total)}</td><td className="text-right pl-2">{n(r.balance)}</td></tr>)}</tbody>
      <tfoot><tr className="font-bold border-b border-neutral-400"><td className="py-1" colSpan={2}>Total {cur}</td><td className="text-right">{n(total)}</td><td className="text-right pl-2">{n(bal)}</td></tr></tfoot></table>
  </>;
}

function Payslip({ doc }: { doc: ServerDocument }) {
  const w = rec(doc.worker); const p = rec(doc.period); const t = rec(doc.totals); const pay = doc.payout ? rec(doc.payout) : null; const ded = arr(doc.deductions); const cur = txt(doc.currency);
  return <>
    <Letterhead doc={doc} title="Payslip" no={txt(w.number)} />
    <div className="py-2"><Row k="Worker" v={txt(w.name)} strong /><Row k="Period" v={`${txt(p.from).slice(0, 10)} to ${txt(p.to).slice(0, 10)}`} /><Row k="Rate" v={`${n(doc.rate)} ${cur} per ${txt(doc.rate_basis)}`} /><Row k="Units worked" v={n(doc.units)} /><Row k="Overtime hours" v={n(doc.overtime_hours)} /></div>
    <Row k={`Gross ${cur}`} v={n(t.gross)} /><Row k="Overtime" v={n(t.overtime)} /><Row k="Bonus" v={n(t.bonus)} />
    {ded.map((d, i) => <Row key={i} k={txt(d.label ?? d.kind ?? 'Deduction')} v={`−${n(d.amount)}`} />)}
    <Row k="Advances deducted" v={`−${n(t.advances_deducted)}`} /><Row k={`Net pay ${cur}`} v={n(t.net)} strong />
    <div className="mt-2 text-neutral-600">{pay ? `Paid ${n(pay.amount)} by ${txt(pay.method).replace('_', ' ')}${txt(pay.reference) ? ` · ref ${txt(pay.reference)}` : ''} on ${when(pay.paid_at)}` : 'Not paid yet.'}</div>
  </>;
}

const BODY: Record<DocumentKind, (p: { doc: ServerDocument }) => React.ReactNode> = { delivery_note: DeliveryNote, invoice: Invoice, statement: Statement, payslip: Payslip };

/**
 * Print or preview a document the server produced. Real mode only (renders nothing in demo mode, where each screen has its own paper view).
 * The server's data is laid out on a plain white sheet; Print sends only that sheet to paper.
 */
export function ServerDocumentButton({ kind, id, label, size = 'sm' }: { kind: DocumentKind; id: string | undefined; label?: string; size?: 'sm' | 'md' }) {
  const [open, setOpen] = useState(false); const [doc, setDoc] = useState<ServerDocument | null>(null); const [err, setErr] = useState(''); const [busy, setBusy] = useState(false);
  if (!isReal() || !id) return null;
  const show = async () => {
    setOpen(true); setBusy(true); setErr(''); setDoc(null);
    try { setDoc(await renderDocument(kind, id)); } catch (e) { setErr(e instanceof Error ? e.message : 'The document could not be produced.'); } finally { setBusy(false); }
  };
  const Body = BODY[kind];
  return <>
    <Button size={size} icon={FileText} onClick={(e) => { e.stopPropagation(); void show(); }}>{label ?? `Print ${documentTitle[kind].toLowerCase()}`}</Button>
    <Sheet open={open} onClose={() => setOpen(false)} title={documentTitle[kind]} width={560}
      footer={<><Button variant="ghost" onClick={() => setOpen(false)}>Close</Button><Button variant="primary" icon={Printer} disabled={!doc} onClick={() => window.print()}>Print</Button></>}>
      <style>{PRINT}</style>
      {busy && <div className="t-sm text-[var(--text-3)]">Getting the document from the server…</div>}
      {err && <Note tone="bad">{err}</Note>}
      {doc && <div className="print-area bg-white text-neutral-900 rounded-md border border-neutral-300 font-mono t-sm leading-snug p-4"><Body doc={doc} />{doc.content_hash && <div className="mt-3 font-sans t-xs text-neutral-400 break-all">Document hash {doc.content_hash}</div>}</div>}
    </Sheet>
  </>;
}
