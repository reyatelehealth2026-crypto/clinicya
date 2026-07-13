/**
 * @jest-environment node
 *
 * POST /api/miniapp/health-profile — the six write actions added in this batch. Kept as a SEPARATE
 * test file from the pre-existing route.test.ts (which owns GET/OPTIONS and must stay functionally
 * untouched) rather than appending to it.
 */
jest.mock('@/lib/miniapp/tenant', () => ({
  resolveMiniappTenantContext: jest.fn(),
  TENANT_UNRESOLVED_RESPONSE: { success: false, error: 'tenant_unresolved' },
  TENANT_UNRESOLVED_STATUS: 400,
}));
jest.mock('./_lib/mutations', () => ({
  updatePersonalAction: jest.fn(),
  updateMedicalHistoryAction: jest.fn(),
  addAllergyAction: jest.fn(),
  removeAllergyAction: jest.fn(),
  addMedicationAction: jest.fn(),
  removeMedicationAction: jest.fn(),
}));

import type { NextRequest } from 'next/server';
import { resolveMiniappTenantContext } from '@/lib/miniapp/tenant';
import { addAllergyAction, updatePersonalAction } from './_lib/mutations';
import { POST } from './route';

const mockResolveTenant = resolveMiniappTenantContext as jest.MockedFunction<typeof resolveMiniappTenantContext>;
const mockUpdatePersonal = updatePersonalAction as jest.MockedFunction<typeof updatePersonalAction>;
const mockAddAllergy = addAllergyAction as jest.MockedFunction<typeof addAllergyAction>;

const FAKE_DB = { __fakeTenantDb: true };

function req(body: Record<string, unknown>): NextRequest {
  return new Request('https://re-ya.com/api/miniapp/health-profile', {
    method: 'POST',
    body: JSON.stringify(body),
  }) as unknown as NextRequest;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockResolveTenant.mockResolvedValue({ ok: true, context: { tenantId: 1, db: FAKE_DB as never } });
});

describe('POST /api/miniapp/health-profile', () => {
  it('unsupported action -> 400 Invalid action, tenant resolution never attempted', async () => {
    const res = await POST(req({ action: 'update_medication', line_user_id: 'U1' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid action' });
    expect(mockResolveTenant).not.toHaveBeenCalled();
  });

  it('tenant_unresolved -> 400, action handler never invoked', async () => {
    mockResolveTenant.mockResolvedValue({ ok: false });
    const res = await POST(req({ action: 'update_personal', line_user_id: 'U1' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(mockUpdatePersonal).not.toHaveBeenCalled();
  });

  it('update_personal dispatches to updatePersonalAction with the parsed body, forwards its status', async () => {
    mockUpdatePersonal.mockResolvedValue({ status: 200, body: { success: true, message: 'บันทึกข้อมูลส่วนตัวแล้ว' } });
    const res = await POST(req({ action: 'update_personal', line_user_id: 'U1', age: 34 }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'บันทึกข้อมูลส่วนตัวแล้ว' });
    expect(mockUpdatePersonal).toHaveBeenCalledWith(FAKE_DB, { action: 'update_personal', line_user_id: 'U1', age: 34 });
  });

  it('add_allergy forwards a non-200 status from the action layer (e.g. 400 duplicate)', async () => {
    mockAddAllergy.mockResolvedValue({ status: 400, body: { success: false, error: 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว' } });
    const res = await POST(req({ action: 'add_allergy', line_user_id: 'U1', drug_name: 'Penicillin' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'ยานี้มีอยู่ในรายการแพ้ยาแล้ว' });
  });

  it('CORS headers set on every POST response', async () => {
    mockUpdatePersonal.mockResolvedValue({ status: 200, body: { success: true, message: 'OK' } });
    const res = await POST(req({ action: 'update_personal', line_user_id: 'U1' }));
    expect(res.headers.get('Access-Control-Allow-Origin')).toBe('*');
  });
});
