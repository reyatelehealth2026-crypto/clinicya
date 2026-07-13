import { describe, expect, it } from 'vitest';
import { isRoleAllowed, mapDbRoleToMenuRole } from '../src/nav';
import type { MenuRole } from '../src/nav';

describe('mapDbRoleToMenuRole', () => {
  it('mirrors includes/header.php::getCurrentUserRole() switch exactly', () => {
    expect(mapDbRoleToMenuRole('super_admin')).toBe('owner');
    expect(mapDbRoleToMenuRole('admin')).toBe('admin');
    expect(mapDbRoleToMenuRole('pharmacist')).toBe('pharmacist');
    expect(mapDbRoleToMenuRole('marketing')).toBe('marketing');
    expect(mapDbRoleToMenuRole('tech')).toBe('tech');
    expect(mapDbRoleToMenuRole('staff')).toBe('staff');
  });

  it('defaults unknown/missing dbRole to staff, mirroring the PHP default: branch', () => {
    expect(mapDbRoleToMenuRole(undefined)).toBe('staff');
    expect(mapDbRoleToMenuRole(null)).toBe('staff');
  });
});

describe('isRoleAllowed', () => {
  const owner: MenuRole = 'owner';
  const staff: MenuRole = 'staff';

  it('allows everyone when roles is empty/undefined, mirroring hasMenuAccess()', () => {
    expect(isRoleAllowed(undefined, staff)).toBe(true);
    expect(isRoleAllowed([], staff)).toBe(true);
  });

  it('allows only listed roles otherwise', () => {
    expect(isRoleAllowed(['owner', 'admin'], owner)).toBe(true);
    expect(isRoleAllowed(['owner', 'admin'], staff)).toBe(false);
  });
});
