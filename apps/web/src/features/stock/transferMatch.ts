import type { Product } from '@tanaad/domain';

/** Each site has its own product row (codes are unique across the company), so a transfer's product must be re-found at the receiving site. */
export type ReceivingMatch =
  | { status: 'one'; product: Product }
  | { status: 'many'; options: Product[] }
  | { status: 'none' };

const norm = (v?: string) => (v ?? '').trim().toLowerCase().replace(/\s+/g, ' ');

/** Same name, category (kind) and unit as the sent product, among the receiving site's own products. Ambiguity is narrowed by pack size and colour before giving up. */
export function matchReceivingProduct(sent: Product | undefined, candidates: Product[]): ReceivingMatch {
  if (!sent) return { status: 'none' };
  const pool = candidates.filter((p) => p.siteId !== sent.siteId && p.active !== false);
  const same = (p: Product) => (norm(p.internalName) === norm(sent.internalName) || (!!p.commercialName && norm(p.commercialName) === norm(sent.commercialName)))
    && p.kind === sent.kind && norm(p.unit) === norm(sent.unit);
  let hits = pool.filter(same);
  if (hits.length > 1) { const tight = hits.filter((p) => p.bundlePieces === sent.bundlePieces && norm(p.colour) === norm(sent.colour)); if (tight.length) hits = tight; }
  if (hits.length === 1) return { status: 'one', product: hits[0]! };
  return hits.length ? { status: 'many', options: hits } : { status: 'none' };
}
