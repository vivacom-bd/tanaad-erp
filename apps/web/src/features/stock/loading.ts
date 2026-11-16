/** Label ids already scanned onto any lorry. A bundle on an open (pending or held) lorry stays in stock until the gate passes, so "scan next" must skip it. */
export function takenLabelIds(dispatches: { scannedLabelIds: string[] }[]): Set<string> {
  return new Set(dispatches.flatMap((d) => d.scannedLabelIds));
}
