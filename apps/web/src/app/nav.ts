import type { Role, SiteCode } from '@tanaad/domain';
import {
  BarChart3,
  Bell,
  Boxes,
  ClipboardList,
  Factory,
  FlaskConical,
  Gauge,
  Home,
  Landmark,
  LayoutDashboard,
  MessageSquareText,
  Package,
  Recycle,
  Scale,
  Scissors,
  ScrollText,
  Settings,
  ShoppingCart,
  Tag,
  Truck,
  Users,
  Wallet,
  Wrench,
  type LucideIcon,
} from 'lucide-react';

export interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  roles?: Role[];
  sites?: SiteCode[];
  group?: string;
  /** "g x" style jump shortcut */
  keys?: string;
  /** extra words the command palette matches on */
  words?: string;
  /** the work area this page is a tab of */
  hub?: string;
}

export const SITE_NAV: NavItem[] = [
  { to: 'today', hub: 'today', keys: 'g t', label: 'Today', icon: Home },
  { to: 'dashboard', hub: 'dashboard', keys: 'g d', label: 'Site overview', icon: LayoutDashboard },

  // Production
  { to: 'production/wall', hub: 'production', keys: 'g p', label: 'Machine wall', icon: Factory, group: 'Production', roles: ['supervisor', 'site_manager', 'ceo', 'group_admin', 'mechanic'] },
  { to: 'production/mixer', hub: 'production', label: 'Mixer', icon: FlaskConical, group: 'Production', sites: ['mats'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/extruders', hub: 'production', label: 'Extruders', icon: Factory, group: 'Production', sites: ['mats'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/finishing', hub: 'production', label: 'Finishing', icon: Tag, group: 'Production', sites: ['mats'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/waste', hub: 'production', label: 'Waste', icon: Recycle, group: 'Production', sites: ['mats'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/raw', hub: 'production', label: 'Raw material issue', icon: Package, group: 'Production', sites: ['bags'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/rolls', hub: 'production', label: 'Roll stickers', icon: Tag, group: 'Production', sites: ['bags'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/convert', hub: 'production', label: 'Bag conversion', icon: Scissors, group: 'Production', sites: ['bags'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/scrap', hub: 'production', label: 'Film scrap', icon: Recycle, group: 'Production', sites: ['bags'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/intake', hub: 'production', label: 'Scrap weigh-in', icon: Scale, group: 'Production', sites: ['recycling'], roles: ['supervisor', 'site_manager', 'gate', 'storekeeper', 'cashier'] },
  { to: 'production/collectors', hub: 'production', label: 'Collector ledger', icon: ClipboardList, group: 'Production', sites: ['recycling'], roles: ['supervisor', 'site_manager', 'cashier', 'ceo'] },
  { to: 'production/sacks', hub: 'production', label: 'Pellet sacks', icon: Tag, group: 'Production', sites: ['recycling'], roles: ['supervisor', 'site_manager'] },
  { to: 'production/sheet', hub: 'production', keys: 'g h', label: 'Shift handover', icon: ScrollText, group: 'Production', roles: ['supervisor', 'site_manager', 'ceo', 'group_admin'] },
  { to: 'production/labels', hub: 'production', keys: 'g j', label: 'Print labels', icon: Tag, group: 'Production', roles: ['supervisor', 'site_manager', 'storekeeper'] },
  { to: 'production/scan', hub: 'production', keys: 'g f', label: 'Scan label', icon: Tag, group: 'Production' },

  // People & Attendance
  { to: 'people/attendance', hub: 'people', keys: 'g a', label: 'Attendance', icon: Users, group: 'People', roles: ['supervisor', 'site_manager', 'ceo', 'group_admin'] },
  { to: 'people/signup', hub: 'people', keys: 'g u', label: 'Casual sign-up', icon: Users, group: 'People', roles: ['supervisor', 'site_manager'] },
  { to: 'people/registry', hub: 'people', label: 'Workers', icon: ClipboardList, group: 'People', roles: ['site_manager', 'supervisor', 'ceo', 'group_admin'] },
  { to: 'people/settlement', hub: 'people', keys: 'g l', label: 'Wages', icon: Wallet, group: 'People', roles: ['site_manager', 'cashier', 'ceo', 'group_admin'] },

  // Cash & Expenses
  { to: 'cash', hub: 'cash', keys: 'g c', label: 'Cash book', icon: Wallet, group: 'Cash', roles: ['cashier', 'site_manager', 'ceo', 'group_admin'] },
  { to: 'cash/expense', hub: 'cash', keys: 'g e', label: 'Record expense', icon: Wallet, group: 'Cash', roles: ['cashier', 'site_manager'] },

  // Stock & Receiving
  { to: 'stock', hub: 'stock', keys: 'g s', label: 'Stock', icon: Boxes, group: 'Stock', roles: ['storekeeper', 'site_manager', 'ceo', 'group_admin', 'sales'] },
  { to: 'stock/count', hub: 'stock', keys: 'g n', label: 'Stock count', icon: ClipboardList, group: 'Stock', roles: ['storekeeper', 'site_manager'] },
  { to: 'stock/tagging', hub: 'stock', label: 'Tagging', icon: Tag, group: 'Stock', roles: ['storekeeper', 'site_manager'] },
  { to: 'stock/receive', hub: 'stock', label: 'Receive', icon: Scale, group: 'Stock', roles: ['storekeeper', 'gate', 'site_manager'] },
  { to: 'purchases', hub: 'stock', keys: 'g y', label: 'Purchases', icon: Package, group: 'Stock', roles: ['site_manager', 'cashier', 'storekeeper', 'ceo', 'group_admin'], words: 'import resin landed cost supplier' },
  { to: 'stock/movements', hub: 'stock', label: 'Movements', icon: Boxes, group: 'Stock', roles: ['storekeeper', 'site_manager'] },

  // Dispatch & Gate
  { to: 'stock/dispatch', hub: 'dispatch', keys: 'g x', label: 'Load lorry', icon: Truck, group: 'Stock', roles: ['storekeeper', 'gate', 'site_manager', 'ceo', 'group_admin'] },
  { to: 'stock/deliveries', hub: 'dispatch', keys: 'g v', label: 'Deliveries', icon: Truck, group: 'Stock', roles: ['storekeeper', 'sales', 'site_manager', 'driver', 'ceo'] },

  // Maintenance
  { to: 'stock/spares', hub: 'maintenance', keys: 'g r', label: 'Spares', icon: Wrench, group: 'Maintenance', roles: ['mechanic', 'storekeeper', 'site_manager', 'ceo', 'group_admin'] },
  { to: 'stock/maintenance', hub: 'maintenance', keys: 'g b', label: 'Breakdowns', icon: Wrench, group: 'Maintenance', roles: ['mechanic', 'site_manager', 'supervisor', 'ceo'] },

  // Sales & Orders
  { to: 'sales/orders', hub: 'sales', keys: 'g o', label: 'Orders', icon: ShoppingCart, group: 'Sales', roles: ['sales', 'site_manager', 'cashier', 'storekeeper', 'ceo', 'group_admin'] },
  { to: 'sales/clients', hub: 'sales', label: 'Clients', icon: Landmark, group: 'Sales', roles: ['sales', 'site_manager', 'ceo', 'group_admin'] },
  { to: 'sales/invoices', hub: 'sales', keys: 'g i', label: 'Invoices', icon: ScrollText, group: 'Sales', roles: ['sales', 'cashier', 'site_manager', 'ceo'] },

  // Reports
  { to: 'reports', hub: 'reports', keys: 'g m', label: 'Reports', icon: BarChart3, group: 'Manage', roles: ['site_manager', 'ceo', 'group_admin'] },

  // Settings
  { to: 'settings', hub: 'settings', keys: 'g ,', label: 'Settings', icon: Settings, group: 'Manage', roles: ['site_manager', 'ceo', 'group_admin'], words: 'master data products machines sizes units categories configuration' },
];

export const GROUP_NAV: NavItem[] = [
  { to: 'cockpit', hub: 'cockpit', keys: 'g c', label: 'Overview', icon: Gauge },
  { to: 'approvals', hub: 'decisions', keys: 'g a', label: 'Approvals', icon: Bell },
  { to: 'credit', hub: 'decisions', keys: 'g r', label: 'Credit', icon: Landmark },
  { to: 'brief', hub: 'records', keys: 'g b', label: 'Morning brief', icon: MessageSquareText },
  { to: 'audit', hub: 'records', keys: 'g u', label: 'Audit log', icon: ScrollText },
  { to: 'people', hub: 'finance', keys: 'g p', label: 'People', icon: Users },
  { to: 'pnl', hub: 'finance', keys: 'g l', label: 'P&L', icon: BarChart3 },
  { to: 'settings', hub: 'settings', keys: 'g ,', label: 'Settings', icon: Settings },
];

export const visibleSiteNav = (role: Role, site: SiteCode) =>
  SITE_NAV.filter((n) => (!n.roles || n.roles.includes(role)) && (!n.sites || n.sites.includes(site)));

export const ROLE_TABS: Record<Role, string[]> = {
  supervisor: ['today', 'production/wall', 'people/attendance', 'production/sheet', 'production/labels'],
  cashier: ['today', 'cash', 'cash/expense', 'people/settlement'],
  storekeeper: ['today', 'stock', 'stock/dispatch', 'stock/spares'],
  gate: ['today', 'stock/receive', 'stock/dispatch', 'production/scan'],
  mechanic: ['today', 'stock/spares', 'stock/maintenance', 'production/wall'],
  sales: ['today', 'sales/orders', 'sales/clients', 'stock/deliveries'],
  site_manager: ['today', 'dashboard', 'production/wall', 'people/attendance', 'cash', 'reports'],
  driver: ['today', 'stock/deliveries'],
  ceo: ['cockpit', 'approvals', 'credit', 'brief'],
  group_admin: ['cockpit', 'settings', 'people', 'audit'],
};

export interface Hub {
  key: string;
  label: string;
  icon: LucideIcon;
}

export const SITE_HUBS: Hub[] = [
  { key: 'today', label: 'Today', icon: Home },
  { key: 'dashboard', label: 'Site overview', icon: LayoutDashboard },
  { key: 'production', label: 'Production', icon: Factory },
  { key: 'people', label: 'People', icon: Users },
  { key: 'cash', label: 'Cash', icon: Wallet },
  { key: 'stock', label: 'Stock', icon: Boxes },
  { key: 'dispatch', label: 'Dispatch', icon: Truck },
  { key: 'maintenance', label: 'Maintenance', icon: Wrench },
  { key: 'sales', label: 'Sales', icon: ShoppingCart },
  { key: 'reports', label: 'Reports', icon: BarChart3 },
  { key: 'settings', label: 'Settings', icon: Settings },
];

export const GROUP_HUBS: Hub[] = [
  { key: 'cockpit', label: 'Overview', icon: Gauge },
  { key: 'decisions', label: 'Decisions', icon: Bell },
  { key: 'finance', label: 'Finance', icon: BarChart3 },
  { key: 'records', label: 'Records', icon: ScrollText },
  { key: 'settings', label: 'Settings', icon: Settings },
];
