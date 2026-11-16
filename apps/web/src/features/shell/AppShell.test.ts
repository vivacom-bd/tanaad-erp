import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import { dataSourceNote } from './AppShell';

describe('status bar data source', () => {
  it('does not claim "no server" when the app is connected to one', () => {
    expect(dataSourceNote(true).text).toBe('Server data');
    expect(dataSourceNote(true).title).not.toMatch(/no server/i);
  });
  it('keeps the browser-only wording for the offline-only build', () => {
    expect(dataSourceNote(false).text).toBe('Local data');
  });
});
