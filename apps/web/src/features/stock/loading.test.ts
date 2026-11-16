import { describe, expect, it } from 'vitest';
import { takenLabelIds } from './loading';

describe('bundles already on a lorry', () => {
  it('collects the labels scanned onto every dispatch, not only the open one', () => {
    const taken = takenLabelIds([{ scannedLabelIds: ['a', 'b'] }, { scannedLabelIds: ['c'] }, { scannedLabelIds: [] }]);
    expect([...taken].sort()).toEqual(['a', 'b', 'c']);
  });
  it('is empty when nothing is loaded', () => { expect(takenLabelIds([]).size).toBe(0); });
});
