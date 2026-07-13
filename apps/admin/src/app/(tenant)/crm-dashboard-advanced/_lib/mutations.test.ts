import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { moveDeal, createDeal, updateDeal, deleteDeal, createTicket, updateTicket, addTicketInteraction } from './mutations';

describe('moveDeal', () => {
  it('rejects an invalid stage without touching the DB', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await moveDeal(db, 1, 'bogus_stage');
    expect(result).toEqual({ success: false, error: 'Invalid stage' });
    expect(queries).toHaveLength(0);
  });

  it('sets closed_at when moving to closed_won/closed_lost', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    const result = await moveDeal(db, 1, 'closed_won');
    expect(result).toEqual({ success: true, message: 'Deal moved successfully' });
    expect(queries[0]?.sql).toContain('closed_at = NOW()');
  });

  it('does NOT set closed_at for a non-terminal stage', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    await moveDeal(db, 1, 'qualified');
    expect(queries[0]?.sql).not.toContain('closed_at');
  });

  it('returns {success:false, error} on a missing crm_deals table, mirroring PHP\'s existing try/catch', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.crm_deals' doesn't exist");
    });
    const result = await moveDeal(db, 1, 'lead');
    expect(result).toEqual({ success: false, error: "Table 'tenant.crm_deals' doesn't exist" });
  });
});

describe('createDeal', () => {
  it('rejects when a required field is empty (PHP empty() semantics, e.g. value=0 counts as missing)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await createDeal(db, { customer_id: 1, title: 'Deal', value: 0 });
    expect(result).toEqual({ success: false, error: 'Missing required field: value' });
    expect(queries).toHaveLength(0);
  });

  it('inserts with defaults for optional fields and returns the new deal_id', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 42, affectedRows: 1 }));
    const result = await createDeal(db, { customer_id: 1, title: 'Deal', value: 500 });
    expect(result).toEqual({ success: true, deal_id: 42 });
    expect(queries[0]?.params).toEqual([1, 'Deal', '', 500, 'lead', 20, null, null, 'manual']);
  });

  it('returns {success:false, error} when crm_deals does not exist', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.crm_deals' doesn't exist");
    });
    const result = await createDeal(db, { customer_id: 1, title: 'Deal', value: 500 });
    expect(result.success).toBe(false);
    expect(result.error).toContain("doesn't exist");
  });
});

describe('updateDeal', () => {
  it('only updates fields that are isset() (0/"" count as set, unlike empty())', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    const result = await updateDeal(db, 5, { value: 0, probability: undefined });
    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('value = ?');
    expect(queries[0]?.sql).not.toContain('probability');
    expect(queries[0]?.params).toEqual([0, 5]);
  });

  it('returns an error with no query when no fields are provided', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await updateDeal(db, 5, {});
    expect(result).toEqual({ success: false, error: 'No fields to update' });
    expect(queries).toHaveLength(0);
  });
});

describe('deleteDeal', () => {
  it('deletes by id', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    const result = await deleteDeal(db, 9);
    expect(result).toEqual({ success: true });
    expect(queries[0]?.sql).toContain('DELETE FROM crm_deals WHERE id = ?');
    expect(queries[0]?.params).toEqual([9]);
  });
});

describe('createTicket', () => {
  it('rejects when a required field is missing', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await createTicket(db, { customer_id: 1 });
    expect(result).toEqual({ success: false, error: 'Missing required field: subject' });
  });

  it('ALWAYS uses the epoch sla_deadline regardless of priority (mirrors the single-quote strtotime() bug)', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 7, affectedRows: 1 }));
    await createTicket(db, { customer_id: 1, subject: 'Help', priority: 'urgent' });
    expect(queries[0]?.params).toContain('1970-01-01 07:00:00');
  });

  it('defaults priority to medium and status to open, returns the new ticket_id', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 11, affectedRows: 1 }));
    const result = await createTicket(db, { customer_id: 1, subject: 'Help' });
    expect(result).toEqual({ success: true, ticket_id: 11 });
    expect(queries[0]?.params).toEqual([1, 'Help', '', 'open', 'medium', 'general', null, '1970-01-01 07:00:00']);
  });

  it('returns {success:false, error} when crm_tickets does not exist', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.crm_tickets' doesn't exist");
    });
    const result = await createTicket(db, { customer_id: 1, subject: 'Help' });
    expect(result.success).toBe(false);
  });
});

describe('updateTicket', () => {
  it('sets resolved_at when status changes to resolved', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    await updateTicket(db, 3, { status: 'resolved' });
    expect(queries[0]?.sql).toContain('resolved_at = NOW()');
  });

  it('does not set resolved_at for a non-terminal status', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ affectedRows: 1 }));
    await updateTicket(db, 3, { status: 'open' });
    expect(queries[0]?.sql).not.toContain('resolved_at');
  });

  it('returns an error with no query when no fields are provided', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await updateTicket(db, 3, {});
    expect(result).toEqual({ success: false, error: 'No fields to update' });
    expect(queries).toHaveLength(0);
  });
});

describe('addTicketInteraction', () => {
  it('rejects when a required field is missing', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await addTicketInteraction(db, { ticket_id: 1, interaction_type: 'note' });
    expect(result).toEqual({ success: false, error: 'Missing required field: content' });
  });

  it('inserts and returns the new interaction_id', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 99, affectedRows: 1 }));
    const result = await addTicketInteraction(db, { ticket_id: 1, interaction_type: 'note', content: 'hello' });
    expect(result).toEqual({ success: true, interaction_id: 99 });
    expect(queries[0]?.params).toEqual([1, 'note', 'hello', null]);
  });

  it('returns {success:false, error} when crm_ticket_interactions does not exist', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'tenant.crm_ticket_interactions' doesn't exist");
    });
    const result = await addTicketInteraction(db, { ticket_id: 1, interaction_type: 'note', content: 'hello' });
    expect(result.success).toBe(false);
  });
});
