import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getEmailSettings, DEFAULT_EMAIL_SETTINGS } from './email-queries';

describe('getEmailSettings', () => {
  it('queries WHERE id = 1 with no tenant/line_account_id filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getEmailSettings(db);
    expect(queries[0]?.sql).toContain('FROM email_settings WHERE id = 1');
  });

  it('returns defaults when no row exists yet', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getEmailSettings(db);
    expect(result).toEqual(DEFAULT_EMAIL_SETTINGS);
  });

  it('returns defaults when the query throws', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    const result = await getEmailSettings(db);
    expect(result).toEqual(DEFAULT_EMAIL_SETTINGS);
  });

  it('maps a real row to camelCase fields with per-field ?? fallbacks', async () => {
    const { db } = makeFakeTenantDb(() => [
      {
        id: 1,
        line_account_id: 1,
        smtp_host: 'smtp.gmail.com',
        smtp_port: 465,
        smtp_user: 'me@gmail.com',
        smtp_pass: 'secret',
        smtp_secure: 'ssl',
        from_email: 'noreply@example.com',
        from_name: 'Reya Pharmacy',
      },
    ]);
    const result = await getEmailSettings(db);
    expect(result).toEqual({
      smtpHost: 'smtp.gmail.com',
      smtpPort: 465,
      smtpUser: 'me@gmail.com',
      smtpPass: 'secret',
      smtpSecure: 'ssl',
      fromEmail: 'noreply@example.com',
      fromName: 'Reya Pharmacy',
    });
  });

  it('falls back per-field when a row exists but some columns are NULL', async () => {
    const { db } = makeFakeTenantDb(() => [
      { id: 1, line_account_id: 1, smtp_host: null, smtp_port: null, smtp_user: null, smtp_pass: null, smtp_secure: null, from_email: null, from_name: null },
    ]);
    const result = await getEmailSettings(db);
    expect(result).toEqual(DEFAULT_EMAIL_SETTINGS);
  });
});
