import { killOwn } from './global-setup';

export default async function globalTeardown() {
  if (process.env.E2E_REUSE === '1') return;
  await killOwn();
}
