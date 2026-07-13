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
import { APPOINTMENT_ID_FORMAT_REGEX } from '@reya/contracts';
import { addDaysToDateString, todayInBangkok } from './_lib/bangkokTime';
import { GET, OPTIONS, POST } from './route';

// A near date (a few days out) rather than a hardcoded far-future literal — avoids the test suite
// tripping api/appointments.php's own "no more than 30 days ahead" validation as wall-clock time
// passes (this file's `available_slots` fixtures need dates that are always both in-range and in the
// future relative to whenever the test actually runs).
const NEAR_FUTURE_DATE = addDaysToDateString(todayInBangkok(), 5);

const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;

// The committed tenant template's real `appointments` column list (see _lib/columns.ts's doc comment)
// — no appointment_id/end_time/duration/type/symptoms/consultation_fee/cancelled_by.
const REAL_APPOINTMENT_COLUMNS = [
  'id',
  'line_account_id',
  'user_id',
  'pharmacist_id',
  'appointment_type',
  'appointment_date',
  'appointment_time',
  'duration_minutes',
  'status',
  'notes',
  'reminder_sent',
  'created_at',
  'updated_at',
  'reminder_10min_sent',
  'reminder_now_sent',
  'cancelled_reason',
];

function appointmentColumnsResult(): Array<{ Field: string }> {
  return REAL_APPOINTMENT_COLUMNS.map((Field) => ({ Field }));
}

function setupTenant(queryImpl: QueryImpl) {
  const { db, queries } = makeFakeKyselyDb<TenantDB>(queryImpl);
  mockGetTenantDb.mockResolvedValue(db);
  return { db, queries };
}

function requestWithTenantHeader(url: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set('x-tenant-id', '2');
  if (init.body) headers.set('content-type', 'application/json');
  return new Request(url, { ...init, headers }) as unknown as import('next/server').NextRequest;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/appointments', () => {
  it('answers 204 with CORS headers', async () => {
    const response = await OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Methods')).toBe('GET, POST, OPTIONS');
  });
});

describe('GET action=pharmacists', () => {
  it('selects the full optional column set unconditionally (no SHOW COLUMNS FROM pharmacists), defaults null optional fields, insurances always []', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('FROM pharmacists') && sqlText.includes('WHERE is_active = 1')) {
        return [
          {
            id: 2,
            name: 'ภญ. สมหญิง',
            title: null,
            specialty: null,
            sub_specialty: null,
            hospital: null,
            license_no: null,
            bio: null,
            consulting_areas: null,
            work_experience: null,
            image_url: null,
            rating: null,
            review_count: null,
            consultation_fee: null,
            consultation_duration: null,
            is_available: null,
            is_active: 1,
            line_account_id: 1,
          },
        ];
      }
      if (sqlText.includes('SELECT COUNT(*) as cnt FROM appointments')) return [{ cnt: 3 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments?action=pharmacists');
    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, message: 'OK' });
    expect(body.pharmacists).toHaveLength(1);
    expect(body.pharmacists[0]).toMatchObject({
      title: '',
      specialty: 'เภสัชกร',
      is_available: 1,
      rating: 5,
      review_count: 0,
      consultation_fee: 0,
      consultation_duration: 15,
      case_count: 3,
      insurances: [],
    });
    expect(queries.some((q) => q.sql.includes('SHOW COLUMNS FROM pharmacists'))).toBe(false);
  });
});

describe('GET action=available_slots', () => {
  it('SUBTLE TRAP: holiday branch overrides message to วันหยุด, never OK', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, consultation_duration FROM pharmacists')) return [{ id: 2, consultation_duration: 20 }];
      if (sqlText.includes('FROM pharmacist_holidays')) return [{ id: 9 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader(`https://mini.example.com/api/miniapp/appointments?action=available_slots&pharmacist_id=2&date=${NEAR_FUTURE_DATE}`);
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'วันหยุด', slots: [] });
  });

  it('PRESERVED BUG: the booked-slots query references a nonexistent `duration` column and throws — swallowed, so bookedSlots is always [] and no slot is ever marked unavailable', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id, consultation_duration FROM pharmacists')) return [{ id: 2, consultation_duration: 60 }];
      if (sqlText.includes('FROM pharmacist_holidays')) return [];
      if (sqlText.includes('FROM pharmacist_schedules')) return [{ start_time: '09:00:00', end_time: '11:00:00' }];
      if (sqlText.includes('SELECT appointment_time, duration FROM appointments')) {
        throw new Error("Unknown column 'duration' in 'field list'");
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    // A future date so the "isToday" past-slot skip never triggers.
    const request = requestWithTenantHeader(`https://mini.example.com/api/miniapp/appointments?action=available_slots&pharmacist_id=2&date=${NEAR_FUTURE_DATE}`);
    const response = await GET(request);
    const body = await response.json();

    expect(body.success).toBe(true);
    expect(body.message).toBe('OK');
    expect(body.slots).toEqual([
      { time: '09:00', available: true },
      { time: '10:00', available: true },
    ]);
  });

  it('missing pharmacist_id -> flat failure, no query issued', async () => {
    const { queries } = setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments?action=available_slots');
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'Missing pharmacist_id' });
    expect(queries).toHaveLength(0);
  });
});

describe('POST action=book', () => {
  it('success: appointment_id matches APT+15-digit format, INSERT only includes the base columns + line_account_id (appointment_id/end_time/duration/type/symptoms/consultation_fee are all absent from the committed template)', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users WHERE line_user_id')) return [{ id: 9 }];
      if (sqlText.includes('SELECT id, consultation_fee, consultation_duration FROM pharmacists')) {
        return [{ id: 2, consultation_fee: '0.00', consultation_duration: 20 }];
      }
      if (sqlText.includes('WHERE pharmacist_id = ?') && sqlText.includes('appointment_time')) return []; // slot-taken check
      if (sqlText.includes('WHERE user_id = ?') && sqlText.includes('appointment_time')) return []; // user-conflict check
      if (sqlText.includes('SHOW COLUMNS FROM appointments')) return appointmentColumnsResult();
      if (sqlText.includes('INSERT INTO appointments')) return { insertId: 55, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({
        action: 'book',
        line_user_id: 'U1234567890abcdef1234567890abcdef',
        line_account_id: 1,
        pharmacist_id: 2,
        date: '2026-07-14',
        time: '09:20',
        type: 'scheduled',
        symptoms: 'ปรึกษายา',
      }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toMatchObject({ success: true, message: 'จองนัดหมายสำเร็จ!', id: 55, date: '2026-07-14', time: '09:20', duration: 20 });
    expect(body.appointment_id).toMatch(APPOINTMENT_ID_FORMAT_REGEX);

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO appointments'));
    expect(insertQuery).toBeDefined();
    expect(insertQuery!.sql).toContain('`user_id`');
    expect(insertQuery!.sql).toContain('`pharmacist_id`');
    expect(insertQuery!.sql).toContain('`appointment_date`');
    expect(insertQuery!.sql).toContain('`appointment_time`');
    expect(insertQuery!.sql).toContain('`status`');
    expect(insertQuery!.sql).toContain('`line_account_id`');
    // NOT persisted — columns don't exist on the committed template.
    expect(insertQuery!.sql).not.toContain('`appointment_id`');
    expect(insertQuery!.sql).not.toContain('`end_time`');
    expect(insertQuery!.sql).not.toContain('`duration`');
    expect(insertQuery!.sql).not.toContain('`type`');
    expect(insertQuery!.sql).not.toContain('`symptoms`');
    expect(insertQuery!.sql).not.toContain('`consultation_fee`');
  });

  it('missing line_user_id -> flat failure', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({ action: 'book', pharmacist_id: 2, date: '2026-07-14', time: '09:20' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'กรุณาเข้าสู่ระบบ' });
  });

  it('slot already taken -> flat failure', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users WHERE line_user_id')) return [{ id: 9 }];
      if (sqlText.includes('SELECT id, consultation_fee, consultation_duration FROM pharmacists')) {
        return [{ id: 2, consultation_fee: 0, consultation_duration: 20 }];
      }
      if (sqlText.includes('WHERE pharmacist_id = ?') && sqlText.includes('appointment_time')) return [{ id: 999 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({ action: 'book', line_user_id: 'U1', pharmacist_id: 2, date: '2026-07-14', time: '09:20' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'ช่วงเวลานี้ถูกจองแล้ว กรุณาเลือกเวลาอื่น' });
  });
});

describe('GET action=my_appointments', () => {
  it('splits into upcoming/past by appointment_date + terminal status, all is the unsplit union', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users WHERE line_user_id')) return [{ id: 9 }];
      if (sqlText.includes('FROM appointments a JOIN pharmacists p')) {
        return [
          {
            id: 55,
            line_account_id: 1,
            user_id: 9,
            pharmacist_id: 2,
            appointment_type: 'consultation',
            appointment_date: sqlDate('2026-12-14'),
            appointment_time: '09:20:00',
            duration_minutes: 30,
            status: 'confirmed',
            notes: null,
            reminder_sent: 0,
            created_at: sqlDate('2026-07-13 10:00:00'),
            updated_at: sqlDate('2026-07-13 10:00:00'),
            reminder_10min_sent: 0,
            reminder_now_sent: 0,
            cancelled_reason: null,
            pharmacist_name: 'ภญ. สมหญิง',
            pharmacist_title: 'เภสัชกร',
            specialty: 'เภสัชกรรมคลินิก',
            pharmacist_image: null,
          },
          {
            id: 40,
            line_account_id: 1,
            user_id: 9,
            pharmacist_id: 2,
            appointment_type: 'consultation',
            appointment_date: sqlDate('2026-06-01'),
            appointment_time: '10:00:00',
            duration_minutes: 30,
            status: 'completed',
            notes: null,
            reminder_sent: 1,
            created_at: sqlDate('2026-05-30 08:00:00'),
            updated_at: sqlDate('2026-06-01 10:35:00'),
            reminder_10min_sent: 1,
            reminder_now_sent: 1,
            cancelled_reason: null,
            pharmacist_name: 'ภญ. สมหญิง',
            pharmacist_title: 'เภสัชกร',
            specialty: 'เภสัชกรรมคลินิก',
            pharmacist_image: null,
          },
        ];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments?action=my_appointments&line_user_id=U1234567890abcdef1234567890abcdef');
    const response = await GET(request);
    const body = await response.json();

    expect(body.success).toBe(true);
    expect(body.upcoming).toHaveLength(1);
    expect(body.past).toHaveLength(1);
    expect(body.all).toHaveLength(2);
    expect(body.upcoming[0].appointment_date).toBe('2026-12-14');
    expect(body.past[0].status).toBe('completed');
  });

  it('missing line_user_id -> flat failure', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments?action=my_appointments');
    const response = await GET(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'Missing line_user_id' });
  });
});

describe('POST action=cancel', () => {
  it('success: lookup always takes the WHERE id = ? branch (appointment_id column absent), cancelled_reason set, cancelled_by NOT set', async () => {
    const { queries } = setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users WHERE line_user_id')) return [{ id: 9 }];
      if (sqlText.includes('SHOW COLUMNS FROM appointments')) return appointmentColumnsResult();
      if (sqlText.includes('SELECT * FROM appointments WHERE id = ?')) {
        return [{ id: 55, user_id: 9, status: 'confirmed', appointment_date: sqlDate('2099-01-01'), appointment_time: '09:20:00' }];
      }
      if (sqlText.includes('UPDATE appointments SET')) return { affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({ action: 'cancel', appointment_id: 55, line_user_id: 'U1234567890abcdef1234567890abcdef', reason: 'ติดธุระ' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: true, message: 'ยกเลิกนัดหมายสำเร็จ' });

    const lookupQuery = queries.find((q) => q.sql.includes('SELECT * FROM appointments'));
    expect(lookupQuery!.sql).toContain('WHERE id = ?');
    expect(lookupQuery!.sql).not.toContain('appointment_id = ?');

    const updateQuery = queries.find((q) => q.sql.includes('UPDATE appointments SET'));
    expect(updateQuery!.sql).toContain('cancelled_reason');
    expect(updateQuery!.sql).not.toContain('cancelled_by');
  });

  it('appointment already in the past -> the one real gate that is enforced', async () => {
    setupTenant((sqlText) => {
      if (sqlText.includes('SELECT id FROM users WHERE line_user_id')) return [{ id: 9 }];
      if (sqlText.includes('SHOW COLUMNS FROM appointments')) return appointmentColumnsResult();
      if (sqlText.includes('SELECT * FROM appointments WHERE id = ?')) {
        return [{ id: 40, user_id: 9, status: 'confirmed', appointment_date: sqlDate('2020-01-01'), appointment_time: '10:00:00' }];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({ action: 'cancel', appointment_id: 40, line_user_id: 'U1' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'ไม่สามารถยกเลิกนัดหมายที่ผ่านไปแล้ว' });
  });

  it('missing appointment_id -> flat failure, no query issued', async () => {
    const { queries } = setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({ action: 'cancel', line_user_id: 'U1' }),
    });
    const response = await POST(request);
    const body = await response.json();

    expect(body).toEqual({ success: false, message: 'ข้อมูลไม่ครบถ้วน' });
    expect(queries).toHaveLength(0);
  });
});

describe('unrecognised action', () => {
  it('GET default -> Invalid action', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments?action=rate');
    const response = await GET(request);
    expect(await response.json()).toEqual({ success: false, message: 'Invalid action' });
  });

  it('POST default -> Invalid action', async () => {
    setupTenant(() => []);
    const request = requestWithTenantHeader('https://mini.example.com/api/miniapp/appointments', {
      method: 'POST',
      body: JSON.stringify({ action: 'rate' }),
    });
    const response = await POST(request);
    expect(await response.json()).toEqual({ success: false, message: 'Invalid action' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/appointments?action=pharmacists') as unknown as import('next/server').NextRequest;

    const response = await GET(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
  });
});
