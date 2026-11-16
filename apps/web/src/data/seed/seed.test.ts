import { describe, expect, it } from 'vitest';
import { buildSeed, TODAY } from './build';

// The seed is authored against a fixed 13:20 demo time. Do not derive this from DEMO_HOUR: that follows the device clock in a production-mode build.
const NOW = `${TODAY}T13:20`;

/** Every ISO timestamp anywhere in the seed, with the record collection it came from. */
function timestamps() {
  const found: { where: string; value: string }[] = [];
  const walk = (o: unknown, where: string) => {
    if (typeof o === 'string') { if (/^\d{4}-\d\d-\d\dT/.test(o)) found.push({ where, value: o }); }
    else if (o && typeof o === 'object') for (const v of Object.values(o)) walk(v, where);
  };
  for (const [name, rows] of Object.entries(buildSeed())) walk(rows, name);
  return found;
}

describe('seed timestamps', () => {
  it('are well-formed (zero-padded hours and minutes)', () => {
    const bad = timestamps().filter((t) => !/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/.test(t.value));
    expect(bad).toEqual([]);
  });
  it('never fall after the pinned demo time', () => {
    const future = timestamps().filter((t) => t.value.slice(0, 16) > NOW);
    expect(future).toEqual([]);
  });
});
