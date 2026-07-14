import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getWelcomeSettings, DEFAULT_WELCOME_SETTINGS } from './welcome-queries';

describe('getWelcomeSettings', () => {
  it('returns the hardcoded default greeting when the query fails (welcome_settings missing on the committed schema)', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.welcome_settings' doesn't exist");
    });
    const result = await getWelcomeSettings(db, 7);
    expect(result).toEqual(DEFAULT_WELCOME_SETTINGS);
  });

  it('returns the hardcoded default greeting when no row matches (query succeeds, empty result)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getWelcomeSettings(db, 7);
    expect(result).toEqual(DEFAULT_WELCOME_SETTINGS);
  });

  it('binds currentBotId twice (double-bind OR-null-safe pattern)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getWelcomeSettings(db, 7);
    expect(queries[0]?.sql).toContain('line_account_id = ?');
    expect(queries[0]?.sql).toContain('line_account_id IS NULL AND ? IS NULL');
    expect(queries[0]?.params).toEqual([7, 7]);
  });

  it('maps a real row to camelCase fields with per-field ?? fallbacks, not the hardcoded greeting default', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, line_account_id: 7, is_enabled: 1, message_type: 'flex', text_content: null, flex_content: '{"type":"bubble"}' },
    ]);
    const result = await getWelcomeSettings(db, 7);
    expect(result).toEqual({
      isEnabled: true,
      messageType: 'flex',
      textContent: '',
      flexContent: '{"type":"bubble"}',
    });
  });

  it('treats is_enabled=0 as false and an unrecognized message_type as "text"', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, line_account_id: null, is_enabled: 0, message_type: 'something-else', text_content: 'hi', flex_content: null },
    ]);
    const result = await getWelcomeSettings(db, null);
    expect(result.isEnabled).toBe(false);
    expect(result.messageType).toBe('text');
    expect(result.textContent).toBe('hi');
    expect(result.flexContent).toBe('');
  });

  it('works with currentBotId=null (both binds null)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getWelcomeSettings(db, null);
    expect(queries[0]?.params).toEqual([null, null]);
  });
});
