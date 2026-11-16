// Real-mode stock and purchases, read side. On-hand comes from the stock_on_hand snapshot (the server computes it), the ledger, transfers and counts
// from their endpoints. Stock writes are online-only (the server checks what is on hand and locks counts); purchases go through the outbox
// (a provisional PO reference offline, the server's real one after sync); those writes are in stockActions.ts. Nothing here runs in demo mode.
import { create } from 'zustand';
import type { Purchase, SiteCode, StockCount } from '@tanaad/domain';
import { useStore, type AppState } from '../store';
import { api } from './http';
import { loadSession } from './auth';
import { mergeById, type SiteOf } from './mapping';
import { canRead } from './readAccess';
import {
  countFromApi, movementFromApi, onHandFromRow, purchaseFromRow, transferFromApi,
  type ServerTransfer, type StockOnHand,
} from './stockMap';

type Rows = Record<string, unknown>[];
export const STOCK_PROJECTIONS = ['stock_on_hand', 'purchases'] as const;
export type StockProj = typeof STOCK_PROJECTIONS[number];

interface ServerStockState { movements: ReturnType<typeof movementFromApi>[]; transfers: ServerTransfer[]; loaded: boolean }
/** The server's movement ledger and transfers for the sites the user can read (newest first). */
export const useServerStock = create<ServerStockState>(() => ({ movements: [], transfers: [], loaded: false }));
export const resetStock = () => { useServerStock.setState({ movements: [], transfers: [], loaded: false }); useStore.setState({ serverStock: [] }); };

/** Merge pulled stock_on_hand or purchases rows. Server rows win; a purchase keeps its id from the order, so the real PO reference replaces the provisional one. */
export function mergeStockRows(proj: StockProj, rows: Rows, siteOf: SiteOf, serverIds: Set<string>) {
  useStore.setState((st): Partial<AppState> => {
    if (proj === 'stock_on_hand') return { serverStock: mergeById<StockOnHand>(st.serverStock ?? [], rows.map((r) => ({ row: onHandFromRow(r, siteOf), deleted: !!r.deletedAt }))) };
    return { purchases: mergeById<Purchase>(st.purchases, rows.map((r) => { serverIds.add(String(r.id)); return { row: purchaseFromRow(r, siteOf), deleted: !!r.deletedAt }; })) };
  });
}

/** Pull the ledger, transfers, today's counts and the on-hand figures for these sites. Roles that may not read stock are skipped. */
export async function refreshStockViews(sites: SiteCode[], siteOf?: SiteOf): Promise<void> {
  const s = loadSession(); if (!s) return;
  const of: SiteOf = siteOf ?? ((id) => (sites.includes(id as SiteCode) ? (id as SiteCode) : sites[0]));
  const day = useStore.getState().today;
  const get = async <T>(path: string): Promise<T | null> => { try { return await api<T>(path, { token: s.token }); } catch (e) { if ((e as { code?: string }).code === 'network') throw e; return null; } };
  let movements: ServerStockState['movements'] = []; let transfers: ServerTransfer[] = []; let any = false;
  for (const site of sites) {
    if (!canRead(s.user.roles, site, 'stock_views')) continue;
    const base = `/sites/${encodeURIComponent(site)}/stock`;
    const [mv, tr, cv] = await Promise.all([
      get<{ data: Rows }>(`${base}/movements?perPage=100`), get<{ transfers: Rows }>(`${base}/transfers`), get<{ counts: Rows }>(`${base}/count-variance?day=${encodeURIComponent(day)}`),
    ]);
    if (mv) { any = true; movements = [...movements, ...mv.data.map((r) => movementFromApi(r, of))]; }
    if (tr) transfers = [...transfers, ...tr.transfers.map(transferFromApi)];
    if (cv) { const counts = cv.counts.map((r) => countFromApi(r, of)); useStore.setState((st) => ({ stockCounts: mergeById<StockCount>(st.stockCounts, counts.map((row) => ({ row, deleted: false }))) })); }
  }
  if (any || transfers.length) useServerStock.setState({ movements: movements.sort((a, b) => b.occurredAt.localeCompare(a.occurredAt)), transfers, loaded: true });
}

