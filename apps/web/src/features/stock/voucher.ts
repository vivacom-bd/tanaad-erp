/** The one-line summary under the stock voucher. A scrap writes off one label by its code and has no product chosen, so it must not read one. */
export function voucherLine(v: { ready: boolean; missing: string[]; scrapLabelCode?: string; qty: string; unit: string; productName?: string; fromZone: string; destination?: string }): string {
  if (!v.ready) return `Still needed: ${v.missing.join(', ')}.`;
  if (v.scrapLabelCode !== undefined) return `Write off label ${v.scrapLabelCode.trim()}.`;
  return `${v.qty} ${v.unit} of ${v.productName ?? 'the product'}, ${v.fromZone} to ${v.destination ?? '—'}.`;
}
