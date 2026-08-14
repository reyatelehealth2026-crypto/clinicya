jest.mock('next/navigation', () => ({
  redirect: jest.fn((url: string) => {
    const err = new Error('NEXT_REDIRECT');
    (err as unknown as { digest: string; url: string }).digest = 'NEXT_REDIRECT';
    (err as unknown as { digest: string; url: string }).url = url;
    throw err;
  }),
}));

jest.mock('./_lib/session', () => ({
  requireTenantPageContext: jest.fn(),
}));

jest.mock('./_lib/orderStatusFlex', () => ({
  sendOrderStatusFlex: jest.fn().mockResolvedValue(true),
  sendOrderRejectionFlex: jest.fn().mockResolvedValue(true),
}));

import { redirect } from 'next/navigation';
import { requireTenantPageContext, type TenantPageContext } from './_lib/session';
import { sendOrderStatusFlex, sendOrderRejectionFlex } from './_lib/orderStatusFlex';
import { makeFakeTenantDb, type RecordedQuery } from './testHelpers/fakeTenantDb';
import {
  verifySlipAction,
  updateStatusAction,
  approvePaymentAction,
  updateShippingAction,
  rejectPaymentAction,
  addTrackingAction,
} from './actions';

const mockRedirect = redirect as unknown as jest.Mock;
const mockRequireTenantPageContext = requireTenantPageContext as jest.MockedFunction<typeof requireTenantPageContext>;
const mockSendOrderStatusFlex = sendOrderStatusFlex as jest.MockedFunction<typeof sendOrderStatusFlex>;
const mockSendOrderRejectionFlex = sendOrderRejectionFlex as jest.MockedFunction<typeof sendOrderRejectionFlex>;

const GUARD = /line_account_id\s*=\s*\?\s*or\s*line_account_id\s+is\s+null/i;

function formData(entries: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(entries)) fd.set(k, v);
  return fd;
}

const SESSION: TenantPageContext['session'] = {
  realm: 'tenant',
  sid: 's',
  adminUserId: 1,
  tenantId: 2,
  currentBotId: 7,
  role: 'admin',
  username: 'admin1',
  displayName: 'Admin',
  createdAt: '',
  lastSeenAt: '',
  expiresAt: '',
};

async function runAction(action: () => Promise<void>): Promise<void> {
  await expect(action()).rejects.toThrow('NEXT_REDIRECT');
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('tenant-guard enumeration across all 5 POST actions', () => {
  it('updateStatusAction: BOTH the status UPDATE and the tracking UPDATE carry the (line_account_id = ? OR line_account_id IS NULL) guard', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    await runAction(() => updateStatusAction(42, formData({ status: 'confirmed', tracking: 'TH123' })));

    const statusUpdate = queries.find((q) => /update transactions set status = /i.test(q.sql));
    const trackingUpdate = queries.find((q) => /update transactions set shipping_tracking = /i.test(q.sql));
    expect(statusUpdate?.sql.toLowerCase()).toMatch(GUARD);
    expect(trackingUpdate?.sql.toLowerCase()).toMatch(GUARD);
  });

  it('approvePaymentAction: the payment_status/status UPDATE carries the guard', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    await runAction(() => approvePaymentAction(42, formData({})));

    const paidUpdate = queries.find((q) => /update transactions set payment_status = 'paid'/i.test(q.sql));
    expect(paidUpdate).toBeDefined();
    expect(paidUpdate!.sql.toLowerCase()).toMatch(GUARD);
  });

  // verifySlipAction's guard assertion (its payment-status UPDATE on a
  // VERIFIED slip) needs a verified VerifyResult to actually reach that
  // UPDATE — see the dedicated "verifySlipAction — verified path guard"
  // describe block below, which drives it deterministically via
  // `verifyStored()` (no live network call) rather than hitting GhostX.

  it('updateShippingAction: the UPDATE carries NO tenant guard (literal PHP behavior)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    await runAction(() =>
      updateShippingAction(42, formData({ shipping_name: 'สมชาย', shipping_phone: '0812345678', shipping_address: '123 ถนน' }))
    );

    const update = queries.find((q) => /update transactions set shipping_name/i.test(q.sql));
    expect(update).toBeDefined();
    expect(update!.sql.toLowerCase()).not.toContain('line_account_id');
  });

  it('addTrackingAction: the UPDATE carries NO tenant guard (literal PHP behavior — "Update without line_account_id filter")', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    await runAction(() => addTrackingAction(42, formData({ tracking: 'TH999888777' })));

    const update = queries.find((q) => /update transactions set shipping_tracking = .* status = 'shipping'/i.test(q.sql));
    expect(update).toBeDefined();
    expect(update!.sql.toLowerCase()).not.toContain('line_account_id');
  });
});

describe('verifySlipAction — verified path guard (SlipVerifier stubbed via a pre-stored GhostX response)', () => {
  it('re-evaluates a stored verify_data response (no network) and, once verified, guards the payment-status UPDATE', async () => {
    const priorResponse = {
      slipVerification: {
        transfer: {
          transactionRef: 'REF-1',
          amount: { amount: 500.0 },
          toAccountNo: '9876543210',
        },
      },
    };
    const { db, setQueryImpl, queries } = makeFakeTenantDb();
    setQueryImpl((sqlTextRaw) => {
      const s = sqlTextRaw.toLowerCase();
      if (s.includes('from payment_slips where id')) {
        return [{ id: 5, transaction_id: 42, qr_payload: null, verify_data: JSON.stringify(priorResponse) }];
      }
      if (s.includes('select grand_total, total_amount')) {
        return [{ grand_total: '500.00', total_amount: '500.00' }];
      }
      if (s.includes('from shop_settings')) {
        return [{ promptpayNumber: '9876543210', bankAccounts: null }];
      }
      if (s.includes('verify_ref = ')) {
        return []; // no dup
      }
      return [];
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    // qr_data posted non-empty so `qr !== ''`, but verify_data already carries a transfer ->
    // verifyStored() path is used (NO network call) per actions.ts's literal port.
    await runAction(() => verifySlipAction(42, formData({ slip_id: '5', qr_data: 'unused-because-prior-exists' })));

    const paidUpdate = queries.find((q) => /update transactions set payment_status='paid'/i.test(q.sql));
    expect(paidUpdate).toBeDefined();
    expect(paidUpdate!.sql.toLowerCase()).toMatch(GUARD);
    expect(mockRedirect).toHaveBeenCalledWith(expect.stringContaining('verify=ok'));
    expect(mockSendOrderStatusFlex).toHaveBeenCalledWith(db, 7, 42, 'paid');
  });
});

describe("approvePaymentAction's loyalty-points award path", () => {
  it('inserts into BOTH points_history and points_transactions, floors the earned amount, and defaults pointsPerBaht to 1', async () => {
    const { db, setQueryImpl, queries } = makeFakeTenantDb();
    setQueryImpl((sqlTextRaw) => {
      const s = sqlTextRaw.toLowerCase();
      if (s.includes('from transactions o join users u')) {
        return [{ user_id: 9, grand_total: '150.75', order_number: 'ORD-1', current_points: 5 }];
      }
      if (s.includes('from points_settings')) {
        return []; // no row -> default pointsPerBaht = 1
      }
      return [];
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    await runAction(() => approvePaymentAction(42, formData({})));

    // floor(150.75 * 1) = 150.
    const historyInsert = queries.find((q: RecordedQuery) => /insert into `points_history`/i.test(q.sql));
    const txInsert = queries.find((q: RecordedQuery) => /insert into `points_transactions`/i.test(q.sql));
    expect(historyInsert).toBeDefined();
    expect(txInsert).toBeDefined();
    expect(historyInsert!.params).toContain(150);
    expect(txInsert!.params).toContain(150);

    const userPointsUpdate = queries.find((q) => /update `users`/i.test(q.sql));
    expect(userPointsUpdate?.sql).toMatch(/set `points`/i);
  });
});

describe('rejectPaymentAction', () => {
  it('marks pending slips rejected and sends the SEPARATE rejection Flex bubble (not sendOrderStatusFlex)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    await runAction(() => rejectPaymentAction(42, formData({})));

    const rejectUpdate = queries.find((q) => /update payment_slips set status = 'rejected'/i.test(q.sql));
    expect(rejectUpdate).toBeDefined();
    expect(mockSendOrderRejectionFlex).toHaveBeenCalledWith(db, 7, 42);
    expect(mockSendOrderStatusFlex).not.toHaveBeenCalled();
    expect(mockRedirect).toHaveBeenCalledWith('/shop/order-detail?id=42&rejected=1');
  });
});

describe('redirect targets', () => {
  it('updateStatusAction redirects to ?updated=1', async () => {
    const { db } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });
    await runAction(() => updateStatusAction(42, formData({ status: 'confirmed' })));
    expect(mockRedirect).toHaveBeenCalledWith('/shop/order-detail?id=42&updated=1');
  });

  it('addTrackingAction redirects to ?tracking_added=1', async () => {
    const { db } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });
    await runAction(() => addTrackingAction(42, formData({ tracking: 'TH1' })));
    expect(mockRedirect).toHaveBeenCalledWith('/shop/order-detail?id=42&tracking_added=1');
  });

  it('addTrackingAction skips the UPDATE + redirect-cause when tracking is the string "0" (PHP truthiness)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });
    await runAction(() => addTrackingAction(42, formData({ tracking: '0' })));
    expect(queries.some((q) => /update transactions/i.test(q.sql))).toBe(false);
  });
});
