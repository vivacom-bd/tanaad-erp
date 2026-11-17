import { useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { ArrowRight, Eye, EyeOff, Fingerprint, KeyRound, Lock, User as UserIcon } from 'lucide-react';
import type { SiteCode, User } from '@tanaad/domain';
import { useStore } from '@/data/store';
import { SITE_LIST, SITES } from '@/config/sites';
import { roleLabel } from '@/config/roles';
import { Avatar, Button, ChipGroup, Dialog, Field, Input, Note, PinPad, Tabs } from '@/components/ui';
import { BrandPanel, Logo } from './BrandPanel';
import { DEMO_ACCOUNTS_LIST, type DemoAccount } from './demoAccounts';
import { isReal, realLogin, realPinLogin, signInMessage } from '@/data/api/sync';

const DEMO_PIN = '1234';
/** Dev/demo servers only: build with VITE_DEMO_ACCOUNTS=1 to get a "Use a demo account" picker on the real sign-in. Never set in production. */
const DEMO_ACCOUNTS = import.meta.env.VITE_DEMO_ACCOUNTS === '1';
const DEMO_PASSWORD = 'password';
/** Same sign-in name the API seeds: the user id without "u_" and underscores (u_sm_bags → smbags). */
const usernameOf = (u: User) => u.id.replace(/^u_/, '').replace(/_/g, '');
const whereOf = (u: User) => { const sites = [...new Set(u.roles.map((r) => r.siteId).filter(Boolean))] as SiteCode[]; return sites.length ? sites.map((x) => SITES[x].shortName).join(' + ') : 'Group'; };

/**
 * Demo access: choose an office account directly, or use the shared-tablet PIN flow.
 * Production authentication belongs to the future API and is not simulated here.
 */
export function Login() {
  const nav = useNavigate(); const users = useStore((s) => s.users); const logIn = useStore((s) => s.logIn);
  const [mode, setMode] = useState<'password' | 'pin'>('password');
  const [username, setUsername] = useState(''); const [password, setPassword] = useState(''); const [show, setShow] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tabletSite, setTabletSite] = useState<SiteCode>('mats'); const [pinUser, setPinUser] = useState<User | null>(null); const [pinError, setPinError] = useState<string | null>(null);

  const enter = (u: User, method: 'password' | 'pin') => { logIn(u.id, method); nav({ to: '/portals' as never }); };
  const [busy, setBusy] = useState(false); const [pick, setPick] = useState(false);
  const pickAccount = async (a: DemoAccount) => {
    setBusy(true); setError(null);
    try { const code = await realLogin(a.username, DEMO_PASSWORD); const me = useStore.getState().users.find((x) => x.id === code); if (me) { setPick(false); enter(me, 'password'); } else setError(`Signed in, but this app has no profile for ${code}.`); }
    catch (e) { setError(signInMessage(e)); }
    finally { setBusy(false); }
  };
  const accounts = DEMO_ACCOUNTS ? DEMO_ACCOUNTS_LIST : [];
  const submitReal = async () => {
    if (!username.trim()) return setError('Enter your username.');
    if (!password) return setError('Enter your password.');
    setError(null); setBusy(true);
    try {
      const code = await realLogin(username.trim().toLowerCase(), password);
      const user = useStore.getState().users.find((u) => u.id === code);
      if (!user) return setError(`Signed in, but this app has no profile for ${code}.`);
      enter(user, 'password');
    } catch (e) {
      setError(signInMessage(e));
    } finally { setBusy(false); }
  };
  const submit = () => {
    if (isReal()) return void submitReal();
    const user = users.find((u) => usernameOf(u) === username.trim().toLowerCase());
    if (!username.trim()) return setError('Enter your username.');
    if (!user) return setError('Account not found. Choose one below.');
    if (!password) return setError('Enter your password.');
    setError(null);
    enter(user, 'password');
  };
  const demo = useMemo(() => {
    if (isReal()) return []; // real accounts live on the server; there is no list to pick from
    const order = ['u_ceo1', 'u_sm_mats', 'u_sup_mats', 'u_cash_mats', 'u_store_mats', 'u_mech', 'u_sales_mats', 'u_sm_bags', 'u_sup_bags', 'u_sm_rec', 'u_sup_rec', 'u_admin'];
    return order.map((id) => users.find((u) => u.id === id)).filter((u): u is User => !!u);
  }, [users]);
  const floor = useMemo(() => users.filter((u) => u.roles.some((r) => r.siteId === tabletSite && ['supervisor', 'storekeeper', 'gate', 'mechanic', 'cashier'].includes(r.role))), [users, tabletSite]);

  return (
    <div className="h-dvh overflow-auto bg-[var(--bg)] grid lg:grid-cols-[minmax(420px,46%)_1fr]">
      <BrandPanel />
      <div className="flex items-center justify-center p-5 sm:p-10">
        <div className="w-full max-w-[440px]">
          <div className="lg:hidden mb-8"><Logo /></div>
          <h2 className="text-[24px] font-semibold tracking-[-0.02em]">Sign in</h2>
          <p className="t-base text-[var(--text-2)] mt-1 mb-5">Use an office account, or sign in on a shared floor tablet.</p>

          <Tabs className="mb-4" value={mode} onChange={(m) => { setMode(m); setError(null); setPinUser(null); setPinError(null); }} tabs={[{ value: 'password', label: <span className="inline-flex items-center gap-1.5"><KeyRound className="size-3.5" />Office account</span> }, { value: 'pin', label: <span className="inline-flex items-center gap-1.5"><Fingerprint className="size-3.5" />Shared tablet</span> }]} />

          {mode === 'password' ? (
            <>
              <form onSubmit={(e) => { e.preventDefault(); submit(); }} className="surface p-5">
                <Field label="Username" className="mb-3">
                  <div className="relative">
                    <UserIcon className="size-4 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-3)]" />
                    <Input big autoFocus autoComplete="username" autoCapitalize="none" placeholder="Username, e.g. smbags" value={username} onChange={(e) => { setUsername(e.target.value); setError(null); }} className="pl-9" />
                  </div>
                </Field>
                <Field label="Password" className="mb-4">
                  <div className="relative">
                    <Lock className="size-4 absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-3)]" />
                    <Input big type={show ? 'text' : 'password'} autoComplete="current-password" placeholder="Password" value={password} onChange={(e) => { setPassword(e.target.value); setError(null); }} className="pl-9 pr-10" />
                    <button type="button" onClick={() => setShow((value) => !value)} aria-label={show ? 'Hide password' : 'Show password'} className="absolute right-2 top-1/2 -translate-y-1/2 size-7 grid place-items-center rounded-md text-[var(--text-3)] hover:bg-[var(--hover)]">
                      {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                    </button>
                  </div>
                </Field>
                {error && <Note tone="bad" className="mb-3">{error}</Note>}
                <Button type="submit" variant="primary" size="lg" block disabled={busy}>Sign in<ArrowRight className="size-4" /></Button>
                <p className="t-xs text-[var(--text-3)] text-center mt-3">{isReal() ? 'Signs in against the Tanaad server.' : 'Enter your password to continue.'}</p>
              </form>

              {demo.length > 0 && (
              <div className="mt-5">
                <div className="flex items-center gap-3 mb-2">
                  <span className="t-label">Accounts</span>
                  <span className="flex-1 border-t border-[var(--line)]" />
                </div>
                <div className="surface overflow-hidden max-h-[224px] overflow-y-auto scrollbar-thin">
                  {demo.map((u) => (
                    <button key={u.id} type="button" onClick={() => { setUsername(usernameOf(u)); setPassword('password'); setError(null); }}
                      className="group w-full flex items-center gap-2.5 px-3 py-2 text-left border-b border-[var(--line)] last:border-b-0 hover:bg-[var(--hover)]">
                      <Avatar name={u.name} seed={u.avatarSeed} size={26} />
                      <span className="min-w-0 flex-1">
                        <span className="block t-base font-medium truncate">{u.name}</span>
                        <span className="block t-xs text-[var(--text-3)] truncate">{usernameOf(u)} · {[...new Set(u.roles.map((r) => roleLabel(r.role)))].join(' + ')} · {whereOf(u)}</span>
                      </span>
                      <ArrowRight className="size-4 text-[var(--text-4)] group-hover:text-[var(--text)]" />
                    </button>
                  ))}
                </div>
              </div>
              )}
              {DEMO_ACCOUNTS && isReal() && (
                <div className="mt-5">
                  <Button block onClick={() => setPick(true)}>Use a demo account</Button>
                  <Dialog open={pick} onClose={() => setPick(false)} title="Pick a demo account" width={520}>
                    {error && <Note tone="bad" className="mb-3">{error}</Note>}
                    <div className="surface overflow-hidden max-h-[420px] overflow-y-auto scrollbar-thin">
                      {accounts.map((a) => (
                        <button key={a.username} type="button" disabled={busy} onClick={() => void pickAccount(a)}
                          className="group w-full flex items-center gap-2.5 px-3 py-2 text-left border-b border-[var(--line)] last:border-b-0 hover:bg-[var(--hover)] disabled:opacity-60">
                          <Avatar name={a.name} seed={a.name.split(' ').map((w) => w[0]).join('')} size={26} />
                          <span className="min-w-0 flex-1">
                            <span className="block t-base font-medium truncate">{a.name}</span>
                            <span className="block t-xs text-[var(--text-3)] truncate">{a.role} · {a.where} · {a.username}</span>
                          </span>
                          <ArrowRight className="size-4 text-[var(--text-4)] group-hover:text-[var(--text)]" />
                        </button>
                      ))}
                    </div>
                  </Dialog>
                </div>
              )}
            </>
          ) : (
            <div className="surface p-5">
              {!pinUser ? <>
                <div className="t-sm font-medium text-[var(--text-2)] mb-1.5">This tablet belongs to</div>
                <ChipGroup className="mb-4" value={tabletSite} onChange={setTabletSite} options={SITE_LIST.map((x) => ({ value: x.id, label: x.name }))} />
                <div className="t-sm font-medium text-[var(--text-2)] mb-1.5">Who are you?</div>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                  {floor.map((u) => (
                    <button key={u.id} onClick={() => { setPinUser(u); setPinError(null); }} className="rounded-lg border border-[var(--line-2)] bg-[var(--surface)] hover:bg-[var(--hover)] p-3 flex flex-col items-center gap-1.5 text-center lift">
                      <Avatar name={u.name} seed={u.avatarSeed} size={44} />
                      <span className="t-base font-medium leading-tight">{u.name}</span>
                      <span className="t-xs text-[var(--text-3)]">{[...new Set(u.roles.filter((r) => r.siteId === tabletSite).map((r) => roleLabel(r.role)))].join(' · ')}</span>
                    </button>
                  ))}
                </div>
              </> : <>
                <div className="flex items-center gap-3 mb-4"><Avatar name={pinUser.name} seed={pinUser.avatarSeed} size={44} /><div className="flex-1"><div className="t-lg font-semibold">{pinUser.name}</div><div className="t-sm text-[var(--text-3)]">{SITES[tabletSite].name}</div></div><Button size="sm" variant="ghost" onClick={() => setPinUser(null)}>Not you?</Button></div>
                <PinPad title="Enter your 4-digit PIN" error={pinError} onSubmit={(pin) => {
                  if (isReal()) { setPinError(null); realPinLogin(usernameOf(pinUser), pin).then((code) => { const u = useStore.getState().users.find((x) => x.id === code); if (u) enter(u, 'pin'); else setPinError(`Signed in, but this app has no profile for ${code}.`); }).catch((e) => setPinError(signInMessage(e))); return; }
                  if (pin === DEMO_PIN) enter(pinUser, 'pin'); else setPinError('Wrong PIN. Try again.');
                }} />
              </>}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
