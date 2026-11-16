import { defineConfig } from '@playwright/test';
import { resolve } from 'node:path';
import { API_URL, RUN_DIR, WEB_URL } from './support/env';

export default defineConfig({
  testDir: './tests',
  globalSetup: './support/global-setup.ts',
  globalTeardown: './support/global-teardown.ts',
  // One shared dev DB: specs run one at a time. Specs that mutate data must be ordered by their own file names.
  workers: 1,
  fullyParallel: false,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never', outputFolder: resolve(RUN_DIR, 'report') }]],
  outputDir: resolve(RUN_DIR, 'results'),
  use: {
    baseURL: WEB_URL,
    // System Google Chrome; no browser download.
    channel: 'chrome',
    headless: true,
    serviceWorkers: 'block',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
    viewport: { width: 1440, height: 900 },
    extraHTTPHeaders: {},
  },
  metadata: { api: API_URL },
});
