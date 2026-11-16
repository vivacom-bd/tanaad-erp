import React from 'react';
import ReactDOM from 'react-dom/client';
import { RouterProvider } from '@tanstack/react-router';
import '@/design-system/tokens.css';
import '@/app/prefs';
import { router } from '@/app/router';
import { initPwa } from '@/app/pwa';
import { useStore } from '@/data/store';
import { clearSession, loadSession } from '@/data/api/auth';
import { setSessionExpiredHandler } from '@/data/api/http';
import { isReal, startSync, stopSync } from '@/data/api/sync';
import { readMirror, restorable, writeMirror } from '@/data/authMirror';

initPwa(
  () => {}, // offline caching is silent; nobody needs to be told the files were cached
  () => useStore.getState().toast({ kind: 'info', title: 'A new version is ready', detail: 'Reload from the status bar when convenient.' }),
);

// A revoked or expired token ends the session at once: back to sign-in with a plain message, never a shell with a blank user.
setSessionExpiredHandler(() => {
  if (!isReal() || !loadSession()) return;
  stopSync(); clearSession(); useStore.getState().signOut();
  useStore.getState().toast({ kind: 'warn', title: 'Your session has ended', detail: 'Sign in again to continue.' });
  void router.navigate({ to: '/' });
});

// Wait for the local store (IndexedDB) before the first route resolves. Otherwise a reload or an app-shortcut launch
// would briefly see "no session" and bounce to sign-in, and late hydration could overwrite a fresh sign-in.
const root = ReactDOM.createRoot(document.getElementById('root')!);
let started = false;
const start = () => {
  if (started) return; started = true;
  if (isReal()) {
    // A reload right after sign-in can beat the IndexedDB write; the synchronous mirror restores the sign-in and workspace.
    const saved = restorable(useStore.getState(), readMirror(), loadSession()?.user.code ?? null);
    if (saved) useStore.setState({ auth: saved.auth, session: saved.session });
    useStore.subscribe((s) => writeMirror({ auth: s.auth, session: s.session }));
    const cur = useStore.getState(); writeMirror({ auth: cur.auth, session: cur.session });
  }
  if (isReal() && loadSession()) startSync(); /* real mode resumes a saved API session; demo never syncs */ root.render(<React.StrictMode><RouterProvider router={router} /></React.StrictMode>); };
if (useStore.persist.hasHydrated()) start();
else { useStore.persist.onFinishHydration(start); setTimeout(start, 2500); }
