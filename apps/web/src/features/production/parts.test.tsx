import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import type { Label } from '@tanaad/domain';
import { freshLabel } from './parts';

const label = (id: string, code: string): Label => ({ id, code, siteId: 'mats', kind: 'batch', stageCode: 'mixer', shiftId: 's', day: '2026-11-10', qtyUnits: 1, qtyKg: 5, status: 'in_stock', parentIds: [] }) as Label;

describe('a label held by a screen follows the store', () => {
  it('shows the real code once the server has replaced the pending one', () => {
    expect(freshLabel([label('l1', 'MAT-BAT-000007')], label('l1', 'PENDING-ABC123')).code).toBe('MAT-BAT-000007');
  });
  it('keeps the held label when the store does not know it (yet)', () => {
    expect(freshLabel([], label('l2', 'PENDING-X')).code).toBe('PENDING-X');
  });
});
