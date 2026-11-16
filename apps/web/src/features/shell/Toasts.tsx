import { CheckCircle2, AlertTriangle, Info, X, XCircle } from 'lucide-react';
import { useStore } from '@/data/store';
import { cn } from '@/components/ui';

/** On a phone a pile of toasts covers the page: only the newest few show; older ones stay queued until those go. */
export const MAX_TOASTS = 2;

export function Toasts() {
  const toasts = useStore((s) => s.toasts); const dismiss = useStore((s) => s.dismissToast);
  return (
    <div className="fixed z-[90] flex flex-col gap-1.5 w-[min(380px,calc(100vw-24px))] bottom-[calc(var(--statusbar-h)+12px)] right-3 touch:right-auto touch:left-1/2 touch:-translate-x-1/2 touch:bottom-20" role="status" aria-live="polite">
      {toasts.slice(-MAX_TOASTS).map((t) => {
        const I = t.kind === 'ok' ? CheckCircle2 : t.kind === 'warn' ? AlertTriangle : t.kind === 'error' ? XCircle : Info;
        const col = t.kind === 'ok' ? 'var(--ok)' : t.kind === 'error' ? 'var(--bad)' : t.kind === 'warn' ? 'var(--warn)' : 'var(--info)';
        return (
          <button key={t.id} onClick={() => dismiss(t.id)} className={cn('flex items-start gap-2.5 text-left px-3 py-2.5 rounded-md bg-[var(--surface)] border border-[var(--line-2)] shadow-[var(--shadow-pop)] anim-up')} style={{ borderLeft: `3px solid ${col}` }}>
            <I className="size-4 shrink-0 mt-0.5" style={{ color: col }} />
            <div className="min-w-0 flex-1"><div className="t-base font-medium">{t.title}</div>{t.detail && <div className="t-sm text-[var(--text-2)]">{t.detail}</div>}</div>
            <X className="size-4 shrink-0 mt-0.5 text-[var(--text-3)]" aria-label="Dismiss" />
          </button>
        );
      })}
    </div>
  );
}
