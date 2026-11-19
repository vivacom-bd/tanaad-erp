import { useMemo, useState, type ReactNode } from 'react';
import { BookOpen, ChevronRight, Search } from 'lucide-react';
import { cn, Empty, PageHeader, Panel } from '@/components/ui';

type Doc = { key: string; title: string; file: string; body: string };

const files = import.meta.glob('./docs/*.md', { eager: true, query: '?raw', import: 'default' }) as Record<string, string>;

const ORDER = ['README', '01-getting-started', '02-site-pages', '03-production', '04-people', '05-cash', '06-stock-and-receiving', '07-dispatch-and-deliveries', '08-maintenance', '09-sales', '10-reports-and-settings', '11-group-portal'];

function titleOf(key: string, body: string) {
  if (key === 'README') return 'Contents';
  const m = body.match(/^#\s+(.+)$/m);
  if (m) return m[1].replace(/^Tanaad Management System:\s*/i, '').replace(/^User Manual\s*/i, '').trim() || key;
  return key.replace(/^\d+-/, '').replace(/-/g, ' ');
}

function parseInline(text: string, key: number): ReactNode[] {
  const out: ReactNode[] = [];
  const re = /(`[^`]+`|\*\*[^*]+\*\*|\[[^\]]+\]\([^)]+\))/g;
  let i = 0; let m: RegExpExecArray | null; let k = key;
  while ((m = re.exec(text))) {
    if (m.index > i) out.push(text.slice(i, m.index));
    const t = m[0];
    if (t.startsWith('`')) out.push(<code key={k++} className="px-1 py-0.5 rounded bg-[var(--surface-3)] text-[0.92em] font-mono">{t.slice(1, -1)}</code>);
    else if (t.startsWith('**')) out.push(<strong key={k++} className="font-semibold text-[var(--text)]">{t.slice(2, -2)}</strong>);
    else {
      const lm = /^\[([^\]]+)\]\(([^)]+)\)$/.exec(t);
      if (lm) {
        const href = lm[2];
        const isDoc = href.endsWith('.md') && !/^https?:/i.test(href);
        out.push(isDoc ? <span key={k++} className="text-[var(--accent-strong)] font-medium">{lm[1]}</span> : <a key={k++} href={href} target="_blank" rel="noreferrer" className="text-[var(--accent-strong)] underline underline-offset-2">{lm[1]}</a>);
      } else out.push(t);
    }
    i = m.index + t.length;
  }
  if (i < text.length) out.push(text.slice(i));
  return out.length ? out : [text];
}

function Render({ body }: { body: string }) {
  const lines = body.replace(/\r\n/g, '\n').split('\n');
  const blocks: React.ReactNode[] = [];
  let i = 0; let b = 0;
  const flushPara = (buf: string[]) => { if (!buf.length) return; const text = buf.join(' ').trim(); if (text) blocks.push(<p key={b++} className="t-base leading-7 text-[var(--text-2)]">{parseInline(text, b * 100)}</p>); buf.length = 0; };
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    const h = /^(#{1,3})\s+(.+)$/.exec(line);
    if (h) {
      const lvl = h[1].length; const txt = h[2];
      const cls = lvl === 1 ? 'text-[22px] font-semibold tracking-tight mt-2' : lvl === 2 ? 'text-[16px] font-semibold mt-5' : 'text-[14px] font-semibold mt-4';
      blocks.push(<div key={b++} className={cn(cls, 'text-[var(--text)]')}>{parseInline(txt, b * 100)}</div>);
      i++; continue;
    }
    if (/^\|/.test(line) && i + 1 < lines.length && /^\|?[\s:|-]+\|?/.test(lines[i + 1])) {
      const split = (row: string) => row.replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
      const head = split(line); i += 2; const rows: string[][] = [];
      while (i < lines.length && /^\|/.test(lines[i])) { rows.push(split(lines[i++])); }
      blocks.push(
        <div key={b++} className="overflow-x-auto border border-[var(--line)] rounded-lg">
          <table className="w-full text-sm border-collapse">
            <thead><tr className="bg-[var(--surface-2)]">{head.map((c, j) => <th key={j} className="text-left font-semibold px-3 py-2 border-b border-[var(--line)]">{parseInline(c, b * 100 + j)}</th>)}</tr></thead>
            <tbody>{rows.map((r, ri) => <tr key={ri} className="border-b border-[var(--line)] last:border-b-0">{r.map((c, j) => <td key={j} className="px-3 py-2 align-top text-[var(--text-2)]">{parseInline(c, b * 100 + ri * 20 + j)}</td>)}</tr>)}</tbody>
          </table>
        </div>
      );
      continue;
    }
    if (/^[-*]\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^[-*]\s+/.test(lines[i])) { items.push(lines[i].replace(/^[-*]\s+/, '')); i++; }
      blocks.push(<ul key={b++} className="list-disc pl-5 space-y-1 t-base text-[var(--text-2)]">{items.map((it, j) => <li key={j} className="leading-7">{parseInline(it, b * 100 + j)}</li>)}</ul>);
      continue;
    }
    if (/^\d+\.\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\d+\.\s+/.test(lines[i])) { items.push(lines[i].replace(/^\d+\.\s+/, '')); i++; }
      blocks.push(<ol key={b++} className="list-decimal pl-5 space-y-1 t-base text-[var(--text-2)]">{items.map((it, j) => <li key={j} className="leading-7">{parseInline(it, b * 100 + j)}</li>)}</ol>);
      continue;
    }
    const para: string[] = [];
    while (i < lines.length && lines[i].trim() && !/^(#{1,3})\s+/.test(lines[i]) && !/^[-*]\s+/.test(lines[i]) && !/^\d+\.\s+/.test(lines[i]) && !(/^\|/.test(lines[i]) && i + 1 < lines.length && /^\|?[\s:|-]+\|?/.test(lines[i + 1]))) { para.push(lines[i].trim()); i++; }
    flushPara(para);
  }
  return <div className="space-y-3">{blocks}</div>;
}

export function Manual() {
  const docs = useMemo<Doc[]>(() => {
    return ORDER.map((key) => {
      const entry = Object.entries(files).find(([p]) => p.endsWith(`/${key}.md`));
      if (!entry) return null;
      const body = entry[1] as string;
      return { key, title: titleOf(key, body), file: entry[0], body };
    }).filter((x): x is Doc => !!x);
  }, []);
  const [active, setActive] = useState('README');
  const [q, setQ] = useState('');
  const list = useMemo(() => {
    const t = q.trim().toLowerCase();
    if (!t) return docs;
    return docs.filter((d) => d.title.toLowerCase().includes(t) || d.body.toLowerCase().includes(t));
  }, [docs, q]);
  const doc = docs.find((d) => d.key === active) ?? docs[0];
  return (
    <div>
      <PageHeader title="User manual" subtitle="How to use every page and module, step by step." />
      <div className="grid gap-[var(--gap)] lg:grid-cols-[280px_minmax(0,1fr)] items-start">
        <Panel pad={false} className="lg:sticky lg:top-0">
          <div className="p-2.5 border-b border-[var(--line)]">
            <label className="ctl h-9 flex items-center gap-2 px-2.5 rounded-lg border border-[var(--line)] bg-[var(--surface-2)] text-[var(--text-3)]">
              <Search className="size-3.5 shrink-0" />
              <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search manual" className="flex-1 min-w-0 bg-transparent outline-none t-sm text-[var(--text)] placeholder:text-[var(--text-4)]" />
            </label>
          </div>
          <nav className="p-1.5 max-h-[60vh] lg:max-h-[calc(100vh-220px)] overflow-y-auto" aria-label="Manual chapters">
            {list.length === 0 ? <div className="px-2 py-4 text-center t-sm text-[var(--text-3)]">No match.</div> : list.map((d) => (
              <button key={d.key} onClick={() => setActive(d.key)} className={cn('w-full flex items-center gap-2.5 px-2.5 py-2 rounded-md text-left', active === d.key ? 'bg-[var(--selected)]' : 'hover:bg-[var(--hover)]')}>
                <BookOpen className={cn('size-4 shrink-0', active === d.key ? 'text-[var(--accent-strong)]' : 'text-[var(--text-3)]')} />
                <span className="min-w-0 flex-1 t-base font-medium truncate">{d.title}</span>
                {active === d.key && <ChevronRight className="size-3.5 text-[var(--text-3)]" />}
              </button>
            ))}
          </nav>
        </Panel>
        <Panel>
          {doc ? <Render body={doc.body} /> : <Empty icon={BookOpen} title="Manual not available" hint="No manual chapters were found." />}
        </Panel>
      </div>
    </div>
  );
}
