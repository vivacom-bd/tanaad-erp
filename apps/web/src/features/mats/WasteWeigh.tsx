import { useMemo, useState } from 'react';
import { Scale } from 'lucide-react';
import { useStore } from '@/data/store';
import { useRefList } from '@/app/hooks';
import { weighWaste } from '@/data/api/productionActions';
import { Button, ChipGroup, FormRow, Input, Panel, Select } from '@/components/ui';
import { fmtKg } from '@/data/format';
import { SetupHint } from '../production/parts';
import { useShift } from './kit';

/** Real mode: weigh waste against a waste kind from master data. Goes to the server through the outbox, so it works offline and counts in the mass balance. */
export function WasteWeigh() {
  const { today, site } = useShift(); const machines = useStore((s) => s.machines);
  const kinds = useRefList('waste_source');
  const siteMachines = useMemo(() => machines.filter((m) => m.siteId === site.id && m.status !== 'retired'), [machines, site.id]);
  const stages = useMemo(() => [...new Set(siteMachines.map((m) => m.stageCode).filter(Boolean))], [siteMachines]);
  const [kindId, setKind] = useState<string | null>(null); const [kg, setKg] = useState(''); const [reason, setReason] = useState('');
  const [machineId, setMachine] = useState(''); const [stagePick, setStage] = useState('');
  const kind = kinds.find((k) => k.id === kindId) ?? kinds[0]; const machine = siteMachines.find((m) => m.id === machineId);
  const stage = machine?.stageCode ?? (stages.includes(stagePick) ? stagePick : stages[0] ?? '');
  const amount = Number(kg); const ready = !!kind && !!stage && Number.isFinite(amount) && amount > 0;
  const save = async () => {
    if (!ready || !kind) return;
    await weighWaste({ siteId: site.id, day: today, stageCode: stage, wasteKind: kind.id, kg: amount, reason: reason.trim() || undefined, machineId: machine?.id });
    useStore.getState().toast({ kind: 'ok', title: `${kind.label} weighed: ${fmtKg(amount)}`, detail: 'Counted in the mass balance once it reaches the server.' });
    setKg(''); setReason('');
  };
  return (
    <Panel icon={Scale} title="Weigh waste" sub="kind, kilos, where it came from"
      footer={<Button variant="primary" icon={Scale} disabled={!ready} onClick={() => void save()}>Record weighing</Button>}>
      <div className="flex flex-col gap-3">
        <FormRow label="What was weighed">{kinds.length === 0 ? <SetupHint inline what="waste sources" /> : <ChipGroup ariaLabel="What was weighed" options={kinds.map((k) => ({ value: k.id, label: k.label }))} value={kind?.id ?? ''} onChange={setKind} />}</FormRow>
        <FormRow label="Kilos"><Input inputMode="decimal" aria-label="Kilos" value={kg} onChange={(e) => setKg(e.target.value)} placeholder="0.0" /></FormRow>
        <FormRow label="Machine (optional)"><Select aria-label="Machine" value={machineId} onChange={(e) => setMachine(e.target.value)}><option value="">Not tied to a machine</option>{siteMachines.map((m) => <option key={m.id} value={m.id}>{m.code}</option>)}</Select></FormRow>
        {!machine && stages.length > 1 && <FormRow label="Stage"><Select aria-label="Stage" value={stage} onChange={(e) => setStage(e.target.value)}>{stages.map((c) => <option key={c} value={c}>{c}</option>)}</Select></FormRow>}
        <FormRow label="Reason (optional)"><Input aria-label="Reason" value={reason} onChange={(e) => setReason(e.target.value)} /></FormRow>
      </div>
    </Panel>
  );
}
