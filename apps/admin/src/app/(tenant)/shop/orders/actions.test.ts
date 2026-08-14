import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('./_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRevalidatePath = jest.fn();
jest.mock('next/cache', () => ({
  revalidatePath: (...args: unknown[]) => mockRevalidatePath(...args),
}));

const mockLineSendMessage = jest.fn();
const mockLinePushMessage = jest.fn();
jest.mock('@reya/line', () => ({
  sendMessage: (...args: unknown[]) => mockLineSendMessage(...args),
  pushMessage: (...args: unknown[]) => mockLinePushMessage(...args),
}));

import { updateOrderStatusAction, approvePaymentAction } from './actions';

const SESSION = { adminUserId: 1, username: 'admin1', currentBotId: 7 };

const ORDER_ROW_WITH_LINE_USER = {
  order_number: 'ORD-100',
  line_user_id: 'Uabc',
  reply_token: 'reply-tok',
  reply_token_expires_str: '2026-01-01 00:00:00',
};

const ORDER_ROW_NO_REPLY_TOKEN = {
  order_number: 'ORD-101',
  line_user_id: 'Uxyz',
  reply_token: null,
  reply_token_expires_str: null,
};

const ORDER_ROW_NO_LINE_USER = {
  order_number: 'ORD-102',
  line_user_id: null,
  reply_token: null,
  reply_token_expires_str: null,
};

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown, sessionOverrides: Partial<typeof SESSION> = {}) {
  const handle = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db: handle.db, session: { ...SESSION, ...sessionOverrides } });
  return handle;
}

/** Default fake DB: order+user SELECT returns ORDER_ROW_WITH_LINE_USER, line_accounts SELECT returns a token, everything else no-ops. */
function wireHappyPathDb(orderRow: Record<string, unknown> | undefined = ORDER_ROW_WITH_LINE_USER) {
  return wireDb((sqlText) => {
    if (sqlText.includes('FROM transactions o') && sqlText.includes('JOIN users u')) {
      return orderRow ? [orderRow] : [];
    }
    if (sqlText.includes('FROM line_accounts')) {
      return [{ channel_access_token: 'token-abc' }];
    }
    return { affectedRows: 1 };
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockLineSendMessage.mockResolvedValue({ code: 200, method: 'reply', body: {} });
  mockLinePushMessage.mockResolvedValue({ code: 200, body: {} });
});

describe('updateOrderStatusAction', () => {
  it('throws on a missing/invalid orderId without touching the db', async () => {
    await expect(updateOrderStatusAction({ orderId: 0, status: 'confirmed' })).rejects.toThrow('Missing required fields');
    expect(mockRequireTenantPageContext).not.toHaveBeenCalled();
  });

  it('UPDATEs status WITH the tenant guard: AND (line_account_id = ? OR line_account_id IS NULL)', async () => {
    const { queries } = wireHappyPathDb();
    await updateOrderStatusAction({ orderId: 42, status: 'confirmed' });

    const updateStatus = queries.find((q) => q.sql.includes('UPDATE transactions SET status ='));
    expect(updateStatus?.sql).toContain('UPDATE transactions SET status = ?');
    expect(updateStatus?.sql).toContain('WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)');
    expect(updateStatus?.params).toEqual(['confirmed', 42, 7]);
  });

  it.each(['confirmed', 'paid'])('fires the best-effort wms_status UPDATE when newStatus=%s', async (status) => {
    const { queries } = wireHappyPathDb();
    await updateOrderStatusAction({ orderId: 5, status });
    const wms = queries.find((q) => q.sql.includes('wms_status'));
    expect(wms?.sql).toContain("SET wms_status = 'pending_pick'");
    expect(wms?.sql).toContain("WHERE id = ? AND (wms_status IS NULL OR wms_status = '')");
    expect(wms?.params).toEqual([5]);
  });

  it.each(['pending', 'shipping', 'delivered', 'cancelled'])('does NOT fire the wms_status UPDATE when newStatus=%s', async (status) => {
    const { queries } = wireHappyPathDb();
    await updateOrderStatusAction({ orderId: 5, status });
    expect(queries.find((q) => q.sql.includes('wms_status'))).toBeUndefined();
  });

  it('the wms_status UPDATE is best-effort: its failure does not abort the action', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('wms_status')) {
        throw new Error('column does not exist');
      }
      if (sqlText.includes('FROM transactions o') && sqlText.includes('JOIN users u')) return [ORDER_ROW_NO_LINE_USER];
      return { affectedRows: 1 };
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    const result = await updateOrderStatusAction({ orderId: 5, status: 'confirmed' });
    expect(result).toEqual({ success: true });
    expect(queries.some((q) => q.sql.includes('activity_logs'))).toBe(true);
  });

  it('INSERTs one activity_logs row (action=update, entity_type=order, new_value={status})', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await updateOrderStatusAction({ orderId: 8, status: 'paid' });
    const log = queries.find((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(log?.params).toEqual(['order', 'update', 'อัพเดทสถานะคำสั่งซื้อ', 1, 'admin1', 'order', 8, JSON.stringify({ status: 'paid' }), 7]);
  });

  it('sends the LINE notify via sendMessage with the order reply token when the order has a line_user_id', async () => {
    wireHappyPathDb(ORDER_ROW_WITH_LINE_USER);
    await updateOrderStatusAction({ orderId: 42, status: 'confirmed' });

    expect(mockLineSendMessage).toHaveBeenCalledWith(
      expect.objectContaining({
        userId: 'Uabc',
        replyToken: 'reply-tok',
        tokenExpires: '2026-01-01 00:00:00',
      }),
      { channelAccessToken: 'token-abc' }
    );
    expect(mockLinePushMessage).not.toHaveBeenCalled();
    expect(mockLineSendMessage.mock.calls[0]?.[0].messages).toContain('ORD-100');
    expect(mockLineSendMessage.mock.calls[0]?.[0].messages).toContain('ยืนยันแล้ว');
  });

  it('still routes through sendMessage (whose own internals fall back to push) when the order has no reply token', async () => {
    wireHappyPathDb(ORDER_ROW_NO_REPLY_TOKEN);
    await updateOrderStatusAction({ orderId: 42, status: 'confirmed' });
    expect(mockLineSendMessage).toHaveBeenCalledWith(expect.objectContaining({ replyToken: null }), { channelAccessToken: 'token-abc' });
  });

  it('does NOT notify at all when the order has no line_user_id', async () => {
    wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await updateOrderStatusAction({ orderId: 42, status: 'confirmed' });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
    expect(mockLinePushMessage).not.toHaveBeenCalled();
  });

  it('saves shipping_tracking and appends it to the message ONLY when status=shipping AND the order has a line_user_id (preserves the PHP quirk)', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_WITH_LINE_USER);
    await updateOrderStatusAction({ orderId: 42, status: 'shipping', tracking: 'TH123456789' });

    const trackingUpdate = queries.find((q) => q.sql.includes('shipping_tracking'));
    expect(trackingUpdate?.sql).toBe('UPDATE transactions SET shipping_tracking = ? WHERE id = ?');
    expect(trackingUpdate?.params).toEqual(['TH123456789', 42]);
    expect(mockLineSendMessage.mock.calls[0]?.[0].messages).toContain('TH123456789');
  });

  it('does NOT save shipping_tracking when the order has no line_user_id, even if tracking was submitted (the guard the PHP quirk creates)', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await updateOrderStatusAction({ orderId: 42, status: 'shipping', tracking: 'TH123456789' });
    expect(queries.find((q) => q.sql.includes('shipping_tracking'))).toBeUndefined();
  });

  it('treats tracking "0" as empty, matching PHP\'s !empty() semantics', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_WITH_LINE_USER);
    await updateOrderStatusAction({ orderId: 42, status: 'shipping', tracking: '0' });
    expect(queries.find((q) => q.sql.includes('shipping_tracking'))).toBeUndefined();
  });

  it('calls revalidatePath("/shop/orders") — the Server Action substitute for PHP\'s <script> redirect', async () => {
    wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await updateOrderStatusAction({ orderId: 42, status: 'confirmed' });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/shop/orders');
  });

  it('resolves the line_accounts lookup by currentBotId ?? 1 (session default), not the raw nullable session value', async () => {
    const { queries } = wireDb(
      (sqlText) => {
        if (sqlText.includes('FROM transactions o') && sqlText.includes('JOIN users u')) return [ORDER_ROW_WITH_LINE_USER];
        return { affectedRows: 1 };
      },
      { currentBotId: null }
    );
    await updateOrderStatusAction({ orderId: 42, status: 'confirmed' });
    const lineAccountQuery = queries.find((q) => q.sql.includes('FROM line_accounts'));
    expect(lineAccountQuery?.params).toEqual([1]);
    const tenantGuardUpdate = queries.find((q) => q.sql.includes('UPDATE transactions SET status ='));
    expect(tenantGuardUpdate?.params).toEqual(['confirmed', 42, 1]);
  });
});

describe('approvePaymentAction', () => {
  it('throws on a missing/invalid orderId without touching the db', async () => {
    await expect(approvePaymentAction(-1)).rejects.toThrow('Missing required fields');
    expect(mockRequireTenantPageContext).not.toHaveBeenCalled();
  });

  it('UPDATEs payment_status/status with NO tenant guard at all (flagged PHP inconsistency, preserved)', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(42);

    const update = queries.find((q) => q.sql.includes('payment_status'));
    expect(update?.sql).toBe("UPDATE transactions SET payment_status = 'paid', status = 'paid' WHERE id = ?");
    expect(update?.params).toEqual([42]);
    expect(update?.sql).not.toContain('line_account_id');
  });

  it('fires the wms_status UPDATE unconditionally (no newStatus gate, unlike updateOrderStatusAction)', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(42);
    const wms = queries.find((q) => q.sql.includes('wms_status'));
    expect(wms?.sql).toContain("SET wms_status = 'pending_pick'");
    expect(wms?.params).toEqual([42]);
  });

  it('the wms_status UPDATE is best-effort here too: its failure does not abort the action', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('wms_status')) {
        throw new Error('column does not exist');
      }
      if (sqlText.includes('FROM transactions o') && sqlText.includes('JOIN users u')) return [ORDER_ROW_NO_LINE_USER];
      return { affectedRows: 1 };
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: SESSION });

    const result = await approvePaymentAction(42);
    expect(result).toEqual({ success: true });
    expect(queries.some((q) => q.sql.includes('activity_logs'))).toBe(true);
  });

  it('INSERTs one activity_logs row (action=approve, entity_type=order, new_value={payment_status,status})', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(9);
    const log = queries.find((q) => q.sql.includes('INSERT INTO activity_logs'));
    expect(log?.params).toEqual([
      'order',
      'approve',
      'อนุมัติการชำระเงิน',
      1,
      'admin1',
      'order',
      9,
      JSON.stringify({ payment_status: 'paid', status: 'paid' }),
      7,
    ]);
  });

  it('sends a plain-text LINE notify via sendMessage with the order reply token when the order has a line_user_id', async () => {
    wireHappyPathDb(ORDER_ROW_WITH_LINE_USER);
    await approvePaymentAction(42);

    expect(mockLineSendMessage).toHaveBeenCalledWith(
      expect.objectContaining({ userId: 'Uabc', replyToken: 'reply-tok' }),
      { channelAccessToken: 'token-abc' }
    );
    expect(mockLineSendMessage.mock.calls[0]?.[0].messages).toContain('ยืนยันการชำระเงินแล้ว');
    expect(mockLineSendMessage.mock.calls[0]?.[0].messages).toContain('ORD-100');
  });

  it('still routes through sendMessage (whose own internals fall back to push) when the order has no reply token', async () => {
    wireHappyPathDb(ORDER_ROW_NO_REPLY_TOKEN);
    await approvePaymentAction(42);
    expect(mockLineSendMessage).toHaveBeenCalledWith(expect.objectContaining({ replyToken: null }), { channelAccessToken: 'token-abc' });
  });

  it('does NOT notify at all when the order has no line_user_id', async () => {
    wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(42);
    expect(mockLineSendMessage).not.toHaveBeenCalled();
    expect(mockLinePushMessage).not.toHaveBeenCalled();
  });

  it('calls revalidatePath("/shop/orders")', async () => {
    wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(42);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/shop/orders');
  });

  it('performs NO loyalty-points award (no INSERT/UPDATE touching loyalty_points or user points columns)', async () => {
    const { queries } = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(42);
    expect(queries.some((q) => /loyalty_points|available_points|total_points/i.test(q.sql))).toBe(false);
  });
});

describe('cross-file guard-parity check (the acceptance-critical assertion)', () => {
  it("approve_payment's UPDATE has NO line_account_id predicate, while update_status's UPDATE DOES carry AND (line_account_id = ? OR line_account_id IS NULL)", async () => {
    const updateStatusDb = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await updateOrderStatusAction({ orderId: 1, status: 'confirmed' });
    const updateStatusSql = updateStatusDb.queries.find((q) => q.sql.includes('UPDATE transactions SET status ='))!.sql;

    jest.clearAllMocks();

    const approvePaymentDb = wireHappyPathDb(ORDER_ROW_NO_LINE_USER);
    await approvePaymentAction(1);
    const approvePaymentSql = approvePaymentDb.queries.find((q) => q.sql.includes('payment_status'))!.sql;

    expect(updateStatusSql).toContain('AND (line_account_id = ? OR line_account_id IS NULL)');
    expect(approvePaymentSql).not.toContain('line_account_id');
  });
});
