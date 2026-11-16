import { useMemo } from 'react';
import { AlertTriangle, ArrowRight, Boxes, ClipboardCheck, Factory, FileClock, Gauge, PackagePlus, ScanLine, Truck, Users, Wallet, Wrench, type LucideIcon } from 'lucide-react';
import type { Role } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { useGo, useSite } from '@/app/hooks';
import { attendanceFor, currentShiftKind, machineWall, openExceptions, shiftId } from '@/data/selectors';
import { fmtDayLong, fmtNum } from '@/data/format';
import { roleLabel } from '@/config/roles';
import { Badge, Button, Empty, PageHeader, Panel } from '@/components/ui';

interface TaskLink {
  title: string;
  detail: string;
  path: string;
  icon: LucideIcon;
  count?: number;
}

/** A role-specific starting point built from current site records and existing tasks. */
export function Today() {
  const s = useStore();
  const site = useSite();
  const go = useGo();
  const role: Role = s.session?.role ?? 'supervisor';
  const kind = currentShiftKind();
  const shift = site.shifts.find((x) => x.kind === kind);
  const wall = useMemo(() => machineWall(s, site.id, s.today, kind), [s, site.id, kind]);
  const allExceptions = useMemo(() => openExceptions(s, site.id), [s, site.id]);
  const exceptions = role === 'site_manager' || role === 'ceo' || role === 'group_admin'
    ? allExceptions
    : allExceptions.filter((x) => x.ownerRole === role);
  const attendanceMarks = attendanceFor(s, shiftId(site.id, s.today, kind)).length;
  const noEntry = wall.filter((x) => x.state === 'not_logged').length;
  const down = wall.filter((x) => x.state === 'down').length;
  const breakdowns = s.breakdowns.filter((x) => x.siteId === site.id && !x.resolvedAt).length;
  const ordersWaiting = s.orders.filter((x) => x.siteId === site.id && ['awaiting_approval', 'approved', 'picking'].includes(x.status)).length;

  const tasks = useMemo<TaskLink[]>(() => {
    const items: TaskLink[] = [];
    const productionTask: TaskLink = {
      title: 'Production entries',
      detail: noEntry
        ? `${noEntry} machine${noEntry === 1 ? ' has' : 's have'} no entry for this shift${down ? ` · ${down} down` : ''}.`
        : `Machine entries recorded for this shift${down ? ` · ${down} down` : ''}.`,
      path: 'production/wall',
      icon: Factory,
      count: noEntry,
    };
    const attendanceTask: TaskLink = {
      title: 'Attendance',
      detail: `${attendanceMarks} attendance mark${attendanceMarks === 1 ? '' : 's'} recorded for this shift.`,
      path: 'people/attendance',
      icon: Users,
    };

    if (role === 'supervisor') {
      items.push(productionTask, attendanceTask);
      if (site.id === 'recycling') items.push({ title: 'Collector intake', detail: 'Record a delivery at the scale and issue its voucher.', path: 'production/intake', icon: PackagePlus });
      items.push({ title: 'Shift handover log', detail: 'Review or add a note to the shift record.', path: 'production/sheet', icon: FileClock });
    } else if (role === 'site_manager') {
      items.push(
        { title: 'Site overview', detail: `${exceptions.length} open exception${exceptions.length === 1 ? '' : 's'} across this site.`, path: 'dashboard', icon: Gauge, count: exceptions.length },
        productionTask,
        attendanceTask,
        { title: 'Cash day', detail: 'Review the cash record and closing status.', path: 'cash', icon: Wallet },
      );
    } else if (role === 'cashier') {
      items.push(
        { title: 'Cash day', detail: 'Review entries, expected balance and close status.', path: 'cash', icon: Wallet },
        { title: 'Record an expense', detail: 'Add an expense to the site cash record.', path: 'cash/expense', icon: Wallet },
      );
      if (site.id === 'recycling') items.push({ title: 'Collector ledger', detail: 'Review deliveries and outstanding payments.', path: 'production/collectors', icon: ClipboardCheck });
    } else if (role === 'storekeeper') {
      items.push(
        { title: 'Stock overview', detail: 'Review recorded stock by product.', path: 'stock', icon: Boxes },
        { title: 'Receive goods', detail: 'Record an incoming delivery at the gate.', path: 'stock/receive', icon: PackagePlus },
        { title: 'Dispatch', detail: `${ordersWaiting} order${ordersWaiting === 1 ? '' : 's'} in the loading or approval queue.`, path: 'stock/dispatch', icon: Truck, count: ordersWaiting },
      );
    } else if (role === 'gate') {
      items.push(
        { title: 'Receive at gate', detail: 'Record an incoming vehicle and measured weight.', path: 'stock/receive', icon: PackagePlus },
        { title: 'Dispatch and gate pass', detail: 'Review loads and record gate weights.', path: 'stock/dispatch', icon: Truck },
        { title: 'Label lookup', detail: 'Find a record by scanning or entering its label code.', path: 'production/scan', icon: ScanLine },
      );
    } else if (role === 'mechanic') {
      items.push(
        { title: 'Breakdowns', detail: `${breakdowns} unresolved machine breakdown${breakdowns === 1 ? '' : 's'}.`, path: 'stock/maintenance', icon: Wrench, count: breakdowns },
        { title: 'Spares store', detail: 'Review parts and record an issue or receipt.', path: 'stock/spares', icon: Boxes },
        { title: site.machineWallTitle, detail: 'Check machine entries and reported downtime.', path: 'production/wall', icon: Factory },
      );
    } else if (role === 'sales') {
      items.push(
        { title: 'Customer orders', detail: `${ordersWaiting} order${ordersWaiting === 1 ? '' : 's'} awaiting a decision or fulfilment.`, path: 'sales/orders', icon: ClipboardCheck, count: ordersWaiting },
        { title: 'Clients and credit', detail: 'Review client records and account balances.', path: 'sales/clients', icon: Users },
        { title: 'Invoices', detail: 'Review issued invoices and payment records.', path: 'sales/invoices', icon: Wallet },
      );
    } else if (role === 'driver') {
      items.push({ title: 'Deliveries', detail: 'Review assigned deliveries and record proof of delivery.', path: 'stock/deliveries', icon: Truck });
    } else {
      items.push(productionTask, attendanceTask);
    }

    return items;
  }, [role, site.id, site.machineWallTitle, noEntry, down, attendanceMarks, exceptions.length, ordersWaiting, breakdowns]);

  return (
    <div className="flex flex-col gap-[var(--gap)]">
      <PageHeader
        eyebrow={`${site.name} · ${roleLabel(role)}`}
        title="Today"
        subtitle={`${kind === 'day' ? 'Day' : 'Night'} shift${shift ? ` · ${shift.starts}–${shift.ends}` : ''} · ${fmtDayLong(s.today)}`}

      />

      <Panel title="Your tasks" sub={`${tasks.length} links`}>
        <div className="divide-y divide-[var(--line)]">
          {tasks.map((task) => (
            <div key={task.path} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
              <span className="size-8 rounded-md bg-[var(--surface-3)] text-[var(--text-2)] grid place-items-center shrink-0">
                <task.icon className="size-4" />
              </span>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <h2 className="t-base font-medium">{task.title}</h2>
                  {task.count !== undefined && <Badge tone={task.count ? 'warn' : 'neutral'}>{fmtNum(task.count)}</Badge>}
                </div>
                <p className="t-sm text-[var(--text-3)]">{task.detail}</p>
              </div>
              <Button size="sm" variant="secondary" icon={ArrowRight} onClick={() => go.site(task.path)}>
                Open
              </Button>
            </div>
          ))}
        </div>
      </Panel>

      <Panel title="Open exceptions" sub={`${exceptions.length} assigned`} icon={AlertTriangle}>
        {exceptions.length === 0 ? <Empty title="No open exceptions assigned to this role" /> : (
          <div className="divide-y divide-[var(--line)]">
            {exceptions.slice(0, 6).map((exception) => (
              <div key={exception.id} className="py-2.5 first:pt-0 last:pb-0 flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="t-base font-medium">{exception.title}</div>
                  <p className="t-sm text-[var(--text-3)]">{exception.detail.trim()} · {roleLabel(exception.ownerRole)}</p>
                </div>
                <Badge tone={exception.severity === 'critical' ? 'bad' : exception.severity === 'warning' ? 'warn' : 'neutral'}>{exception.severity}</Badge>
              </div>
            ))}
          </div>
        )}
      </Panel>
    </div>
  );
}
