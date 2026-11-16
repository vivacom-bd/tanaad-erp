export const fmtUGX = (n: number) => `UGX ${Math.round(n).toLocaleString('en-UG')}`;
export const fmtUSD = (n: number) => `$${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
export const fmtMoney = (n: number, c: 'UGX' | 'USD') => (c === 'USD' ? fmtUSD(n) : fmtUGX(n));
export const fmtKg = (n: number) => `${n.toLocaleString('en-UG', { maximumFractionDigits: 1 })} kg`;
export const fmtNum = (n: number) => n.toLocaleString('en-UG');
export const fmtPct = (n: number, signed = false) => `${signed && n > 0 ? '+' : ''}${n.toFixed(1)}%`;
export const compact = (n: number) => (Math.abs(n) >= 1_000_000 ? `${(n / 1_000_000).toFixed(2)}M` : Math.abs(n) >= 1_000 ? `${Math.round(n / 1_000)}k` : `${Math.round(n)}`);
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
export const fmtDay = (day: string) => { const d = new Date(day + 'T00:00:00Z'); return `${DAYS[d.getUTCDay()]} ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`; };
export const fmtDayShort = (day: string) => { const d = new Date(day + 'T00:00:00Z'); return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}`; };
export const fmtDayLong = (day: string) => { const d = new Date(day + 'T00:00:00Z'); return `${DAYS[d.getUTCDay()]}, ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`; };
/** The business time zone: every instant the API sends is UTC (`...Z`); people read it in Kampala time. */
export const TZ = 'Africa/Kampala';
const ZONED = /(Z|[+-]\d{2}:?\d{2})$/;
const kampalaParts = (iso: string): { day: string; hour: string; minute: string } | null => {
  if (!ZONED.test(iso)) return null; // no zone given: the wall-clock text is already local
  const t = new Date(iso); if (Number.isNaN(t.getTime())) return null;
  const k = new Date(t.getTime() + 3 * 3600_000).toISOString(); // Kampala is UTC+3 all year, no daylight saving
  return { day: k.slice(0, 10), hour: k.slice(11, 13), minute: k.slice(14, 16) };
};
/** The Kampala calendar day of an instant (a plain date passes through). */
export const kampalaDay = (iso: string) => iso.length <= 10 ? iso : kampalaParts(iso)?.day ?? iso.slice(0, 10);
/** Hours since midnight in Kampala time (14.5 = 14:30). */
export const kampalaHour = (iso: string) => { const p = kampalaParts(iso); if (p) return +p.hour + +p.minute / 60; const m = iso.match(/T(\d{2}):(\d{2})/); return m ? +m[1] + +m[2] / 60 : 0; };
export const fmtTime = (iso: string) => {
  const p = kampalaParts(iso); if (p) return `${p.hour}:${p.minute}`;
  const m = iso.match(/T(\d{1,2}):(\d{2})/); return m ? `${m[1].padStart(2, '0')}:${m[2]}` : iso;
};
export const fmtDateTime = (iso: string) => `${fmtDayShort(kampalaDay(iso))} ${fmtTime(iso)}`;
export const initials = (name: string) => name.split(' ').map((x) => x[0]).join('').slice(0, 2).toUpperCase();
