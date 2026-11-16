// Real mode: orders that wait for a group decision, from GET /group/approvals. Approve and Hold call the order endpoints (online-only).
import { useEffect, useState } from 'react';
import { Check, Pause, ShoppingCart } from 'lucide-react';
import { useStore } from '@/data/store';
import { Badge, Button, Empty, Input, Panel } from '@/components/ui';
import { SITES } from '@/config/sites';
import { fmtMoney, fmtDateTime } from '@/data/format';
import { refreshGroupExceptions, syncNow } from '@/data/api/sync';
import { decideOrderOnServer, loadApprovals, useServerGroup, writeError } from '@/data/api/group';
import { SiteBadge, plural } from './shared';

export function ApprovalOrders() {
  const toast = useStore((s) => s.toast);
  const orders = useServerGroup((s) => s.approvalOrders); const loaded = useServerGroup((s) => s.approvalsLoaded);
  const [open, setOpen] = useState<{ id: string; verb: 'approved' | 'held' } | null>(null); const [reason, setReason] = useState(''); const [busy, setBusy] = useState(false); const [err, setErr] = useState('');
  useEffect(() => { loadApprovals().catch(() => toast({ kind: 'warn', title: 'Could not load orders waiting for approval' })); }, [toast]);
  if (loaded && orders.length === 0) return null;
  const confirm = async () => {
    const o = orders.find((x) => x.id === open?.id); if (!o || !open) return;
    if (open.verb === 'held' && !reason.trim()) { setErr('Add a short note. A hold needs a reason.'); return; }
    setBusy(true); setErr('');
    try {
      await decideOrderOnServer(o.siteCode, o.id, open.verb, reason);
      toast({ kind: open.verb === 'approved' ? 'ok' : 'warn', title: `${o.number} ${open.verb === 'approved' ? 'approved' : 'on hold'}`, detail: reason.trim() || undefined });
      setOpen(null); setReason('');
      await Promise.all([loadApprovals(), refreshGroupExceptions().catch(() => undefined)]); void syncNow();
    } catch (e) { setErr(writeError(e)); } finally { setBusy(false); }
  };
  return (
    <Panel pad={false} icon={ShoppingCart} title="Orders waiting for approval" sub={plural(orders.length, 'order')}>
      {orders.length === 0 ? <div className="p-[var(--pad)]"><Empty title="Loading orders" /></div> : orders.map((o) => (
        <div key={o.id} className="px-[var(--pad)] py-2.5 border-b border-[var(--line)] last:border-b-0">
          <div className="flex items-center gap-2 flex-wrap">
            <SiteBadge site={o.siteCode} />
            <span className="code">{o.number || 'No number yet'}</span>
            <span className="t-base font-medium flex-1 min-w-0 truncate">{o.clientName ?? 'Client not shown'}</span>
            <span className="num t-base font-semibold">{o.total === null ? <span className="text-[var(--text-4)]" title="The server did not send a total">—</span> : fmtMoney(o.total, o.currency as 'UGX' | 'USD')}</span>
            {open?.id !== o.id && <>
              <Button size="sm" variant="ok" icon={Check} onClick={() => { setOpen({ id: o.id, verb: 'approved' }); setReason(''); setErr(''); }}>Approve</Button>
              <Button size="sm" icon={Pause} onClick={() => { setOpen({ id: o.id, verb: 'held' }); setReason(''); setErr(''); }}>Hold</Button>
            </>}
          </div>
          <div className="t-xs text-[var(--text-3)] mt-0.5">{SITES[o.siteCode].name}{o.createdAt ? ` · raised ${fmtDateTime(o.createdAt.replace(' ', 'T') + (o.createdAt.includes('Z') ? '' : 'Z'))}` : ''}</div>
          {open?.id === o.id && (
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <Input value={reason} aria-label="Reason" aria-invalid={!!err || undefined} onChange={(e) => { setReason(e.target.value); setErr(''); }} className="flex-1 min-w-[200px]" placeholder={open.verb === 'held' ? 'Note required: what has to happen first?' : 'Why you are approving it (optional)'} />
              <Button variant="ghost" onClick={() => setOpen(null)} disabled={busy}>Cancel</Button>
              <Button variant={open.verb === 'approved' ? 'ok' : 'secondary'} disabled={busy} onClick={confirm}>{busy ? 'Saving' : open.verb === 'approved' ? 'Confirm approve' : 'Confirm hold'}</Button>
              {err && <div role="alert" className="basis-full t-xs text-[var(--bad)]">{err}</div>}
              {open.verb === 'approved' && <Badge tone="neutral">The server checks the credit limit and the client</Badge>}
            </div>
          )}
        </div>
      ))}
    </Panel>
  );
}
