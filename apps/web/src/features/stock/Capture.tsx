import { useRef, useState } from 'react';
import { Camera, Check, Eraser, PenLine } from 'lucide-react';
import { Button, cn } from '@/components/ui';
import { ApiError } from '@/data/api/http';
import { uploadMedia } from '@/data/api/media';

const why = (e: unknown) => (e instanceof ApiError ? (e.code === 'network' ? 'No connection. Try again when you are online.' : e.detail) : 'The file could not be saved.');

/** Real-mode signature: drawn on the screen, saved to Media, and handed back as its hash. */
export function SignaturePad({ label, onHash }: { label: string; onHash: (hash: string | null) => void }) {
  const canvas = useRef<HTMLCanvasElement>(null); const down = useRef(false);
  const [inked, setInked] = useState(false); const [busy, setBusy] = useState(false); const [saved, setSaved] = useState(false); const [error, setError] = useState<string | null>(null);
  const at = (e: React.PointerEvent<HTMLCanvasElement>) => { const c = canvas.current!; const r = c.getBoundingClientRect(); return { x: ((e.clientX - r.left) / r.width) * c.width, y: ((e.clientY - r.top) / r.height) * c.height }; };
  const start = (e: React.PointerEvent<HTMLCanvasElement>) => {
    if (saved) return; const ctx = canvas.current?.getContext('2d'); if (!ctx) return;
    e.currentTarget.setPointerCapture(e.pointerId); down.current = true; const p = at(e); ctx.lineWidth = 3; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111'; ctx.beginPath(); ctx.moveTo(p.x, p.y);
  };
  const move = (e: React.PointerEvent<HTMLCanvasElement>) => { if (!down.current) return; const ctx = canvas.current?.getContext('2d'); if (!ctx) return; const p = at(e); ctx.lineTo(p.x, p.y); ctx.stroke(); setInked(true); };
  const clear = () => { const c = canvas.current; c?.getContext('2d')?.clearRect(0, 0, c.width, c.height); setInked(false); setSaved(false); setError(null); onHash(null); };
  const save = () => {
    const c = canvas.current; if (!c || !inked) return; setBusy(true); setError(null);
    c.toBlob(async (blob) => {
      try { if (!blob) throw new Error('empty'); onHash(await uploadMedia(blob, 'photo', 'signature.png')); setSaved(true); }
      catch (e) { setError(why(e)); } finally { setBusy(false); }
    }, 'image/png');
  };
  return (
    <div>
      <div className={cn('rounded-lg border bg-white', saved ? 'border-[color-mix(in_srgb,var(--ok)_40%,transparent)]' : 'border-dashed border-[var(--line-2)]')}>
        <canvas ref={canvas} width={640} height={200} aria-label={label} onPointerDown={start} onPointerMove={move} onPointerUp={() => { down.current = false; }} onPointerCancel={() => { down.current = false; }}
          className="w-full h-[110px] touch:h-[150px] touch-none rounded-lg cursor-crosshair" />
      </div>
      <div className="flex items-center gap-2 mt-1.5">
        <span className="t-sm text-[var(--text-3)] mr-auto flex items-center gap-1.5">{saved ? <><Check className="size-3.5 text-[var(--ok)]" />Signature saved</> : <><PenLine className="size-3.5" />{label}</>}</span>
        <Button size="sm" icon={Eraser} onClick={clear} disabled={busy || (!inked && !saved)}>Clear</Button>
        <Button size="sm" variant="primary" icon={Check} onClick={save} disabled={!inked || saved || busy}>{busy ? 'Saving…' : 'Save signature'}</Button>
      </div>
      {error && <div className="t-sm text-[var(--bad)] mt-1">{error}</div>}
    </div>
  );
}

/** Real-mode photo: the camera or a file, saved to Media, handed back as its hash. Optional. */
export function PhotoPick({ label, onHash }: { label: string; onHash: (hash: string | null) => void }) {
  const input = useRef<HTMLInputElement>(null); const [busy, setBusy] = useState(false); const [done, setDone] = useState(false); const [error, setError] = useState<string | null>(null);
  const pick = async (file: File | undefined) => {
    if (!file) return; setBusy(true); setError(null);
    try { onHash(await uploadMedia(file, 'photo', file.name)); setDone(true); } catch (e) { setError(why(e)); onHash(null); setDone(false); } finally { setBusy(false); }
  };
  return (
    <div>
      <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" capture="environment" className="hidden" onChange={(e) => { void pick(e.target.files?.[0]); e.target.value = ''; }} />
      <Button icon={done ? Check : Camera} onClick={() => input.current?.click()} disabled={busy}>{busy ? 'Saving…' : done ? 'Photo saved · replace' : label}</Button>
      {error && <div className="t-sm text-[var(--bad)] mt-1">{error}</div>}
    </div>
  );
}
