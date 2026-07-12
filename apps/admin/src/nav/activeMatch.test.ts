import { findActivePrimaryNavKey } from './activeMatch';
import { ALL_PRIMARY_NAV_ITEMS } from './manifest';

describe('findActivePrimaryNavKey', () => {
  it.each([
    ['/dashboard?tab=executive', 'overview'],
    ['/odoo-dashboard', 'overview'],
    ['/analytics', 'overview'], // '/analytics' is only in overview's match list (reports uses '/triage-analytics', not '/analytics')
    ['/inbox-v2', 'inbox'],
    ['/messages', 'inbox'],
    ['/shop/orders', 'orders'],
    ['/pos', 'orders'],
    ['/inventory', 'inventory'],
    ['/procurement', 'inventory'],
    ['/pharmacy', 'pharmacy'],
    ['/dispense-tracking', 'pharmacy'],
    ['/appointments-admin', 'pharmacy'],
    ['/users', 'patients'],
    ['/membership', 'patients'],
    ['/broadcast', 'marketing'],
    ['/rich-menu', 'marketing'],
    ['/activity-logs', 'reports'],
    ['/settings', 'settings'],
    ['/admin-users', 'settings'],
    ['/some/totally/unrelated/path', null],
  ])('currentPath=%s -> key=%s', (currentPath, expectedKey) => {
    expect(findActivePrimaryNavKey(currentPath, ALL_PRIMARY_NAV_ITEMS)).toBe(expectedKey);
  });

  it("does a SUBSTRING check (strpos semantics), not a strict 'starts with' check", () => {
    const items = [{ key: 'orders', href: '/shop/orders', match: ['/shop/orders'] }];
    expect(findActivePrimaryNavKey('/admin/foo/shop/orders/123', items)).toBe('orders');
  });

  it('on a tie in matched length, the FIRST-inserted item wins (PHP uses strict >, never >=)', () => {
    const items = [
      { key: 'first', href: '/a', match: ['/same-len-abc'] },
      { key: 'second', href: '/b', match: ['/same-len-xyz'] },
    ];
    // A path containing BOTH equal-length prefixes verbatim -> both match at the same length.
    const path = '/same-len-abc/same-len-xyz';
    expect(findActivePrimaryNavKey(path, items)).toBe('first');
  });

  it('picks the LONGER prefix even when a shorter one also matches and is listed first', () => {
    const items = [
      { key: 'short', href: '/a', match: ['/dashboard'] },
      { key: 'long', href: '/b', match: ['/dashboard-extended'] },
    ];
    expect(findActivePrimaryNavKey('/dashboard-extended', items)).toBe('long');
  });

  it('falls back to href when match is omitted', () => {
    const items = [{ key: 'k', href: '/only-href' }];
    expect(findActivePrimaryNavKey('/only-href/sub', items)).toBe('k');
    expect(findActivePrimaryNavKey('/unrelated', items)).toBeNull();
  });

  it('never matches an empty-string prefix', () => {
    const items = [{ key: 'k', href: '', match: [''] }];
    expect(findActivePrimaryNavKey('/anything', items)).toBeNull();
  });
});
