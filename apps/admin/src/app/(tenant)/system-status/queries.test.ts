import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import { getSystemStatus, computeOverallStatus, type StatusCheck } from './queries';

describe('getSystemStatus', () => {
  it('returns all 19 checks in system-status.php order: 11 portable + 8 not_ported placeholders', async () => {
    const { db } = makeFakeTenantDb(() => [{ total: 0, unread: 0 }]);
    const result = await getSystemStatus(db, 1);

    expect(result.checks.map((c) => c.key)).toEqual([
      'database',
      'vibe_selling',
      'inbox_service',
      'v2_DrugPricingEngineService',
      'v2_CustomerHealthEngineService',
      'v2_PharmacyImageAnalyzerService',
      'v2_PharmacyGhostDraftService',
      'table_users',
      'table_messages',
      'table_line_accounts',
      'table_user_tags',
      'table_admin_users',
      'v2_table_customer_health_profiles',
      'v2_table_drug_pricing_rules',
      'v2_table_ghost_draft_learning',
      'line_api',
      'ai_module',
      'message_stats',
      'user_stats',
    ]);

    const notPorted = result.checks.filter((c) => c.status === 'not_ported');
    expect(notPorted).toHaveLength(8);
  });

  it('reports healthy + ok on every portable check when every table/query succeeds (seeded, unmodified tenant template)', async () => {
    const { db } = makeFakeTenantDb(() => [{ total: 5, unread: 2 }]);
    const result = await getSystemStatus(db, 7);

    // admin_users is NOT in database/migration_2026-05-25_tenant_template.sql (platform-level
    // table per that file's own header comment) — this fake driver has no failure injection so
    // every SELECT 1 FROM ... succeeds; the "admin_users genuinely 500s on the real template" case
    // is covered by the dedicated test below.
    for (const key of ['database', 'table_users', 'table_messages', 'table_line_accounts', 'table_user_tags', 'table_admin_users']) {
      const check = result.checks.find((c) => c.key === key);
      expect(check?.status).toBe('ok');
    }
    expect(result.overallStatus).toBe('healthy');
  });

  it('does not 500 and reports critical when a required table is missing (mirrors the unmodified tenant template lacking admin_users)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('admin_users')) {
        throw new Error("Table 'tenant.admin_users' doesn't exist");
      }
      return [{ total: 0, unread: 0 }];
    });
    const result = await getSystemStatus(db, 1);

    const adminUsersCheck = result.checks.find((c) => c.key === 'table_admin_users');
    expect(adminUsersCheck?.status).toBe('error');
    expect(adminUsersCheck?.message).toBe('ตารางผู้ดูแลระบบ (admin_users) ไม่พบ');
    expect(result.overallStatus).toBe('critical');
  });

  it('reports degraded (not critical) when only a v2 table is missing', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('ghost_draft_learning')) {
        throw new Error("Table 'tenant.ghost_draft_learning' doesn't exist");
      }
      return [{ total: 0, unread: 0 }];
    });
    const result = await getSystemStatus(db, 1);

    const check = result.checks.find((c) => c.key === 'v2_table_ghost_draft_learning');
    expect(check?.status).toBe('warning');
    expect(check?.message).toBe('Ghost Draft Learning (ghost_draft_learning) ยังไม่ได้ migrate');
    expect(result.overallStatus).toBe('degraded');
  });

  it('reports critical (database down) even when a v2 table is also missing — critical wins over degraded', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection refused');
    });
    const result = await getSystemStatus(db, 1);
    expect(result.overallStatus).toBe('critical');
  });

  it('message_stats/user_stats failures report warning but do NOT degrade overallStatus (mirrors PHP catch blocks not touching $overallStatus)', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM messages WHERE line_account_id') || sqlText.includes('FROM users WHERE line_account_id')) {
        throw new Error('boom');
      }
      return [];
    });
    const result = await getSystemStatus(db, 1);

    expect(result.checks.find((c) => c.key === 'message_stats')?.status).toBe('warning');
    expect(result.checks.find((c) => c.key === 'user_stats')?.status).toBe('warning');
    expect(result.overallStatus).toBe('healthy');
  });

  it('formats message_stats/user_stats success messages with real counts, scoped by currentBotId', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('total FROM messages')) return [{ total: 42 }];
      if (sqlText.includes('unread FROM messages')) return [{ unread: 9 }];
      if (sqlText.includes('total FROM users')) return [{ total: 100 }];
      return [];
    });
    const result = await getSystemStatus(db, 55);

    expect(result.checks.find((c) => c.key === 'message_stats')?.message).toBe('ข้อความทั้งหมด: 42, ยังไม่อ่าน: 9');
    expect(result.checks.find((c) => c.key === 'user_stats')?.message).toBe('ผู้ใช้ทั้งหมด: 100');
    // every message_stats/user_stats query bound line_account_id = currentBotId
    const scopedQueries = queries.filter((q) => q.sql.includes('line_account_id ='));
    expect(scopedQueries.length).toBeGreaterThan(0);
    for (const q of scopedQueries) {
      expect(q.params).toContain(55);
    }
  });

  it('exposes the currentBotId it was called with', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getSystemStatus(db, 3);
    expect(result.currentBotId).toBe(3);
  });
});

describe('computeOverallStatus', () => {
  function check(key: string, status: StatusCheck['status']): StatusCheck {
    return { key, status, message: '' };
  }

  it('is healthy when every portable check is ok', () => {
    expect(computeOverallStatus([check('database', 'ok'), check('table_users', 'ok')])).toBe('healthy');
  });

  it('ignores not_ported and warning-on-non-critical-key checks', () => {
    expect(computeOverallStatus([check('database', 'ok'), check('vibe_selling', 'not_ported'), check('line_api', 'not_ported')])).toBe('healthy');
  });

  it('is critical when database errors, regardless of anything else', () => {
    expect(computeOverallStatus([check('database', 'error'), check('v2_table_drug_pricing_rules', 'ok')])).toBe('critical');
  });

  it('is degraded when a v2_table warns and nothing critical failed', () => {
    expect(computeOverallStatus([check('database', 'ok'), check('v2_table_ghost_draft_learning', 'warning')])).toBe('degraded');
  });
});
