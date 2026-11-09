import { useMemo, useState } from 'react';
import { ArrowRight, Bell, Clock, Copy, CreditCard, Flame, Lock, MessageSquareText, Table2, Users, Wallet, type LucideIcon } from 'lucide-react';
import type { SiteSettings } from '@tanaad/domain';
import { useGo } from '@/app/hooks';
import { SITE_COLOR } from '@/config/siteIdentity';
import { roleLabel } from '@/config/roles';
import { useStore } from '@/data/store';
import { Avatar, Badge, Bar, Button, DataGrid, PageHeader, Panel, Split, Tabs, cn, type Column } from '@/components/ui';
import { WhatsAppPreview } from '@/components/WhatsAppPreview';
import { SITE_LIST } from '@/config/sites';
import { cashDayFor, cashInSafe, cashSummary, headcount, massBalanceDay, openExceptions, outputToday } from '@/data/selectors';
import { compact, fmtDayLong, fmtNum, fmtUGX } from '@/data/format';
import { IconChip, KV, SiteBadge, SiteMark, plural, unitName } from './shared';

/** Alert kinds the sites already raise. Limits come from Settings — this page only shows them. */
interface Rule { id: string; icon: LucideIcon; title: string; detail: string; limit?: (st: SiteSettings) => string }
const RULES: Rule[] = [
  { id: 'gate', icon: Lock, title: 'Gate lock', detail: 'Scale weight outside the gate tolerance, or a bundle count mismatch.', limit: (st) => `±${st.gateTolerancePct}%` },
  { id: 'cash', icon: Wallet, title: 'Cash variance over threshold', detail: 'Counted closing differs from expected by more than the site threshold.', limit: (st) => fmtUGX(st.cashVariance) },
  { id: 'mass', icon: Flame, title: 'Mass gap', detail: 'Kilos in minus kilos out, work in progress and waste exceeds the allowed loss.', limit: (st) => `${st.massGapPct}%` },
  { id: 'credit', icon: CreditCard, title: 'Credit over limit', detail: 'An export order would take a client past the credit limit on its client record.' },
];

function RuleRow({ rule }: { rule: Rule }) {
  const settings = useStore((x) => x.siteSettings);
  return (
    <div className="px-[var(--pad)] py-2.5 border-b border-[var(--line)] last:border-b-0">
      <div className="flex items-start gap-2.5">
        <IconChip icon={rule.icon} color="var(--accent)" />
        <div className="min-w-0 flex-1"><div className="t-base font-medium">{rule.title}</div><div className="t-sm text-[var(--text-3)]">{rule.detail}</div></div>
      </div>
      {rule.limit && <div className="flex flex-wrap gap-1.5 mt-2">{SITE_LIST.map((x) => <span key={x.id} className="inline-flex items-center gap-1.5 h-[20px] touch:h-[24px] pl-1.5 pr-1.5 rounded-[5px] bg-[var(--surface-2)] border border-[var(--line)] t-xs"><span className="size-1.5 rounded-[2px]" style={{ background: SITE_COLOR[x.id] }} /><span className="text-[var(--text-2)]">{x.shortName}</span><span className="num font-semibold">{rule.limit!(settings[x.id])}</span></span>)}</div>}
    </div>
  );
}

/** Compose today’s brief from live records. Copy the text; WhatsApp send is outside this demo. */
export function Brief() {
  const s = useStore(); const toast = useStore((x) => x.toast); const go = useGo();
  const ceos = s.users.filter((u) => u.roles.some((r) => r.role === 'ceo'));
  const managers = s.users.filter((u) => u.roles.some((r) => r.role === 'site_manager'));
  const [who, setWho] = useState(ceos[0]?.id ?? '');

  const sites = useMemo(() => SITE_LIST.map((site) => {
    const hc = headcount(s, site.id, s.today); const o = outputToday(s, site.id, s.today); const units = o.reduce((t, x) => t + x.units, 0); const target = o.reduce((t, x) => t + x.target, 0);
    const cd = cashDayFor(s, site.id, s.today); const cash = cd ? cashSummary(s, cd.id).expectedClosing : cashInSafe(s, site.id);
    const dsp = s.dispatches.filter((d) => d.siteId === site.id && d.createdAt.startsWith(s.today)).length; const ex = openExceptions(s, site.id);
    return { site, hc: hc.total, units, target, cash, dsp, ex: ex.length, critical: ex.filter((e) => e.severity === 'critical').length };
  }), [s]);
  type SiteLine = (typeof sites)[number];
  const needs = useMemo(() => [
    ...openExceptions(s).filter((e) => e.severity === 'critical' || e.ownerRole === 'ceo').map((e) => e.title),
    ...SITE_LIST.map((site) => ({ site, ...massBalanceDay(s, site.id, s.today) })).filter((m) => m.state === 'missing').map((m) => `${m.site.shortName}: ${fmtNum(m.gapKg)} kg unaccounted today`),
  ], [s]);

  const lines = useMemo(() => {
    const out: string[] = [`*Tanaad · ${fmtDayLong(s.today)}*`, ''];
    for (const x of sites) out.push(`*${x.site.name}*`, `• ${x.hc} on shift`, `• ${fmtNum(x.units)} ${unitName(x.site.id)}${x.target ? ` of ${fmtNum(x.target)} target (${Math.round((x.units / x.target) * 100)}%)` : ''}`, `• Cash UGX ${compact(x.cash)}`, `• ${x.dsp} dispatch${x.dsp === 1 ? '' : 'es'}`, `• ${x.ex} exception${x.ex === 1 ? '' : 's'} open${x.critical ? ` (${x.critical} critical)` : ''}`, '');
    if (needs.length) { out.push('*Needs you:*'); for (const n of needs) out.push(`→ ${n}`); } else out.push('Nothing needs you right now.');
    return out;
  }, [s.today, sites, needs]);

  const copyMessage = async () => {
    try {
      await navigator.clipboard.writeText(lines.join('\n'));
      toast({ kind: 'ok', title: 'Brief copied', detail: 'Paste into WhatsApp when you are ready to send.' });
    } catch {
      toast({ kind: 'warn', title: 'Could not copy', detail: 'Select the preview text and copy it by hand.' });
    }
  };

  const cols: Column<SiteLine>[] = [
    { key: 'site', header: 'Site', cell: (x) => <span className="inline-flex items-center gap-2 font-medium"><SiteMark site={x.site.id} size={20} />{x.site.name}</span> },
    { key: 'hc', header: 'On shift', align: 'right', mono: true, cell: (x) => fmtNum(x.hc) },
    { key: 'out', header: 'Output', align: 'right', mono: true, cell: (x) => <>{fmtNum(x.units)} <span className="text-[var(--text-3)]">{unitName(x.site.id)}</span></> },
    { key: 'tg', header: 'Of shift target', width: 150, cell: (x) => (x.target ? <span className="flex items-center gap-2"><Bar value={x.units} max={Math.max(x.target, x.units)} tone="series" className="w-[72px]" /><span className="num">{Math.round((x.units / x.target) * 100)}%</span></span> : <span className="text-[var(--text-4)]">no benchmark</span>) },
    { key: 'cash', header: 'Cash, UGX', align: 'right', mono: true, cell: (x) => <span className="font-medium">{compact(x.cash)}</span> },
    { key: 'dsp', header: 'Dispatches', align: 'right', mono: true, cell: (x) => fmtNum(x.dsp) },
    { key: 'ex', header: 'Exceptions', align: 'right', cell: (x) => (x.critical ? <Badge tone="bad" dot>{x.ex} · {x.critical} critical</Badge> : x.ex ? <Badge tone="warn">{x.ex} open</Badge> : <Badge tone="ok">clear</Badge>) },
  ];

  return (
    <div>
      <PageHeader eyebrow="Group HQ" title="Morning brief"
        meta={<Badge tone="neutral" dot>Preview</Badge>}
        subtitle="Five numbers per site, composed now from the same records the site screens show. Copy the text when you are ready to send it yourself."
        actions={<Button variant="primary" icon={Copy} onClick={copyMessage} hotkey="mod+enter" hotkeyLabel="Copy the brief">Copy message</Button>} />

      <Split aside={<>
        <Panel icon={Clock} title="How it goes out" sub="manual for now"
          footer={<div className="flex items-center justify-between gap-3"><span>Daily auto-send is not wired yet. Copy here, paste in WhatsApp.</span><Button size="sm" variant="ghost" onClick={() => go.group('settings')}>Site rules</Button></div>}>
          <KV k="Recipients" v={plural(ceos.length, 'person', 'people')} />
          <KV k="Message" v={`${plural(lines.filter((l) => l.startsWith('•')).length, 'number')} · ${plural(needs.length, 'item')} for you`} />
          <KV k="Delivery" v={<Badge tone="neutral" dot>You send it</Badge>} />
        </Panel>

        <Panel icon={Users} title="Recipients" sub={`${ceos.length} get the brief`} pad={false}>
          <div className="px-[var(--pad)] h-7 touch:h-8 flex items-center bg-[var(--surface-2)] border-b border-[var(--line)] t-label">Brief goes to</div>
          {ceos.map((c) => (
            <div key={c.id} className="flex items-center gap-2.5 px-[var(--pad)] py-2 border-b border-[var(--line)]">
              <Avatar name={c.name} seed={c.avatarSeed} size={28} />
              <div className="min-w-0 flex-1"><div className="t-base font-medium truncate">{c.name}</div><div className="t-sm text-[var(--text-3)]">{roleLabel('ceo')}</div></div>
            </div>
          ))}
          {managers.length > 0 && <div className="px-[var(--pad)] h-7 touch:h-8 flex items-center bg-[var(--surface-2)] border-b border-[var(--line)] t-label">Site managers</div>}
          {managers.map((m) => (
            <div key={m.id} className="flex items-center gap-2.5 px-[var(--pad)] py-1.5 border-b border-[var(--line)] last:border-b-0">
              <Avatar name={m.name} seed={m.avatarSeed} size={24} />
              <div className="min-w-0 flex-1 t-base truncate">{m.name}<span className="t-sm text-[var(--text-3)]"> · {roleLabel('site_manager')}</span></div>
              <span className="flex gap-1">{m.roles.filter((r) => r.role === 'site_manager' && r.siteId).map((r) => <SiteBadge key={r.siteId} site={r.siteId} />)}</span>
            </div>
          ))}
        </Panel>

        <Panel icon={Bell} title="Alert limits" sub="from each site’s rules" pad={false}
          footer={<>These limits are stored in Settings. Changing them there changes what the sites raise — not from this page.</>}>
          {RULES.map((r) => <RuleRow key={r.id} rule={r} />)}
        </Panel>
      </>}>
        <div className="flex flex-col gap-[var(--gap)]">
          <Panel icon={MessageSquareText} title="Preview" sub={`${plural(ceos.length, 'message')}, composed now`} actions={<Tabs className="md:hidden" value={who} onChange={setWho} tabs={ceos.map((c) => ({ value: c.id, label: c.name.split(' ')[0] }))} />}
            footer={<span>{plural(lines.filter((l) => l.startsWith('•')).length, 'number')} and {plural(needs.length, 'item')} that need you. Rebuilt from the records every time this page opens.</span>}>
            <div className="grid gap-[var(--gap)] md:grid-cols-2">
              {ceos.map((c) => (
                <div key={c.id} className={cn('min-w-0', c.id !== who && 'max-md:hidden')}>
                  <div className="flex items-center gap-2 mb-2"><Avatar name={c.name} seed={c.avatarSeed} size={24} /><span className="t-base font-medium truncate">{c.name}</span><span className="t-sm text-[var(--text-3)]">{roleLabel('ceo')}</span><Badge className="ml-auto" tone="neutral" dot>Preview</Badge></div>
                  <WhatsAppPreview className="w-full" to={c.name} lines={lines} sent={false} />
                </div>
              ))}
            </div>
          </Panel>

          <Panel icon={Table2} title="Where the numbers come from" sub="the same records the site screens show" pad={false}
            footer={<div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 num"><span>Group: {fmtNum(sites.reduce((t, x) => t + x.hc, 0))} on shift · UGX {compact(sites.reduce((t, x) => t + x.cash, 0))} in three safes · {plural(sites.reduce((t, x) => t + x.dsp, 0), 'dispatch', 'dispatches')}</span><span>{plural(sites.reduce((t, x) => t + x.ex, 0), 'exception')} open</span></div>}>
            <DataGrid bare rows={sites} columns={cols} rowKey={(x) => x.site.id} />
            <div className="border-t border-[var(--line)] px-[var(--pad)] py-2.5">
              <div className="t-label mb-1">Needs you · {needs.length}</div>
              {needs.length === 0 ? <div className="t-sm text-[var(--text-3)]">Nothing needs you right now.</div> : needs.map((n) => <div key={n} className="flex items-start gap-2 py-1 t-base"><ArrowRight className="size-3.5 text-[var(--accent-strong)] mt-[3px] shrink-0" />{n}</div>)}
            </div>
          </Panel>
        </div>
      </Split>
    </div>
  );
}
