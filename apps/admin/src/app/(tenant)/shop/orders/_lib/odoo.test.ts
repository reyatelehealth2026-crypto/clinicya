import { isOdooIntegrationEnabled } from './odoo';

describe('isOdooIntegrationEnabled', () => {
  it('is false when the env var is unset', () => {
    expect(isOdooIntegrationEnabled({})).toBe(false);
  });

  it.each(['1', 'true', 'yes', 'on', 'TRUE', 'On'])('is true for %p (case-insensitive)', (value) => {
    expect(isOdooIntegrationEnabled({ ODOO_INTEGRATION_ENABLED: value })).toBe(true);
  });

  it.each(['0', 'false', 'no', 'off', ''])('is false for %p', (value) => {
    expect(isOdooIntegrationEnabled({ ODOO_INTEGRATION_ENABLED: value })).toBe(false);
  });
});
