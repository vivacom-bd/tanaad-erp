import { useEffect, useRef, useState } from 'react';
import { CloudAlert } from 'lucide-react';
import { actionName } from '@/data/api/names';
import { IconButton } from '@/components/ui';
import { canRetry, dismissRejected, isReal, retryRejected, useSync } from '@/data/api/sync';

/** Real mode only: a count badge for outbox items the server rejected, opening a list with Dismiss / Retry. */
export function SyncIssues() {
  const rejected = useSync((s) => s.rejected); const pending = useSync((s) => s.pending);
  const [open, setOpen] = useState(false); const box = useRef<HTMLDivElement>(null);
  useEffect(() => { if (!open) return; const away = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false); }; window.addEventListener('mousedown', away); return () => window.removeEventListener('mousedown', away); }, [open]);
  if (!isReal() || (rejected.length === 0 && !open)) return pending > 0 && isReal() ? <span className="app-no-drag t-xs text-[var(--text-3)] tabular-nums" title="Waiting to sync">{pending} queued</span> : null;
  return (
    <div ref={box} className="relative app-no-drag">
      <IconButton icon={CloudAlert} label="Sync problems" active={open} onClick={() => setOpen((v) => !v)} />
      {rejected.length > 0 && <span className="absolute top-0.5 right-0.5 min-w-[15px] h-[15px] px-1 rounded-full bg-[var(--warn)] text-white text-[10px] font-semibold grid place-items-center pointer-events-none">{rejected.length}</span>}
      {open && (
        <div className="absolute right-0 top-[calc(100%+6px)] max-sm:fixed max-sm:inset-x-2 max-sm:right-2 max-sm:top-14 max-sm:w-auto z-50 w-[380px] max-w-[calc(100vw-16px)] bg-[var(--surface)] border border-[var(--line-2)] rounded-lg shadow-[var(--shadow-pop)] anim-pop overflow-hidden">
          <div className="px-3 h-10 flex items-center justify-between border-b border-[var(--line)]"><span className="t-base font-semibold">Not accepted by the server</span><span className="t-xs text-[var(--text-3)]">{pending} still queued</span></div>
          {rejected.length === 0 ? <div className="px-3 py-7 text-center t-sm text-[var(--text-3)]">Nothing rejected.</div> : (
            <div className="max-h-[360px] overflow-y-auto scrollbar-thin">
              {rejected.map((r) => (
                <div key={r.key} className="px-3 py-2 border-b border-[var(--line)] last:border-b-0">
                  <div className="flex items-baseline justify-between gap-2"><span className="t-base font-medium">{actionName(r.envelope.type)}</span><span className="t-xs text-[var(--text-3)] shrink-0">{r.siteCode} · {r.code}</span></div>
                  <div className="t-sm text-[var(--text-2)]">{r.reason}</div>
                  <div className="flex gap-3 mt-1 t-sm">
                    <button className="text-[var(--text-2)] hover:underline" onClick={() => void dismissRejected(r.key!)}>Dismiss</button>
                    {canRetry(r) && <button className="text-[var(--accent)] hover:underline" onClick={() => void retryRejected(r.key!)}>Retry</button>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
