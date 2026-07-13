import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import {
  getExecutiveOverview,
  getRecentActivities,
  getDealsList,
  getRevenueAnalytics,
  getPipelineData,
  getTickets,
  getTicketStats,
  getCustomers,
  getCampaigns,
  getSegments,
  getCustomer360,
} from './queries';

const NO_SUCH_TABLE = (name: string) => new Error(`Table 'tenant.${name}' doesn't exist`);

describe('getExecutiveOverview', () => {
  it('returns real users/drip_campaigns counts + placeholder metrics, with empty crm_deals/crm_tickets-backed fields when those tables are missing', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM crm_deals') || sqlText.includes('FROM crm_tickets')) {
        throw NO_SUCH_TABLE('crm_deals');
      }
      if (sqlText.includes('FROM users')) return [{ count: 42 }];
      if (sqlText.includes('FROM drip_campaigns')) return [{ count: 3 }];
      return [];
    });

    const overview = await getExecutiveOverview(db, null);

    expect(overview.metrics.totalCustomers).toEqual({ value: 42, change: 5.2 });
    expect(overview.metrics.activeDeals).toEqual({ value: 0, pipelineValue: 0, change: 12.5 });
    expect(overview.metrics.openTickets).toEqual({ value: 0, urgent: 3 });
    expect(overview.metrics.avgDealSize).toEqual({ value: 0, change: 0 });
    expect(overview.metrics.activeCampaigns).toEqual({ value: 3, change: 0 });
    expect(overview.metrics.monthlyRevenue).toEqual({ value: 125000, change: 8.3 });
    expect(overview.metrics.conversionRate).toEqual({ value: 24.5, change: 0 });
    expect(overview.metrics.satisfaction).toEqual({ value: 4.5, max: 5, change: 0.2 });
    expect(overview.alerts).toEqual([]);
    expect(overview.activities).toEqual([]);
    expect(overview.charts).toEqual({ revenueTrend: [100, 120, 115, 140, 135, 160, 155], pipelineDistribution: [10, 8, 5, 3, 12, 7] });

    // total_customers uses the double-bind null-safe pattern
    const totalCustomersQuery = queries.find((q) => q.sql.includes('FROM users') && q.sql.includes('is_blocked = 0'));
    expect(totalCustomersQuery?.params).toEqual([null, null]);
  });

  it('populates active_deals/open_tickets/avg_deal_size/alerts/activities when crm_deals/crm_tickets exist', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('deal_count')) return [{ deal_count: 4, pipeline_value: 90000 }];
      if (sqlText.includes('sla_deadline < NOW()')) return [{ count: 1 }]; // alert breach query — check BEFORE the generic status-IN branch below
      if (sqlText.includes('FROM crm_tickets WHERE status IN')) return [{ count: 2 }];
      if (sqlText.includes('AVG(value)')) return [{ avg: 12345.678 }];
      if (sqlText.includes('FROM users')) return [{ count: 10 }];
      if (sqlText.includes('FROM drip_campaigns')) return [{ count: 1 }];
      if (sqlText.includes("stage = 'lead'")) return [{ count: 2 }];
      if (sqlText.includes('crm_deals d LEFT JOIN')) return [];
      if (sqlText.includes('crm_tickets t LEFT JOIN')) return [];
      return [];
    });

    const overview = await getExecutiveOverview(db, 5);
    expect(overview.metrics.activeDeals).toEqual({ value: 4, pipelineValue: 90000, change: 12.5 });
    expect(overview.metrics.openTickets).toEqual({ value: 2, urgent: 3 });
    expect(overview.metrics.avgDealSize).toEqual({ value: 12345.68, change: 0 });
    expect(overview.alerts).toEqual([
      { type: 'danger', message: '1 ticket(s) have breached SLA', link: '#tickets' },
      { type: 'info', message: '2 new lead(s) today', link: '#pipeline' },
    ]);
  });
});

describe('getRecentActivities', () => {
  it('merges + sorts deals and tickets by created_at desc', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes("'deal' as type")) {
        return [{ type: 'deal', created_at: '2026-01-01T00:00:00Z', customer_name: 'A', title: 'Deal A', value: 100, stage: 'lead' }];
      }
      if (sqlText.includes("'ticket' as type")) {
        return [{ type: 'ticket', created_at: '2026-02-01T00:00:00Z', customer_name: 'B', title: 'Ticket B', stage: 'open' }];
      }
      return [];
    });
    const activities = await getRecentActivities(db, 10);
    expect(activities).toHaveLength(2);
    expect(activities[0]?.title).toBe('Ticket B'); // newer first
    expect(activities[1]?.title).toBe('Deal A');
  });

  it('returns [] when crm_deals/crm_tickets are both missing', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw NO_SUCH_TABLE('crm_deals');
    });
    expect(await getRecentActivities(db, 10)).toEqual([]);
  });

  it('returns whichever side succeeds when only one table is missing', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes("'deal' as type")) throw NO_SUCH_TABLE('crm_deals');
      return [{ type: 'ticket', created_at: '2026-02-01T00:00:00Z', customer_name: 'B', title: 'Ticket B', stage: 'open' }];
    });
    const activities = await getRecentActivities(db, 10);
    expect(activities).toEqual([{ type: 'ticket', created_at: '2026-02-01T00:00:00Z', customer_name: 'B', title: 'Ticket B', stage: 'open', value: null }]);
  });
});

describe('getDealsList', () => {
  it('is ALWAYS empty — an unconditional stub, mirrors CRMDashboardService::getDealsList()', () => {
    expect(getDealsList()).toEqual({ deals: [], total: 0 });
  });
});

describe('getRevenueAnalytics', () => {
  it('queries odoo_webhooks_log for the daily series and returns the hardcoded summary placeholder regardless of period', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ date: '2026-07-01', order_count: 3, revenue: 4500 }]);
    const result = await getRevenueAnalytics(db, '30d');
    expect(result).toEqual({
      period: '30d',
      daily: [{ date: '2026-07-01', order_count: 3, revenue: 4500 }],
      summary: { total: 125000, avg: 17857 },
    });
    expect(queries[0]?.sql).toContain('odoo_webhooks_log');
    expect(queries[0]?.params).toContain(30);
  });

  it('falls back to an empty daily series (summary placeholder untouched) when odoo_webhooks_log.created_at does not exist on the committed schema', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Unknown column 'created_at' in 'field list'");
    });
    const result = await getRevenueAnalytics(db, '30d');
    expect(result).toEqual({
      period: '30d',
      daily: [],
      summary: { total: 125000, avg: 17857 },
    });
  });
});

describe('getPipelineData', () => {
  it('returns 6 stages with real deals + a computed stage value, plus the win_rate placeholder', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes("d.stage = ?") || sqlText.includes('WHERE d.stage')) {
        return [{ id: 1, value: 1000, stage: 'lead', title: 'D1' }];
      }
      return [];
    });
    const result = await getPipelineData(db);
    expect(result.stages).toHaveLength(6);
    expect(result.stages.map((s) => s.id)).toEqual(['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost']);
    for (const stage of result.stages) {
      expect(stage.count).toBe(1);
      expect(stage.value).toBe(1000);
    }
    expect(result.totalDeals).toBe(6);
    expect(result.totalValue).toBe(6000);
    expect(result.winRate).toBe(35.0);
  });

  it('falls back to all-empty stages (but keeps win_rate) when crm_deals is missing', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw NO_SUCH_TABLE('crm_deals');
    });
    const result = await getPipelineData(db);
    expect(result.stages).toHaveLength(6);
    for (const stage of result.stages) {
      expect(stage.count).toBe(0);
      expect(stage.value).toBe(0);
      expect(stage.deals).toEqual([]);
    }
    expect(result.totalDeals).toBe(0);
    expect(result.totalValue).toBe(0);
    expect(result.winRate).toBe(35.0);
  });
});

describe('getTickets', () => {
  it('applies status/priority/assignedTo filters and returns tickets + total', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(*) as count FROM crm_tickets')) return [{ count: 1 }];
      return [{ id: 1, subject: 'Help', status: 'open', priority: 'high' }];
    });
    const result = await getTickets(db, { status: 'open', priority: 'high', limit: 10, offset: 0 });
    expect(result.tickets).toHaveLength(1);
    expect(result.total).toBe(1);

    const listQuery = queries.find((q) => q.sql.includes('LEFT JOIN users'));
    expect(listQuery?.sql).toContain('t.status = ?');
    expect(listQuery?.sql).toContain('t.priority = ?');
    expect(listQuery?.sql).not.toContain('t.assigned_to = ?');
  });

  it('falls back to an empty result when crm_tickets is missing', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw NO_SUCH_TABLE('crm_tickets');
    });
    const result = await getTickets(db, {});
    expect(result).toEqual({ tickets: [], total: 0, limit: 50, offset: 0 });
  });
});

describe('getTicketStats', () => {
  it('returns by_status/by_priority key-pair maps + sla counts', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('GROUP BY status')) return [{ status: 'open', count: 3 }, { status: 'closed', count: 1 }];
      if (sqlText.includes('GROUP BY priority')) return [{ priority: 'high', count: 2 }];
      if (sqlText.includes('approaching_sla')) return [{ approaching_sla: 1 }];
      if (sqlText.includes('breached_sla')) return [{ breached_sla: 2 }];
      return [];
    });
    const stats = await getTicketStats(db);
    expect(stats).toEqual({ byStatus: { open: 3, closed: 1 }, byPriority: { high: 2 }, approachingSla: 1, breachedSla: 2 });
  });

  it('falls back to empty stats when crm_tickets is missing', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw NO_SUCH_TABLE('crm_tickets');
    });
    expect(await getTicketStats(db)).toEqual({ byStatus: {}, byPriority: {}, approachingSla: 0, breachedSla: 0 });
  });
});

describe('getCustomers', () => {
  it('uses the double-bind null-safe line_account_id pattern and returns deals_count/tickets_count from the real query', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(DISTINCT u.id)')) return [{ count: 1 }];
      return [{ id: 1, display_name: 'A', deals_count: 2, tickets_count: 1, tags: 'VIP' }];
    });
    const result = await getCustomers(db, null, {});
    expect(result.customers[0]).toMatchObject({ deals_count: 2, tickets_count: 1 });
    expect(result.total).toBe(1);

    const rowsQuery = queries.find((q) => q.sql.includes('GROUP BY u.id'));
    expect(rowsQuery?.sql).toContain('(u.line_account_id = ? OR ? IS NULL)');
    expect(rowsQuery?.params).toEqual(expect.arrayContaining([null, null]));
  });

  it('falls back to a joinless query (deals_count/tickets_count = 0) when crm_deals/crm_tickets are missing, without losing real user/tag data', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('crm_deals d ON')) throw NO_SUCH_TABLE('crm_deals');
      if (sqlText.includes('SELECT COUNT(DISTINCT u.id)')) return [{ count: 1 }];
      return [{ id: 1, display_name: 'A', tags: 'VIP' }];
    });
    const result = await getCustomers(db, 7, {});
    expect(result.customers[0]).toMatchObject({ id: 1, display_name: 'A', deals_count: 0, tickets_count: 0 });
    expect(result.total).toBe(1);

    const fallbackQuery = queries.find((q) => q.sql.includes('GROUP BY u.id') && !q.sql.includes('crm_deals'));
    expect(fallbackQuery).toBeDefined();
  });

  it('adds the search LIKE clause only when search is non-empty', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ count: 0 }]);
    await getCustomers(db, null, { search: 'abc' });
    const rowsQuery = queries.find((q) => q.sql.includes('GROUP BY u.id'));
    expect(rowsQuery?.sql).toContain('u.display_name LIKE ?');
    expect(rowsQuery?.params).toEqual(expect.arrayContaining(['%abc%', '%abc%']));
  });
});

describe('getCampaigns', () => {
  it('uses the single-bind line_account_id pattern (line_account_id = ? OR line_account_id IS NULL)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, name: 'Welcome', is_active: 1, step_count: 3, active_users: 5, completed_users: 2 }]);
    const result = await getCampaigns(db, null, {});
    expect(result).toEqual([{ id: 1, name: 'Welcome', is_active: 1, step_count: 3, active_users: 5, completed_users: 2 }]);

    expect(queries[0]?.sql).toContain('c.line_account_id = ? OR c.line_account_id IS NULL');
    expect(queries[0]?.params).toEqual([null, 20]); // line_account_id bound ONCE (not twice) + the LIMIT param
  });

  it('filters by is_active when status is provided', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getCampaigns(db, 3, { status: 'active' });
    expect(queries[0]?.sql).toContain('c.is_active = ?');
    expect(queries[0]?.params).toEqual([3, 1, 20]);
  });
});

describe('getSegments', () => {
  it('computes new/inactive from real tables, 0 for vip (unhandled default case), and 0 for has_deals/has_tickets when those tables are missing', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('DATE_SUB(NOW(), INTERVAL 30 DAY)') && sqlText.includes('user_behaviors')) return [{ count: 4 }];
      if (sqlText.includes('created_at >= DATE_SUB')) return [{ count: 7 }];
      if (sqlText.includes('crm_deals') || sqlText.includes('crm_tickets')) throw NO_SUCH_TABLE('crm_deals');
      return [];
    });
    const segments = await getSegments(db, null);
    expect(segments).toEqual([
      { id: 'vip', name: 'VIP Customers', description: 'High value customers', count: 0 },
      { id: 'new', name: 'New Customers', description: 'Joined in last 30 days', count: 7 },
      { id: 'inactive', name: 'Inactive Users', description: 'No activity in 30 days', count: 4 },
      { id: 'has_deals', name: 'Active Prospects', description: 'Have open deals', count: 0 },
      { id: 'has_tickets', name: 'Support Active', description: 'Have open tickets', count: 0 },
    ]);
  });

  it('reports real has_deals/has_tickets counts when those tables exist', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('crm_deals')) return [{ count: 9 }];
      if (sqlText.includes('crm_tickets')) return [{ count: 3 }];
      return [{ count: 0 }];
    });
    const segments = await getSegments(db, null);
    expect(segments.find((s) => s.id === 'has_deals')?.count).toBe(9);
    expect(segments.find((s) => s.id === 'has_tickets')?.count).toBe(3);
  });
});

describe('getCustomer360', () => {
  it('returns null when the customer does not exist', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getCustomer360(db, 999)).toBeNull();
  });

  it('returns real user + tags with hardcoded placeholder stats (never touching crm_deals/crm_tickets)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users')) return [{ id: 1, line_user_id: 'U1', display_name: 'Somchai', picture_url: null, phone: '0812345678', email: null }];
      if (sqlText.includes('FROM user_tags')) return [{ id: 1, name: 'VIP', color: '#fff' }];
      return [];
    });
    const result = await getCustomer360(db, 1);
    expect(result).toEqual({
      id: 1,
      line_user_id: 'U1',
      display_name: 'Somchai',
      picture_url: null,
      phone: '0812345678',
      email: null,
      tags: [{ id: 1, name: 'VIP', color: '#fff' }],
      orders_count: 5,
      total_spent: 25000,
      deals_count: 2,
      tickets_count: 1,
    });
    expect(queries.every((q) => !q.sql.includes('crm_deals') && !q.sql.includes('crm_tickets'))).toBe(true);
  });
});
