import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { getConsentPageData, formatConsentLogTimestamp } from './consent-queries';

const RECENT_LOG_ROW = {
  id: 1,
  created_at: '2026-07-10 10:30:00',
  display_name: 'สมชาย ใจดี',
  line_user_id: 'U1234567890abcdef1234567890abcdef',
  consent_type: 'privacy_policy',
  action: 'accept',
  consent_version: '1.0',
  ip_address: '203.0.113.5',
};

const ACCESS_LOG_ROW_WITH_ADMIN = {
  id: 1,
  created_at: '2026-07-11 08:00:00',
  admin_name: 'pharmacist_a',
  action: 'view_profile',
  resource_type: 'user_profile',
  target_user: 'สมชาย ใจดี',
  ip_address: '203.0.113.9',
};

const ACCESS_LOG_ROW_NULL_ADMIN = {
  id: 2,
  created_at: '2026-07-11 09:00:00',
  admin_name: null,
  action: 'export_data',
  resource_type: 'consent_logs',
  target_user: null,
  ip_address: null,
};

function wireHappyPathDb(overrides: { recentLogs?: unknown[]; accessLogs?: unknown[] } = {}) {
  return makeFakeTenantDb((sqlText: string) => {
    if (sqlText.includes('COUNT(DISTINCT user_id)')) return [{ total: 42 }];
    if (sqlText.includes('GROUP BY consent_type')) {
      return [
        { consent_type: 'privacy_policy', count: 30 },
        { consent_type: 'terms_of_service', count: 25 },
        { consent_type: 'health_data', count: 10 },
      ];
    }
    if (sqlText.includes('FROM consent_logs cl')) return overrides.recentLogs ?? [RECENT_LOG_ROW];
    if (sqlText.includes('FROM data_access_logs dal')) return overrides.accessLogs ?? [ACCESS_LOG_ROW_WITH_ADMIN, ACCESS_LOG_ROW_NULL_ADMIN];
    return [];
  });
}

describe('getConsentPageData', () => {
  it('aggregates stats + recentLogs + accessLogs on the happy path (populated rows)', async () => {
    const { db } = wireHappyPathDb();
    const result = await getConsentPageData(db);

    expect(result.error).toBeNull();
    expect(result.stats).toEqual({
      totalConsented: 42,
      byType: { privacy_policy: 30, terms_of_service: 25, health_data: 10 },
    });
    expect(result.recentLogs).toHaveLength(1);
    expect(result.recentLogs[0]).toEqual({
      id: 1,
      createdAt: '2026-07-10 10:30:00',
      displayName: 'สมชาย ใจดี',
      lineUserId: 'U1234567890abcdef1234567890abcdef',
      consentType: 'privacy_policy',
      action: 'accept',
      consentVersion: '1.0',
      ipAddress: '203.0.113.5',
    });
    expect(result.accessLogs).toHaveLength(2);
  });

  it('falls back the LEFT JOIN NULL admin_name to "System" (data_access_logs)', async () => {
    const { db } = wireHappyPathDb();
    const result = await getConsentPageData(db);

    const withAdmin = result.accessLogs.find((l) => l.id === 1);
    const withoutAdmin = result.accessLogs.find((l) => l.id === 2);
    expect(withAdmin?.adminName).toBe('pharmacist_a');
    expect(withoutAdmin?.adminName).toBe('System');
    expect(withoutAdmin?.targetUser).toBeNull();
  });

  it('produces the empty-state shape with 0 rows across every query (no error)', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('COUNT(DISTINCT user_id)')) return [{ total: 0 }];
      return [];
    });
    const result = await getConsentPageData(db);

    expect(result.error).toBeNull();
    expect(result.stats).toEqual({ totalConsented: 0, byType: {} });
    expect(result.recentLogs).toEqual([]);
    expect(result.accessLogs).toEqual([]);
  });

  it('degrades the WHOLE result to the error banner when the data_access_logs (admin_users) query throws — the CONFIRMED, permanent-in-production case', async () => {
    const { db } = makeFakeTenantDb((sqlText: string) => {
      if (sqlText.includes('COUNT(DISTINCT user_id)')) return [{ total: 42 }];
      if (sqlText.includes('GROUP BY consent_type')) return [{ consent_type: 'privacy_policy', count: 30 }];
      if (sqlText.includes('FROM consent_logs cl')) return [RECENT_LOG_ROW];
      if (sqlText.includes('FROM data_access_logs dal')) {
        throw new Error("Table 'reya_tenant_0001.admin_users' doesn't exist");
      }
      return [];
    });

    const result = await getConsentPageData(db);

    expect(result.error).toBe("Table 'reya_tenant_0001.admin_users' doesn't exist");
    // "all-or-nothing" — even though total_consented/by_type/recentLogs all
    // succeeded before the 4th query threw, the returned payload is the
    // fully-empty default, matching the PHP view's `if ($consentError) {
    // <error banner only> }` never reading the partially-populated PHP
    // variables at all.
    expect(result.stats).toEqual({ totalConsented: 0, byType: {} });
    expect(result.recentLogs).toEqual([]);
    expect(result.accessLogs).toEqual([]);
  });

  it('degrades to the error banner when an earlier query (total_consented) throws too', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'reya_tenant_0001.user_consents' doesn't exist");
    });

    const result = await getConsentPageData(db);
    expect(result.error).toBe("Table 'reya_tenant_0001.user_consents' doesn't exist");
  });

  it('stringifies a non-Error throw', async () => {
    const { db } = makeFakeTenantDb(() => {
      // eslint-disable-next-line @typescript-eslint/no-throw-literal
      throw 'boom-string';
    });
    const result = await getConsentPageData(db);
    expect(result.error).toBe('boom-string');
  });
});

describe('formatConsentLogTimestamp', () => {
  it('formats as d/m/Y H:i in Asia/Bangkok (no seconds)', () => {
    // 2026-07-10T03:30:00Z is 2026-07-10 10:30 in Asia/Bangkok (+07:00).
    expect(formatConsentLogTimestamp('2026-07-10T03:30:00Z')).toBe('10/07/2026 10:30');
  });

  it('returns "-" for null', () => {
    expect(formatConsentLogTimestamp(null)).toBe('-');
  });

  it('returns "-" for an unparseable date string', () => {
    expect(formatConsentLogTimestamp('not-a-date')).toBe('-');
  });
});
