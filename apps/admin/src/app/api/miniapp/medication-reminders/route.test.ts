/**
 * @jest-environment node
 */
jest.mock('@reya/tenant', () => ({
  createMasterLineAccountRouteRepository: jest.fn(),
  routeByLineAccount: jest.fn(),
}));
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('@reya/auth', () => {
  const actual = jest.requireActual('@reya/auth');
  return { ...actual, runWithTenantDb: actual.runWithTenantDb };
});

import type { TenantDB } from '@reya/db';
import { getTenantDb } from '@reya/db';
import { routeByLineAccount } from '@reya/tenant';
import { makeFakeKyselyDb, sqlDate, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { GET, OPTIONS, POST } from './route';

const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;

function setupTenant(queryImpl: QueryImpl) {
  const { db, queries } = makeFakeKyselyDb<TenantDB>(queryImpl);
  mockGetTenantDb.mockResolvedValue(db);
  return { db, queries };
}

function requestWithTenantHeader(url: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set('x-tenant-id', '2');
  return new Request(url, { ...init, headers }) as unknown as import('next/server').NextRequest;
}

const REMINDER_ROW = {
  id: 10,
  user_id: 42,
  line_user_id: 'U1',
  line_account_id: 1,
  medication_name: 'พาราเซตามอล 500 มก.',
  dosage: '1 เม็ด',
  frequency: 'twice_daily',
  reminder_times: JSON.stringify(['08:00', '20:00']),
  start_date: sqlDate('2026-07-01'),
  end_date: null,
  notes: 'ทานหลังอาหาร',
  is_active: 1,
  product_id: 200,
  order_id: null,
  created_at: sqlDate('2026-07-01 08:00:00'),
  updated_at: sqlDate('2026-07-01 08:00:00'),
  taken_count_7d: 12,
  missed_count_7d: 2,
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/medication-reminders', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('GET action=list (also the default)', () => {
  it('returns reminders with computed adherence_percent and parsed reminder_times', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('FROM medication_reminders r')) return [REMINDER_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders?action=list&line_user_id=U1&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.reminders).toHaveLength(1);
    expect(body.reminders[0].adherence_percent).toBe(86);
    expect(body.reminders[0].reminder_times).toEqual(['08:00', '20:00']);
    // Contract-drift regression: created_at must be a MySQL-shaped string, not a `Z`-suffixed ISO string.
    expect(body.reminders[0].created_at).toBe('2026-07-01 08:00:00');
  });

  it('no user resolved AND no line_user_id -> {success:true, reminders:[]}', async () => {
    setupTenant((sqlText) => {
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders');
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, reminders: [] });
  });
});

describe('POST action=add', () => {
  it('inserts a reminder, returns reminder_id + Thai success message', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('INSERT INTO medication_reminders')) return { insertId: 11, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders', {
      method: 'POST',
      body: JSON.stringify({
        action: 'add',
        line_user_id: 'U1',
        line_account_id: 1,
        medication_name: 'วิตามินซี',
        dosage: '1 เม็ด',
        frequency: 'daily',
        reminder_times: ['09:00'],
      }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, reminder_id: 11, message: 'เพิ่มการเตือนทานยาแล้ว' });
    expect(queries.some((q) => q.sql.includes('INSERT INTO medication_reminders'))).toBe(true);
  });

  it('empty medication_name -> {success:false, error} with Thai validation string', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders', {
      method: 'POST',
      body: JSON.stringify({ action: 'add', line_user_id: 'U1', line_account_id: 1, medication_name: '' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, error: 'กรุณาระบุชื่อยา' });
  });

  it('user not found -> {success:false, error:"User not found"}', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders', {
      method: 'POST',
      body: JSON.stringify({ action: 'add', line_user_id: 'Uunknown', medication_name: 'ยา' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, error: 'User not found' });
  });
});

describe('POST action=delete — NO ownership pre-check (see route.ts doc comment)', () => {
  it('always succeeds even when 0 rows matched (someone else\'s or a nonexistent reminder_id)', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('UPDATE medication_reminders SET is_active')) return { insertId: 0, affectedRows: 0 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete', line_user_id: 'U1', line_account_id: 1, reminder_id: 999999 }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'ลบการเตือนแล้ว' });
    expect(queries.some((q) => q.sql.includes('SELECT id FROM medication_reminders'))).toBe(false);
  });
});

describe('POST action=mark_taken — DOES verify ownership first (asymmetric with delete)', () => {
  it('ok: ownership verified, then inserted into medication_taken_history', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('SELECT id FROM medication_reminders')) return [{ id: 10 }];
      if (sqlText.includes('INSERT INTO medication_taken_history')) return { insertId: 1, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders', {
      method: 'POST',
      body: JSON.stringify({ action: 'mark_taken', line_user_id: 'U1', line_account_id: 1, reminder_id: 10, status: 'taken' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'บันทึกการทานยาแล้ว' });
  });

  it('ownership check misses -> {success:false, error:"Reminder not found"}, no INSERT', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('SELECT id FROM medication_reminders')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders', {
      method: 'POST',
      body: JSON.stringify({ action: 'mark_taken', line_user_id: 'U1', line_account_id: 1, reminder_id: 999999, status: 'taken' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, error: 'Reminder not found' });
    expect(queries.some((q) => q.sql.includes('INSERT INTO medication_taken_history'))).toBe(false);
  });
});

describe('top-level catch — raw exception message leak (replicated verbatim)', () => {
  it('a thrown DB error surfaces as {success:false, error:<raw message>}', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users')) return [{ id: 42 }];
      if (sqlText.includes('FROM medication_reminders r')) throw new Error('simulated DB failure');
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/medication-reminders?action=list&line_user_id=U1&line_account_id=1');
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, error: 'simulated DB failure' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/medication-reminders') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
