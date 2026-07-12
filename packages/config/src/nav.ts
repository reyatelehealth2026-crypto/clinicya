/**
 * nav.ts — shared navigation TYPES + the DB-role -> menu-role mapping.
 *
 * Port of the type shapes implied by includes/header.php's menu data
 * ($menuGroups, $primaryNav, $primaryNavFooter) plus getCurrentUserRole().
 * The actual MENU DATA (the literal Thai/English strings, hrefs, icons,
 * 'match' arrays) lives in apps/admin/src/nav/manifest.ts — this file only
 * hosts the types + the pure role-mapping function so both apps/admin and
 * any future Next app can share them without duplicating the shape.
 *
 * Kept dependency-free (no @reya/auth import) on purpose: header.php's role
 * gate reads $currentUser['role'] (the admin_users.role DB column) directly,
 * independent of session/auth plumbing — same here.
 */

/**
 * admin_users.role DB values — mirrors the $dbRole switch cases in
 * includes/header.php::getCurrentUserRole() (lines ~34-51).
 */
export type DbRole = 'super_admin' | 'admin' | 'pharmacist' | 'marketing' | 'tech' | 'staff';

/**
 * Menu-system role literals used throughout $menuGroups/$primaryNav 'roles'
 * arrays in includes/header.php. NOT the same strings as DbRole — 'owner' is
 * the menu-system name for the super_admin DB role.
 */
export type MenuRole = 'owner' | 'admin' | 'pharmacist' | 'marketing' | 'tech' | 'staff';

/**
 * Exact port of includes/header.php::getCurrentUserRole()'s switch statement.
 * Unknown/missing dbRole falls through to 'staff', mirroring the PHP
 * `default: return 'staff'` branch (and the `!isset($currentUser['role'])`
 * early-return, which this function's caller should map to `undefined`).
 */
export function mapDbRoleToMenuRole(dbRole: DbRole | undefined | null): MenuRole {
  switch (dbRole) {
    case 'super_admin':
      return 'owner';
    case 'admin':
      return 'admin';
    case 'pharmacist':
      return 'pharmacist';
    case 'marketing':
      return 'marketing';
    case 'tech':
      return 'tech';
    case 'staff':
    default:
      return 'staff';
  }
}

/**
 * Which stack currently serves a given nav destination. Every item ships as
 * 'php' until its target page is actually cut over to Next (Phase 2+) —
 * nothing in Phase 1 batch 2 has been ported yet, so every leaf in
 * apps/admin/src/nav/manifest.ts is 'php', unconditionally.
 */
export type ServedBy = 'php' | 'next';

/** A leaf destination inside the L1 flat rail ($primaryNav / $primaryNavFooter). */
export interface PrimaryNavItem {
  /** Stable identifier — mirrors the PHP array's 'key' (used for active-nav matching). */
  key: string;
  /** FontAwesome icon class, verbatim from the PHP source (e.g. 'fa-gauge-high'). */
  icon: string;
  /** Thai label — copied verbatim from the PHP source's 'label'. */
  labelTh: string;
  /** English gloss — fresh copy authored for this port; no EN source existed in PHP. Needs later i18n review. */
  labelEn: string;
  href: string;
  /**
   * Path-prefix list used by the longest-prefix-wins active matcher. NOTE:
   * despite the name, the PHP matcher (and this port) does a SUBSTRING
   * check (`strpos($currentPath, $prefix) !== false`), not a strict
   * "starts with" check — see apps/admin/src/nav/activeMatch.ts.
   */
  match: readonly string[];
  roles: readonly MenuRole[];
  /** Which live counter (if any) feeds this item's badge — resolved by the caller, not baked into static data. */
  badgeKey?: 'unreadMessages' | 'pendingOrders';
  badgeColor?: 'yellow';
  servedBy: ServedBy;
}

/** A leaf destination inside an L2 accordion group ($menuGroups[*].menus[*]). */
export interface NavLeafItem {
  titleTh: string;
  titleEn: string;
  /** Emoji icon, verbatim from the PHP source. */
  icon: string;
  href: string;
  /** Only set for items gated by the Odoo kill-switch (mirrors `if ($isOdooMode) { $supplyMenus[] = ... }`). */
  odooOnly?: boolean;
  /** Which live counter (if any) feeds this item's badge — resolved by the caller, not baked into static data. */
  badgeKey?: 'unreadMessages' | 'pendingOrders';
  servedBy: ServedBy;
}

/** A leaf with nested submenus instead of its own href (e.g. the 'Dashboard' menu). */
export interface NavParentItem {
  titleTh: string;
  titleEn: string;
  icon: string;
  submenus: readonly NavLeafItem[];
}

export type NavMenuItem = NavLeafItem | NavParentItem;

export function isNavParentItem(item: NavMenuItem): item is NavParentItem {
  return 'submenus' in item;
}

/** One of the 5 role-gated accordion groups in $menuGroups. */
export interface NavGroup {
  groupId: string;
  groupTitleTh: string;
  groupTitleEn: string;
  groupIcon: string;
  roles: readonly MenuRole[];
  menus: readonly NavMenuItem[];
}

/** True iff `menuRole` is allowed by `roles` — empty/undefined roles means "everyone", mirroring hasMenuAccess(). */
export function isRoleAllowed(roles: readonly MenuRole[] | undefined, menuRole: MenuRole): boolean {
  if (!roles || roles.length === 0) {
    return true;
  }
  return roles.includes(menuRole);
}
