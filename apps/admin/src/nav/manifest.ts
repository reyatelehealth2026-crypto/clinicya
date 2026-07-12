import type { NavGroup, NavLeafItem, PrimaryNavItem } from '@reya/config';

/**
 * manifest.ts — the actual MENU DATA, ported 1:1 from includes/header.php
 * (read at lines ~356-548 of that file). Hrefs, Thai labels, role arrays,
 * icons, and 'match' prefix arrays are copied verbatim from the PHP source.
 * English labels (labelEn/titleEn/groupTitleEn) are FRESH copy authored for
 * this port — there is no EN source in the PHP UI, flag for later i18n
 * review. Every leaf is `servedBy: 'php'` — nothing has been cut over to
 * Next in this batch.
 *
 * Two PHP variables are request-dynamic and therefore modeled as a resolver
 * function instead of a baked-in literal:
 *   - `$isOdooMode` swaps a handful of hrefs/labels and adds two supply-menu
 *     items (mirrors the Odoo kill-switch — see CLAUDE.md "Odoo kill-switch").
 *   - `$unreadMessages` / `$pendingOrders` feed live badge counts — modeled
 *     here as `badgeKey`, resolved by the caller at render time, never as a
 *     static number.
 * `buildPrimaryNav()` / `buildMenuGroups()` take an explicit NavContext so
 * the Odoo branch is testable without any live DB/Odoo dependency.
 */

export interface NavContext {
  /** Mirrors `$isOdooMode` (config/config.php's ODOO_INTEGRATION_ENABLED gate + $orderDataSource === 'odoo'). */
  isOdooMode: boolean;
}

/** Default context used by the exported static manifests below: non-Odoo tenant, the common case. */
export const DEFAULT_NAV_CONTEXT: NavContext = { isOdooMode: false };

// ==================== L1 — Flat Primary Nav ($primaryNav / $primaryNavFooter) ====================
// "Flat nav is now the DEFAULT (2026-06-29)" per the PHP source comment — this is the real default
// nav, not the accordion ($menuGroups is the secondary/legacy structure, still ported below).

export function buildPrimaryNav(ctx: NavContext): PrimaryNavItem[] {
  return [
    {
      key: 'overview',
      icon: 'fa-gauge-high',
      labelTh: 'ภาพรวม',
      labelEn: 'Overview',
      href: ctx.isOdooMode ? '/odoo-dashboard' : '/dashboard?tab=executive',
      match: ['/dashboard', '/odoo-dashboard', '/analytics'],
      roles: ['owner', 'admin'],
      servedBy: 'php',
    },
    {
      key: 'inbox',
      icon: 'fa-comments',
      labelTh: 'กล่องข้อความ',
      labelEn: 'Inbox',
      href: '/inbox-v2',
      match: ['/inbox', '/messages'],
      roles: ['owner', 'admin', 'pharmacist', 'staff', 'marketing'],
      badgeKey: 'unreadMessages',
      servedBy: 'php',
    },
    {
      key: 'orders',
      icon: 'fa-receipt',
      labelTh: ctx.isOdooMode ? 'ออเดอร์ (Odoo)' : 'ออเดอร์',
      labelEn: 'Orders',
      href: '/shop/orders',
      match: ['/shop/orders', '/pos'],
      roles: ['owner', 'admin', 'staff'],
      badgeKey: 'pendingOrders',
      badgeColor: 'yellow',
      servedBy: 'php',
    },
    {
      key: 'inventory',
      icon: 'fa-boxes-stacked',
      labelTh: 'สินค้า & คลัง',
      labelEn: 'Inventory & Stock',
      href: '/inventory',
      match: ['/inventory', '/procurement', '/accounting'],
      roles: ['owner', 'admin', 'pharmacist', 'staff'],
      servedBy: 'php',
    },
    {
      key: 'pharmacy',
      icon: 'fa-prescription-bottle-medical',
      labelTh: 'งานเภสัช',
      labelEn: 'Pharmacy',
      href: '/pharmacy',
      match: ['/pharmacy', '/dispense-tracking', '/appointments-admin', '/pharmacist-video-calls'],
      roles: ['owner', 'admin', 'pharmacist'],
      servedBy: 'php',
    },
    {
      key: 'patients',
      icon: 'fa-user-group',
      labelTh: 'ลูกค้า & สมาชิก',
      labelEn: 'Patients & Members',
      href: '/users',
      match: ['/users', '/user-tags', '/membership', '/loyalty-members'],
      roles: ['owner', 'admin', 'pharmacist', 'staff'],
      servedBy: 'php',
    },
    {
      key: 'marketing',
      icon: 'fa-bullhorn',
      labelTh: 'การตลาด LINE',
      labelEn: 'LINE Marketing',
      href: '/broadcast',
      match: ['/broadcast', '/drip-campaigns', '/rich-menu', '/templates', '/liff-settings'],
      roles: ['owner', 'admin', 'marketing'],
      servedBy: 'php',
    },
    {
      key: 'reports',
      icon: 'fa-chart-line',
      labelTh: 'รายงาน',
      labelEn: 'Reports',
      href: '/analytics',
      match: ['/activity-logs', '/scheduled', '/triage-analytics'],
      roles: ['owner', 'admin'],
      servedBy: 'php',
    },
  ];
}

/** $primaryNavFooter — no Odoo-conditional fields, so this is a plain constant. */
export const PRIMARY_NAV_FOOTER: PrimaryNavItem[] = [
  {
    key: 'settings',
    icon: 'fa-gear',
    labelTh: 'ตั้งค่า',
    labelEn: 'Settings',
    href: '/settings',
    match: ['/settings', '/admin-users', '/admin/', '/consent-management'],
    roles: ['owner', 'admin', 'tech'],
    servedBy: 'php',
  },
];

/** Convenience: the default (non-Odoo) primary nav, matching $primaryNav in the common case. */
export const PRIMARY_NAV: PrimaryNavItem[] = buildPrimaryNav(DEFAULT_NAV_CONTEXT);

/** array_merge($primaryNav, $primaryNavFooter) — the full list the active-nav matcher walks. */
export function buildAllPrimaryNavItems(ctx: NavContext): PrimaryNavItem[] {
  return [...buildPrimaryNav(ctx), ...PRIMARY_NAV_FOOTER];
}

export const ALL_PRIMARY_NAV_ITEMS: PrimaryNavItem[] = buildAllPrimaryNavItems(DEFAULT_NAV_CONTEXT);

// ==================== L2 — Accordion Menu Groups ($menuGroups) ====================

function buildSupplyMenus(ctx: NavContext): NavLeafItem[] {
  const menus: NavLeafItem[] = [
    { titleTh: 'POS ขายหน้าร้าน', titleEn: 'POS (Storefront)', icon: '🛒', href: '/pos', servedBy: 'php' },
    {
      titleTh: ctx.isOdooMode ? 'รายการสั่งซื้อ (Odoo)' : 'รายการสั่งซื้อ',
      titleEn: ctx.isOdooMode ? 'Orders (Odoo)' : 'Orders',
      icon: '🧾',
      href: '/shop/orders',
      badgeKey: 'pendingOrders',
      servedBy: 'php',
    },
    { titleTh: 'คลังสินค้า', titleEn: 'Inventory', icon: '📦', href: '/inventory', servedBy: 'php' },
    { titleTh: 'จัดซื้อ', titleEn: 'Procurement', icon: '🚚', href: '/procurement', servedBy: 'php' },
    { titleTh: 'บัญชี', titleEn: 'Accounting', icon: '💰', href: '/accounting', servedBy: 'php' },
  ];

  if (ctx.isOdooMode) {
    menus.push(
      { titleTh: 'Odoo Dashboard', titleEn: 'Odoo Dashboard', icon: '🛰️', href: '/odoo-dashboard', odooOnly: true, servedBy: 'php' },
      {
        titleTh: 'Odoo Webhooks',
        titleEn: 'Odoo Webhooks',
        icon: '🪝',
        href: '/odoo-webhooks-dashboard',
        odooOnly: true,
        servedBy: 'php',
      }
    );
  }

  return menus;
}

export function buildMenuGroups(ctx: NavContext): NavGroup[] {
  return [
    {
      groupId: 'insights',
      groupTitleTh: 'ภาพรวมและสถิติ',
      groupTitleEn: 'Overview & Insights',
      groupIcon: '📊',
      roles: ['owner', 'admin'],
      menus: [
        {
          titleTh: 'Dashboard',
          titleEn: 'Dashboard',
          icon: '🏠',
          submenus: [
            {
              titleTh: ctx.isOdooMode ? 'Odoo Overview' : 'Executive Overview',
              titleEn: ctx.isOdooMode ? 'Odoo Overview' : 'Executive Overview',
              icon: '🏠',
              href: ctx.isOdooMode ? '/odoo-dashboard' : '/dashboard?tab=executive',
              servedBy: 'php',
            },
            {
              titleTh: 'CRM Dashboard',
              titleEn: 'CRM Dashboard',
              icon: '🏠',
              href: '/dashboard?tab=crm',
              servedBy: 'php',
            },
            ...(ctx.isOdooMode
              ? [
                  {
                    titleTh: 'จัดการลูกค้า Odoo',
                    titleEn: 'Manage Odoo Customers',
                    icon: '🏠',
                    href: '/odoo-dashboard',
                    odooOnly: true,
                    servedBy: 'php' as const,
                  },
                ]
              : []),
          ],
        },
        { titleTh: 'วิเคราะห์ข้อมูล', titleEn: 'Analytics', icon: '📈', href: '/analytics', servedBy: 'php' },
        { titleTh: 'ประวัติการใช้งาน', titleEn: 'Activity Log', icon: '📋', href: '/activity-logs', servedBy: 'php' },
      ],
    },
    {
      groupId: 'clinical',
      groupTitleTh: 'งานบริการคลินิก',
      groupTitleEn: 'Clinical Services',
      groupIcon: '🩺',
      roles: ['owner', 'admin', 'pharmacist'],
      menus: [
        { titleTh: 'ห้องยา / จ่ายยา', titleEn: 'Pharmacy / Dispensing', icon: '💊', href: '/pharmacy', servedBy: 'php' },
        { titleTh: 'ติดตามการจ่ายยา', titleEn: 'Dispense Tracking', icon: '🔔', href: '/dispense-tracking', servedBy: 'php' },
        { titleTh: 'นัดหมาย', titleEn: 'Appointments', icon: '📅', href: '/appointments-admin', servedBy: 'php' },
        { titleTh: 'ปรึกษาออนไลน์', titleEn: 'Video Consultation', icon: '📹', href: '/pharmacist-video-calls', servedBy: 'php' },
      ],
    },
    {
      groupId: 'patient',
      groupTitleTh: 'ดูแลลูกค้า',
      groupTitleEn: 'Customer Care',
      groupIcon: '👥',
      roles: ['owner', 'admin', 'marketing', 'staff'],
      menus: [
        {
          titleTh: 'กล่องข้อความ',
          titleEn: 'Inbox',
          icon: '💬',
          href: '/inbox-v2',
          badgeKey: 'unreadMessages',
          servedBy: 'php',
        },
        { titleTh: 'สถิติแชท', titleEn: 'Chat Analytics', icon: '📊', href: '/inbox-v2?tab=analytics', servedBy: 'php' },
        { titleTh: 'รายชื่อลูกค้า', titleEn: 'Customer List', icon: '📇', href: '/users', servedBy: 'php' },
        { titleTh: 'บรอดแคสต์', titleEn: 'Broadcast', icon: '📢', href: '/broadcast', servedBy: 'php' },
        { titleTh: 'ระบบสมาชิก', titleEn: 'Membership', icon: '💳', href: '/membership', servedBy: 'php' },
        {
          titleTh: 'สมาชิกเบอร์ (จ่ายแต้ม)',
          titleEn: 'Loyalty Points Members',
          icon: '🎁',
          href: '/loyalty-members',
          servedBy: 'php',
        },
      ],
    },
    {
      groupId: 'supply',
      groupTitleTh: 'คลังสินค้าและยอดขาย',
      groupTitleEn: 'Inventory & Sales',
      groupIcon: '📦',
      roles: ['owner', 'admin', 'staff'],
      menus: buildSupplyMenus(ctx),
    },
    {
      groupId: 'facility',
      groupTitleTh: 'ตั้งค่าร้านค้า',
      groupTitleEn: 'Store Settings',
      groupIcon: '⚙️',
      roles: ['owner', 'admin', 'tech'],
      menus: [
        { titleTh: 'ตั้งค่าระบบ', titleEn: 'System Settings', icon: '🔧', href: '/settings', servedBy: 'php' },
        { titleTh: 'เว็บไซต์ร้าน', titleEn: 'Store Website', icon: '🌐', href: '/website', servedBy: 'php' },
        {
          titleTh: 'ตั้งค่าร้านออนไลน์',
          titleEn: 'Mini App Settings',
          icon: '📱',
          href: '/admin/miniapp-settings.php',
          servedBy: 'php',
        },
        {
          titleTh: 'Landing Page',
          titleEn: 'Landing Page',
          icon: '🏠',
          href: '/admin/landing-settings',
          servedBy: 'php',
        },
        { titleTh: 'Rich Menu', titleEn: 'Rich Menu', icon: '🎨', href: '/rich-menu', servedBy: 'php' },
        { titleTh: 'ศูนย์ช่วยเหลือ', titleEn: 'Help Center', icon: '📚', href: '/help', servedBy: 'php' },
      ],
    },
  ];
}

/** Convenience: the default (non-Odoo) menu groups. */
export const MENU_GROUPS: NavGroup[] = buildMenuGroups(DEFAULT_NAV_CONTEXT);

export { findActivePrimaryNavKey } from './activeMatch';
export type { MatchableNavItem } from './activeMatch';
