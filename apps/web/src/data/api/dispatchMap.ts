// Pure translation for dispatch, the gate and proof of delivery: the order snapshot out, the server's dispatch shape in,
// and plain wording for refusals. The server decides gate pass or hold, the variance and the delivery note number; nothing
// here computes them. No store import.
import type { Dispatch, GateStatus, Order } from '@tanaad/domain';
import type { SiteOf } from './mapping';
import { problemText } from './sparesMap';

type Row = Record<string, unknown>;
const optNum = (v: unknown) => (v === null || v === undefined ? undefined : Number(v));
const str = (v: unknown) => (v === null || v === undefined || v === '' ? undefined : String(v));

/** Shown until the server allocates the delivery note at the gate. It is a label, never a number. */
export const DN_PENDING = 'DN pending';

/* ───────────── outgoing ───────────── */

export interface OrderSnapshot { orderRef: string; clientName: string; site: string; lines: { productId: string; bundles: number }[] }

/**
 * The order snapshot the server's dispatch.started contract takes (Orders arrives in Phase 4 and will emit the same shape).
 * Until then it is built from the local order: lines with the server's product ids, one line per product.
 * `resolve` maps a local product id to the server's product id, or undefined when the server has no such product.
 */
export function orderSnapshot(order: Pick<Order, 'number' | 'lines'>, clientName: string | undefined, site: string, resolve: (productId: string) => string | undefined): { ok: true; order: OrderSnapshot } | { ok: false; message: string } {
  if (!clientName?.trim()) return { ok: false, message: 'This order has no client name to put on the delivery note.' };
  const bundles = new Map<string, number>();
  for (const l of order.lines) {
    const id = resolve(l.productId);
    if (!id) return { ok: false, message: 'A product on this order is not in the server catalogue yet, so it cannot be loaded. Orders connect to the server in Phase 4.' };
    bundles.set(id, (bundles.get(id) ?? 0) + l.bundles);
  }
  const lines = [...bundles].map(([productId, n]) => ({ productId, bundles: n }));
  if (!lines.length || lines.some((l) => !Number.isInteger(l.bundles) || l.bundles < 1)) return { ok: false, message: 'The order has no whole number of bundles to load.' };
  return { ok: true, order: { orderRef: order.number, clientName: clientName.trim(), site, lines } };
}

export const startBody = (b: { dispatchId: string; driver: string; vehicle: string; order: OrderSnapshot }): Row =>
  ({ dispatchId: b.dispatchId, driver: b.driver.trim(), vehicle: b.vehicle.trim(), order: b.order });

/** Proof of delivery: the signature (required) and photo (optional) go as Media hashes. */
export const deliveryBody = (d: { receivedBy: string; bundlesReceived: number; signatureHash: string; photoHash?: string }): Row =>
  ({ receivedBy: d.receivedBy.trim(), bundlesReceived: d.bundlesReceived, signatureHash: d.signatureHash, ...(d.photoHash ? { photoHash: d.photoHash } : {}) });

/* ───────────── incoming ───────────── */

/** The server's dispatch (GET/POST answers, camelCase) in the store's shape. The order is found by its reference among the local orders. */
export function dispatchFromServer(r: Row, site: SiteOf, orderIdOf: (orderRef: string) => string | undefined): Dispatch {
  const pod = r.pod as Row | null | undefined;
  const ref = String(r.orderRef ?? '');
  return {
    id: String(r.id), orderId: orderIdOf(ref) ?? '', orderRef: ref, siteId: site(r.siteId), deliveryNoteNo: str(r.deliveryNoteNo) ?? DN_PENDING,
    scannedLabelIds: Array.isArray(r.scannedLabelIds) ? r.scannedLabelIds.map(String) : [], scannedCodes: Array.isArray(r.scannedCodes) ? r.scannedCodes.map(String) : [],
    expectedKg: Number(r.expectedKg ?? 0), gateKg: optNum(r.gateKg), variancePct: optNum(r.variancePct), gateStatus: (str(r.gateStatus) ?? 'pending') as GateStatus,
    gateReasons: Array.isArray(r.gateReasons) ? r.gateReasons.map(String) : [], overrideBy: str(r.overrideBy), overrideReason: str(r.overrideReason),
    driver: String(r.driver ?? ''), vehicle: String(r.vehicle ?? ''), hasLorryPhoto: false, hasDriverSignature: false, createdAt: String(r.createdAt ?? ''), gatePassedAt: str(r.gatePassedAt),
    pod: pod ? { receiverName: String(pod.receiverName ?? ''), bundlesCounted: Number(pod.bundlesCounted ?? 0), bundlesExpected: Number(pod.bundlesExpected ?? 0), deliveredAt: String(pod.deliveredAt ?? ''), hasSignature: !!pod.hasSignature, signatureHash: str(pod.signatureHash), photoHash: str(pod.photoHash) } : undefined,
  };
}

/** The local order status a server dispatch implies. Only moves an order forward. */
export function orderStatusFor(d: Pick<Dispatch, 'gateStatus' | 'pod'>): Order['status'] {
  if (d.pod) return 'delivered';
  if (d.gateStatus === 'pass' || d.gateStatus === 'overridden') return 'dispatched';
  return 'picking';
}
const RANK: Partial<Record<Order['status'], number>> = { approved: 0, picking: 1, dispatched: 2, delivered: 3 };
export const advancesOrder = (from: Order['status'], to: Order['status']) => RANK[to] !== undefined && RANK[from] !== undefined && RANK[from]! < RANK[to]!; // invoiced or paid orders are never moved back

/* ───────────── plain wording for refusals ───────────── */

interface Failure { status: number; code: string; detail: string; details?: Record<string, unknown> }
const OFFLINE = 'No connection. The gate and loading need the server, try again when you are online.';
const bare = (s: string) => s.replace(/\.$/, '');

/** A scan the server refused, in the prototype's scan wording. */
export function scanMessage(e: Failure): string {
  if (e.code === 'network') return OFFLINE;
  if (e.status === 403) return e.detail || 'Only the Storekeeper, Gate or Site Manager of this site may scan.';
  const t = problemText(e); const unknown = /^unknown label (.+) at this site$/i.exec(t);
  if (unknown) return `Unknown label ${unknown[1]}`;
  const product = /^(.+): its product is not on this order$/i.exec(t);
  if (product) return `${product[1]} is not on this order`;
  return bare(t) || 'The scan was refused.';
}

export function startMessage(e: Failure): string {
  if (e.code === 'network') return OFFLINE;
  if (e.status === 403) return e.detail || 'Only the Storekeeper, Gate or Site Manager of this site may start a load.';
  return problemText(e) || 'Loading could not be started.';
}

export function weighMessage(e: Failure): string {
  if (e.code === 'network') return OFFLINE;
  if (e.status === 403) return e.detail || 'Only the Storekeeper, Gate or Site Manager of this site may weigh at the gate.';
  return problemText(e) || 'The weight could not be recorded.';
}

/** An override the server refused. A weigher or loader, or any role but the Site Manager, gets the server's own sentence. */
export function overrideMessage(e: Failure): string {
  if (e.code === 'network') return OFFLINE;
  if (e.status === 403) return e.detail || 'Only the Site Manager of this site may override the gate.';
  return problemText(e) || 'The override was refused.';
}

export function deliveryMessage(e: Failure): string {
  if (e.code === 'network') return 'No connection. Proof of delivery needs the server, try again when you are online.';
  if (e.status === 403) return e.detail || 'Only the Storekeeper, Gate, Driver or Site Manager of this site may confirm a delivery.';
  return problemText(e) || 'The delivery could not be confirmed.';
}

/** The result lines of a gate decision, from the server's dispatch. Nothing is recomputed. */
export function gateResult(d: Pick<Dispatch, 'gateStatus' | 'variancePct' | 'gateReasons' | 'deliveryNoteNo'>): { decision: 'pass' | 'hold'; variancePct: number; reasons: string[]; note?: string } {
  return { decision: d.gateStatus === 'hold' ? 'hold' : 'pass', variancePct: d.variancePct ?? 0, reasons: d.gateReasons ?? [], note: d.deliveryNoteNo === DN_PENDING ? undefined : d.deliveryNoteNo };
}
