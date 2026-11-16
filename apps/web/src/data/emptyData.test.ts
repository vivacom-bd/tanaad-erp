import { describe, expect, it } from 'vitest';
import { realToday } from './emptyData';

const at = (iso: string) => Date.parse(iso);

describe('realToday', () => {
  it('is the Kampala date during the day', () => {
    expect(realToday(at('2026-11-10T08:00:00Z'))).toBe('2026-11-10'); // 11:00 Kampala
  });
  it('is still yesterday inside the tail of the night shift (00:00 to 07:00 Kampala), like the server', () => {
    expect(realToday(at('2026-11-09T22:34:00Z'))).toBe('2026-11-09'); // 01:34 Kampala on the 10th
    expect(realToday(at('2026-11-10T03:59:00Z'))).toBe('2026-11-09'); // 06:59 Kampala
  });
  it('turns over when the night shift ends', () => {
    expect(realToday(at('2026-11-10T04:00:00Z'))).toBe('2026-11-10'); // 07:00 Kampala
    expect(realToday(at('2026-11-10T20:59:00Z'))).toBe('2026-11-10'); // 23:59 Kampala
  });
});
