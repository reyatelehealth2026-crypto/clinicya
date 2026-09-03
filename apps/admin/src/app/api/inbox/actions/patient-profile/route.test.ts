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
  const url = `https://tenant.re-ya.com/api/inbox/actions/patient-profile${search}`;
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

interface Fixtures {
  users?: unknown[];
  tags?: unknown[];
  notes?: unknown[];
  prescriptions?: unknown[];
  interactions?: unknown[];
}

function wireFakeDb(fixtures: Fixtures = {}, sessionOverrides: Partial<TenantSession> = {}): RecordedQuery[] {
  const queryImpl = (sqlText: string) => {
    if (sqlText.includes('FROM users')) return fixtures.users ?? [];
    if (sqlText.includes('FROM user_tags')) return fixtures.tags ?? [];
    if (sqlText.includes('FROM customer_notes')) return fixtures.notes ?? [];
    if (sqlText.includes('FROM transactions t')) return fixtures.prescriptions ?? [];
    if (sqlText.includes('FROM drug_interactions')) return fixtures.interactions ?? [];
    return [];
  };
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

/** Mirrors _lib/medicalHistory.ts's calculateAge() exactly, for an exact expected-age assertion. */
function expectedAge(birthDate: Date, today: Date): number {
  let age = today.getFullYear() - birthDate.getFullYear();
  const monthDiff = today.getMonth() - birthDate.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) age -= 1;
  return age;
}

const BIRTH_DATE = new Date(1950, 0, 1);

const USER_ROW = {
  id: 42,
  display_name: 'คุณสมชาย',
  first_name: 'สมชาย',
  last_name: 'ใจดี',
  weight: '70.50',
  height: '175.00',
  birth_date: BIRTH_DATE,
  gender: 'male',
  drug_allergies: 'Penicillin',
  current_medications: 'Metformin, Aspirin',
  medical_conditions: 'เบาหวาน',
};

const TAG_ROW = { id: 1, name: 'VIP', color: '#ff0000', description: 'ลูกค้าประจำ' };
const NOTE_ROW = { id: 5, note: 'แพ้ยาปฏิชีวนะบางชนิด', created_at: new Date(2026, 6, 1, 9, 0, 0), created_by: 3 };
const PRESCRIPTION_ROW = {
  transaction_id: 501,
  order_number: 'ORD-501',
  created_at: new Date(2026, 6, 1, 10, 30, 0),
  status: 'completed',
  product_name: 'Amoxicillin 500mg',
  quantity: 2,
  generic_name: 'Amoxicillin',
  is_prescription: 1,
  drug_category: 'controlled',
};
const INTERACTION_ROW = {
  id: 9,
  drug1_name: 'Metformin',
  drug1_generic: 'Metformin HCl',
  drug2_name: 'Aspirin',
  drug2_generic: 'Acetylsalicylic acid',
  severity: 'severe',
  description: 'Increased bleeding risk',
  recommendation: 'Monitor closely',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/inbox/actions/patient-profile', () => {
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

    const res = await GET(req('?user_id=0'));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid user ID' });
  });

  it('happy path: full profile assembly, schema-drift fixes all evidenced in the compiled SQL, warnings generated', async () => {
    const queries = wireFakeDb({
      users: [USER_ROW],
      tags: [TAG_ROW],
      notes: [NOTE_ROW],
      prescriptions: [PRESCRIPTION_ROW],
      interactions: [INTERACTION_ROW],
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();
    const age = expectedAge(BIRTH_DATE, new Date());

    expect(res.status).toBe(200);
    expect(body.success).toBe(true); // mirrors found: true
    expect(body.data).toEqual({
      userId: 42,
      found: true,
      displayName: 'คุณสมชาย',
      demographics: { age, gender: 'male', weight: 70.5, height: 175 },
      health: {
        allergies: ['Penicillin'],
        conditions: ['เบาหวาน'],
        currentMedications: ['Metformin', 'Aspirin'],
        hasAllergies: true,
        hasConditions: true,
        hasMedications: true,
      },
      tags: [TAG_ROW],
      notes: [{ id: 5, note: 'แพ้ยาปฏิชีวนะบางชนิด', created_at: '2026-07-01 09:00:00', created_by: 3 }],
      prescriptionHistory: [
        {
          transaction_id: 501,
          order_number: 'ORD-501',
          created_at: '2026-07-01 10:30:00',
          status: 'completed',
          product_name: 'Amoxicillin 500mg',
          quantity: 2,
          generic_name: 'Amoxicillin',
          is_prescription: 1,
          drug_category: 'controlled',
        },
      ],
      currentMedicationInteractions: {
        hasInteractions: true,
        interactions: [
          {
            id: 9,
            drug1: 'Metformin',
            drug1Generic: 'Metformin HCl',
            drug2: 'Aspirin',
            drug2Generic: 'Acetylsalicylic acid',
            severity: 'severe',
            description: 'Increased bleeding risk',
            recommendation: 'Monitor closely',
            source: 'database',
          },
        ],
        severity: 'severe',
        severityLabel: 'รุนแรง',
        drugsChecked: ['Metformin', 'Aspirin'],
        interactionCount: 1,
      },
      warnings: [
        {
          type: 'allergy',
          severity: 'high',
          message: 'ลูกค้าแพ้ยา: Penicillin',
          icon: 'fa-exclamation-triangle',
          color: 'red',
        },
        {
          type: 'condition',
          severity: 'medium',
          message: 'โรคประจำตัว: เบาหวาน',
          icon: 'fa-heartbeat',
          color: 'orange',
        },
        {
          type: 'interaction',
          severity: 'high',
          message: 'พบยาตีกันในยาที่ใช้อยู่ 1 คู่',
          icon: 'fa-pills',
          color: 'purple',
          details: [
            {
              id: 9,
              drug1: 'Metformin',
              drug1Generic: 'Metformin HCl',
              drug2: 'Aspirin',
              drug2Generic: 'Acetylsalicylic acid',
              severity: 'severe',
              description: 'Increased bleeding risk',
              recommendation: 'Monitor closely',
              source: 'database',
            },
          ],
        },
        {
          type: 'elderly',
          severity: 'medium',
          message: `ผู้สูงอายุ (อายุ ${age} ปี) - ควรระวังขนาดยา`,
          icon: 'fa-user-clock',
          color: 'blue',
        },
      ],
    });

    // FIX (A)/(B) evidence (medical history, cross-imported).
    const userQuery = queries.find((q) => q.sql.includes('FROM users'));
    expect(userQuery!.sql).toContain('birthday AS birth_date');
    expect(userQuery!.sql).not.toContain('chronic_diseases');

    // FIX (C) evidence (customer_notes, local to this module).
    const notesQuery = queries.find((q) => q.sql.includes('FROM customer_notes'));
    expect(notesQuery!.sql).not.toContain('note_type');
    expect(notesQuery!.sql).toContain('SELECT id, note, created_at, created_by');

    // FIX (D) evidence (prescription history, cross-imported).
    const prescriptionQuery = queries.find((q) => q.sql.includes('FROM transactions t'));
    expect(prescriptionQuery!.sql).toContain('bi.requires_prescription = 1');
    expect(prescriptionQuery!.sql).toContain('bi.requires_prescription AS is_prescription');
    expect(prescriptionQuery!.sql).not.toContain('bi.is_prescription');
    expect(prescriptionQuery!.params).toEqual([42, 10]); // limit=10 per PHP line 851
  });

  it('currentMedicationInteractions stays [] (an array, not the full object) when currentMedications.length <= 1 — no drug_interactions query issued', async () => {
    const queries = wireFakeDb({
      users: [{ ...USER_ROW, current_medications: 'Metformin' }],
    });

    const res = await GET(req('?user_id=42'));
    const body = await res.json();

    expect(body.data.currentMedicationInteractions).toEqual([]);
    expect(queries.some((q) => q.sql.includes('FROM drug_interactions'))).toBe(false);
    // No interaction warning either.
    expect(body.data.warnings.some((w: { type: string }) => w.type === 'interaction')).toBe(false);
  });

  it('user not found: found:false, success:false, empty/degraded shape throughout', async () => {
    wireFakeDb({ users: [] });

    const res = await GET(req('?user_id=999'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(false);
    expect(body.data.found).toBe(false);
    expect(body.data.displayName).toBeNull();
    expect(body.data.demographics).toEqual({ age: null, gender: null, weight: null, height: null });
    expect(body.data.health).toEqual({
      allergies: [],
      conditions: [],
      currentMedications: [],
      hasAllergies: false,
      hasConditions: false,
      hasMedications: false,
    });
    expect(body.data.currentMedicationInteractions).toEqual([]);
    expect(body.data.warnings).toEqual([]);
  });

  // NOTE: no "Database error: ..." 500 test here, deliberately — every DB call
  // inside getComprehensivePatientProfile()'s dependency chain swallows its
  // own errors (getUserMedicalHistory, getUserTagsAndNotes,
  // getUserPrescriptionHistory, findInteraction all have their own
  // try/catch), so route.ts's defensive try/catch is genuinely unreachable
  // through any DB-level failure. Same precedent as
  // ../drug-inventory/route.test.ts and ../low-stock-drugs/route.test.ts
  // (Phase 4 batch 4a).

  it('POST is method-not-allowed (405)', async () => {
    const res = await POST();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });
});
