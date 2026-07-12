import type { MenuRole, NavLeafItem, PrimaryNavItem } from '@reya/config';
import { isNavParentItem, isRoleAllowed } from '@reya/config';
import {
  ALL_PRIMARY_NAV_ITEMS,
  buildMenuGroups,
  buildPrimaryNav,
  DEFAULT_NAV_CONTEXT,
  MENU_GROUPS,
  PRIMARY_NAV,
  PRIMARY_NAV_FOOTER,
} from './manifest';

function visiblePrimaryKeys(role: MenuRole): string[] {
  return ALL_PRIMARY_NAV_ITEMS.filter((item) => isRoleAllowed(item.roles, role)).map((item) => item.key);
}

function collectAllLeafItems(): NavLeafItem[] {
  const leaves: NavLeafItem[] = [];
  for (const group of MENU_GROUPS) {
    for (const menu of group.menus) {
      if (isNavParentItem(menu)) {
        leaves.push(...menu.submenus);
      } else {
        leaves.push(menu);
      }
    }
  }
  return leaves;
}

describe('primary nav — role-filter correctness (one case per MenuRole literal)', () => {
  it('owner sees every item', () => {
    expect(visiblePrimaryKeys('owner')).toEqual([
      'overview',
      'inbox',
      'orders',
      'inventory',
      'pharmacy',
      'patients',
      'marketing',
      'reports',
      'settings',
    ]);
  });

  it('admin sees every item', () => {
    expect(visiblePrimaryKeys('admin')).toEqual([
      'overview',
      'inbox',
      'orders',
      'inventory',
      'pharmacy',
      'patients',
      'marketing',
      'reports',
      'settings',
    ]);
  });

  it('pharmacist sees inbox, inventory, pharmacy, patients', () => {
    expect(visiblePrimaryKeys('pharmacist')).toEqual(['inbox', 'inventory', 'pharmacy', 'patients']);
  });

  it('marketing sees inbox, marketing', () => {
    expect(visiblePrimaryKeys('marketing')).toEqual(['inbox', 'marketing']);
  });

  it('tech sees only settings', () => {
    expect(visiblePrimaryKeys('tech')).toEqual(['settings']);
  });

  it('staff sees inbox, orders, inventory, patients', () => {
    expect(visiblePrimaryKeys('staff')).toEqual(['inbox', 'orders', 'inventory', 'patients']);
  });
});

describe('menu groups — role-filter correctness', () => {
  it('owner/admin see all 5 groups; pharmacist/marketing/staff/tech see a strict subset', () => {
    const groupIdsFor = (role: MenuRole) => MENU_GROUPS.filter((g) => isRoleAllowed(g.roles, role)).map((g) => g.groupId);

    expect(groupIdsFor('owner')).toEqual(['insights', 'clinical', 'patient', 'supply', 'facility']);
    expect(groupIdsFor('admin')).toEqual(['insights', 'clinical', 'patient', 'supply', 'facility']);
    expect(groupIdsFor('pharmacist')).toEqual(['clinical']);
    expect(groupIdsFor('marketing')).toEqual(['patient']);
    expect(groupIdsFor('staff')).toEqual(['patient', 'supply']);
    expect(groupIdsFor('tech')).toEqual(['facility']);
  });
});

describe('servedBy defaults to php — guard test asserting nothing is servedBy:next yet', () => {
  it('every primary nav item (including footer) is servedBy: php', () => {
    for (const item of ALL_PRIMARY_NAV_ITEMS) {
      expect(item.servedBy).toBe('php');
    }
    expect(ALL_PRIMARY_NAV_ITEMS.some((item: PrimaryNavItem) => item.servedBy === 'next')).toBe(false);
  });

  it('every accordion menu leaf (including submenus) is servedBy: php', () => {
    const leaves = collectAllLeafItems();
    expect(leaves.length).toBeGreaterThan(0);
    for (const leaf of leaves) {
      expect(leaf.servedBy).toBe('php');
    }
    expect(leaves.some((leaf) => leaf.servedBy === 'next')).toBe(false);
  });
});

describe('completeness — every item has non-empty labelTh/labelEn/href (or titleTh/titleEn/href)', () => {
  it('every primary nav item', () => {
    for (const item of ALL_PRIMARY_NAV_ITEMS) {
      expect(item.labelTh.length).toBeGreaterThan(0);
      expect(item.labelEn.length).toBeGreaterThan(0);
      expect(item.href.length).toBeGreaterThan(0);
      expect(item.match.length).toBeGreaterThan(0);
    }
  });

  it('every accordion menu leaf', () => {
    for (const leaf of collectAllLeafItems()) {
      expect(leaf.titleTh.length).toBeGreaterThan(0);
      expect(leaf.titleEn.length).toBeGreaterThan(0);
      expect(leaf.href.length).toBeGreaterThan(0);
    }
  });

  it('every group has a non-empty groupTitleTh/groupTitleEn', () => {
    for (const group of MENU_GROUPS) {
      expect(group.groupTitleTh.length).toBeGreaterThan(0);
      expect(group.groupTitleEn.length).toBeGreaterThan(0);
    }
  });
});

describe('Odoo kill-switch conditionals (buildPrimaryNav/buildMenuGroups ctx)', () => {
  it('non-Odoo default: overview -> /dashboard?tab=executive, orders label is plain "ออเดอร์"', () => {
    const nav = buildPrimaryNav(DEFAULT_NAV_CONTEXT);
    expect(nav.find((i) => i.key === 'overview')?.href).toBe('/dashboard?tab=executive');
    expect(nav.find((i) => i.key === 'orders')?.labelTh).toBe('ออเดอร์');
  });

  it('Odoo mode: overview -> /odoo-dashboard, orders label swaps to "ออเดอร์ (Odoo)"', () => {
    const nav = buildPrimaryNav({ isOdooMode: true });
    expect(nav.find((i) => i.key === 'overview')?.href).toBe('/odoo-dashboard');
    expect(nav.find((i) => i.key === 'orders')?.labelTh).toBe('ออเดอร์ (Odoo)');
  });

  it('Odoo mode adds two extra supply-group items (Odoo Dashboard, Odoo Webhooks)', () => {
    const nonOdoo = buildMenuGroups({ isOdooMode: false }).find((g) => g.groupId === 'supply')!;
    const odoo = buildMenuGroups({ isOdooMode: true }).find((g) => g.groupId === 'supply')!;

    expect(nonOdoo.menus).toHaveLength(5);
    expect(odoo.menus).toHaveLength(7);
    expect(odoo.menus.some((m) => !isNavParentItem(m) && m.titleTh === 'Odoo Dashboard')).toBe(true);
    expect(odoo.menus.some((m) => !isNavParentItem(m) && m.titleTh === 'Odoo Webhooks')).toBe(true);
  });

  it('PRIMARY_NAV/MENU_GROUPS constants match the non-Odoo default context', () => {
    expect(PRIMARY_NAV).toEqual(buildPrimaryNav(DEFAULT_NAV_CONTEXT));
    expect(MENU_GROUPS).toEqual(buildMenuGroups(DEFAULT_NAV_CONTEXT));
    expect(ALL_PRIMARY_NAV_ITEMS).toEqual([...PRIMARY_NAV, ...PRIMARY_NAV_FOOTER]);
  });
});
