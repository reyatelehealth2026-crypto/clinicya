/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { GET, POST } from './route';

function req(search = ''): NextRequest {
  const url = `https://tenant.re-ya.com/api/inbox/actions/refill-reminders${search}`;
  return { nextUrl: new URL(url) } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 7,
    tenantId: 1,
    currentBotId: 3,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const DAY_MS = 86_400_000;

// Frozen reference instant for all day-math tests below (jest fake timers,
// not the real system clock) — `getRefillReminders()` computes `new Date()`
// ("now") internally, so without pinning it, a fixture built from
// `Date.now() - n * DAY_MS` at test-setup time and the implementation's own
// `new Date()` read a few milliseconds later would disagree right at a
// day-boundary (e.g. an intended "exactly 1 day away" fixture landing at
// 0.999999... days once real wall-clock time has moved on), making these
// assertions flaky. Freezing `now` makes every day-math test exact and
// deterministic.
const FIXED_NOW = new Date('2026-08-13T12:00:00.000Z').getTime();

function daysAgo(n: number): Date {
  return new Date(FIXED_NOW - n * DAY_MS);
}

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers({ now: FIXED_NOW });
});

afterEach(() => {
  jest.useRealTimers();
});

describe('GET /api/inbox/actions/refill-reminders', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await GET(req('?user_id=42'));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "Invalid user ID" when user_id is missing', async () => {
    const queries = wireFakeDb();

    const res = await GET(req());

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
    expect(queries).toHaveLength(0);
  });

  it('400 "Invalid user ID" when user_id <= 0', async () => {
    wireFakeDb();

    const res = await GET(req('?user_id=-1'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('empty purchase history: {success: true, data: {reminders: [], userId, totalDue: 0}} — no "count" key at the top level', async () => {
    wireFakeDb(() => []);

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { reminders: [], userId: 42, totalDue: 0 } });
    expect(body).not.toHaveProperty('count');
  });

  /**
   * The day-math property check called out by this batch's acceptance
   * criteria: lastPurchaseDate = now - 23 days, duration = 30 (default
   * category, no category_name match) => estimatedRefillDate = lastPurchase
   * + 30 days = now + 7 days => daysUntilRefill must be EXACTLY 7.
   *
   * Per the PHP thresholds actually in
   * `classes/DrugRecommendEngineService.php::getRefillReminders()` (lines
   * ~458-467): `$status = 'due'; $urgency = 'normal'; if ($daysUntilRefill
   * < 0) {...} elseif ($daysUntilRefill <= 3) { $urgency = 'medium'; }` —
   * with daysUntilRefill=7 (not <0, not <=3), urgency stays 'normal', NOT
   * 'medium'. (This batch's own brief text asserted "urgency 'medium'" for
   * this exact fixture in the same breath as "verify against the exact PHP
   * thresholds: ... urgency medium if <=3, else normal" — those two
   * clauses are mutually contradictory for daysUntilRefill=7, since 7 > 3.
   * This test follows the literal PHP source, which is unambiguous, and
   * asserts the CORRECT value: urgency 'normal'.)
   */
  it('day-math: lastPurchaseDate = now-23d, default duration=30 => daysUntilRefill=7 exactly, status "due", urgency "normal" (not "medium" — see comment above), exact Thai message', async () => {
    const purchase = {
      product_id: 10,
      name: 'วิตามินซี',
      sku: 'VITC-10',
      price: '150.00',
      stock: 40,
      image_url: null,
      last_purchase_date: daysAgo(23),
      total_quantity: 1,
      category_name: null, // no category match -> falls through to 'default' => 30 days
    };
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [purchase] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.data.reminders).toHaveLength(1);
    const reminder = body.data.reminders[0];
    expect(reminder.daysUntilRefill).toBe(7);
    expect(reminder.status).toBe('due');
    expect(reminder.urgency).toBe('normal');
    expect(reminder.usageDuration).toBe(30);
    expect(reminder.message).toBe('ยา วิตามินซี จะถึงกำหนดเติมใน 7 วัน');
  });

  it('overdue: daysUntilRefill < 0 => status "overdue", urgency "high", exact overdue Thai message', async () => {
    // duration 7 (antibiotic), last purchase 10 days ago => refill was due 3 days ago.
    const purchase = {
      product_id: 11,
      name: 'Amoxicillin',
      sku: 'AMX-11',
      price: '80.00',
      stock: 5,
      image_url: null,
      last_purchase_date: daysAgo(10),
      total_quantity: 1,
      category_name: 'Antibiotics',
    };
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [purchase] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    const reminder = body.data.reminders[0];
    expect(reminder.usageDuration).toBe(7);
    expect(reminder.daysUntilRefill).toBe(-3);
    expect(reminder.status).toBe('overdue');
    expect(reminder.urgency).toBe('high');
    expect(reminder.message).toBe('ยา Amoxicillin เลยกำหนดเติมแล้ว 3 วัน');
  });

  it('due today (daysUntilRefill === 0) and medium urgency (<=3) — exact Thai message', async () => {
    // duration 14 (pain), last purchase exactly 14 days ago => refill today.
    const purchase = {
      product_id: 12,
      name: 'Ibuprofen',
      sku: 'IBU-12',
      price: '60.00',
      stock: 20,
      image_url: null,
      last_purchase_date: daysAgo(14),
      total_quantity: 1,
      category_name: 'Pain Relief',
    };
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [purchase] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    const reminder = body.data.reminders[0];
    expect(reminder.usageDuration).toBe(14);
    expect(reminder.daysUntilRefill).toBe(0);
    expect(reminder.status).toBe('due');
    expect(reminder.urgency).toBe('medium');
    expect(reminder.message).toBe('ยา Ibuprofen ถึงกำหนดเติมวันนี้');
  });

  it('due tomorrow (daysUntilRefill === 1) — exact Thai message', async () => {
    // duration 30 (chronic), last purchase 29 days ago => refill tomorrow.
    const purchase = {
      product_id: 13,
      name: 'Metformin',
      sku: 'MET-13',
      price: '120.00',
      stock: 15,
      image_url: null,
      last_purchase_date: daysAgo(29),
      total_quantity: 1,
      category_name: 'Chronic Medication',
    };
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [purchase] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    const reminder = body.data.reminders[0];
    expect(reminder.usageDuration).toBe(30);
    expect(reminder.daysUntilRefill).toBe(1);
    expect(reminder.message).toBe('ยา Metformin จะถึงกำหนดเติมพรุ่งนี้');
  });

  it('excludes purchases whose refill is more than 7 days away', async () => {
    const purchase = {
      product_id: 14,
      name: 'Vitamin D',
      sku: 'VITD-14',
      price: '200.00',
      stock: 30,
      image_url: null,
      last_purchase_date: daysAgo(1), // duration 30 (default) => refill in 29 days
      total_quantity: 1,
      category_name: null,
    };
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [purchase] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.reminders).toHaveLength(0);
    expect(body.data.totalDue).toBe(0);
  });

  it('sort: overdue reminders sort before non-overdue, then ascending by daysUntilRefill', async () => {
    const rows = [
      { // due in 2 days (medium)
        product_id: 1, name: 'A', sku: 'A', price: '1', stock: 1, image_url: null,
        last_purchase_date: daysAgo(28), total_quantity: 1, category_name: null, // duration 30 -> +2d
      },
      { // overdue by 5 days
        product_id: 2, name: 'B', sku: 'B', price: '1', stock: 1, image_url: null,
        last_purchase_date: daysAgo(12), total_quantity: 1, category_name: 'Antibiotics', // duration 7 -> -5d
      },
      { // due in 0 days
        product_id: 3, name: 'C', sku: 'C', price: '1', stock: 1, image_url: null,
        last_purchase_date: daysAgo(30), total_quantity: 1, category_name: null, // duration 30 -> 0d
      },
    ];
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? rows : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.reminders.map((r: { productId: number }) => r.productId)).toEqual([2, 3, 1]);
  });

  it('category duration matching: first matching key wins in chronic/antibiotic/vitamin/pain/default order', async () => {
    const purchase = {
      product_id: 20,
      name: 'X',
      sku: 'X',
      price: '1',
      stock: 1,
      image_url: null,
      last_purchase_date: daysAgo(23),
      total_quantity: 1,
      category_name: 'Chronic Pain Medication', // contains both "chronic" and "pain" — chronic wins (earlier in the map)
    };
    wireFakeDb((sqlText) => (sqlText.includes('FROM transactions t') ? [purchase] : []));

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.reminders[0].usageDuration).toBe(30); // chronic, not pain (14)
  });

  it('a thrown DB failure yields reminders: [] (the PHP fallback-query branch is a documented no-op) — success stays true', async () => {
    wireFakeDb(() => {
      throw new Error('deadlock');
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body).toEqual({ success: true, data: { reminders: [], userId: 42, totalDue: 0 } });
  });

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
