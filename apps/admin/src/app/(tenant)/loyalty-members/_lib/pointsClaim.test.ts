import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { pcNormalizePhone, pcIsLineUser, pcNormalizePayment, giveByPhone, getMemberDetail } from './pointsClaim';

describe('pcNormalizePhone', () => {
  it('strips non-digit characters', () => {
    expect(pcNormalizePhone('081-234-5678')).toBe('0812345678');
  });
  it('rewrites a leading 66 (11 digits) to a leading 0', () => {
    expect(pcNormalizePhone('+66812345678')).toBe('0812345678');
  });
  it('leaves an already-local number untouched', () => {
    expect(pcNormalizePhone('0812345678')).toBe('0812345678');
  });
});

describe('pcIsLineUser', () => {
  it('is false for an offline: ghost line_user_id', () => {
    expect(pcIsLineUser('offline:0812345678')).toBe(false);
  });
  it('is true for a real LINE user id', () => {
    expect(pcIsLineUser('U1234567890abcdef')).toBe(true);
  });
  it('is true for null/undefined (matches PHP strpos(...) !== 0 on an empty string)', () => {
    expect(pcIsLineUser(null)).toBe(true);
    expect(pcIsLineUser(undefined)).toBe(true);
  });
});

describe('pcNormalizePayment', () => {
  it('accepts the 4 allowed methods case-insensitively', () => {
    expect(pcNormalizePayment('Cash')).toBe('cash');
    expect(pcNormalizePayment(' transfer ')).toBe('transfer');
    expect(pcNormalizePayment('CARD')).toBe('card');
    expect(pcNormalizePayment('qr')).toBe('qr');
  });
  it('returns null for anything else', () => {
    expect(pcNormalizePayment('bitcoin')).toBeNull();
    expect(pcNormalizePayment('')).toBeNull();
  });
});

describe('giveByPhone — validation (no DB writes)', () => {
  it('rejects a phone shorter than 8 digits', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await giveByPhone(db, { lineAccountId: 1, adminUserId: 1, phone: '0812', name: '', userId: 0, amount: 100, points: 0, paymentMethod: '' });
    expect(result.success).toBe(false);
    expect(result.message).toMatch(/เบอร์/);
    // Phone validation short-circuits before any query — the schema is owned by
    // database/migration_2026-06-02_points_claims.sql, not by a runtime CREATE TABLE.
    expect(queries).toHaveLength(0);
  });

  it('rejects negative amount', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await giveByPhone(db, { lineAccountId: 1, adminUserId: 1, phone: '0812345678', name: '', userId: 0, amount: -1, points: 0, paymentMethod: '' });
    expect(result.success).toBe(false);
  });

  it('rejects when neither amount nor points is given', async () => {
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('points_settings') ? [] : []));
    const result = await giveByPhone(db, { lineAccountId: 1, adminUserId: 1, phone: '0812345678', name: '', userId: 0, amount: 0, points: 0, paymentMethod: '' });
    expect(result.success).toBe(false);
    expect(result.message).toMatch(/ยอดเงินหรือแต้ม/);
  });

  it('rejects when calculatePoints() from a positive amount rounds down to 0 points', async () => {
    const { db } = makeFakeTenantDb((sqlText) =>
      sqlText.includes('points_settings') ? [{ pointsPerBaht: 0, minOrderForPoints: 0, pointsExpiryDays: 365, isActive: 1 }] : []
    );
    const result = await giveByPhone(db, { lineAccountId: 1, adminUserId: 1, phone: '0812345678', name: '', userId: 0, amount: 10, points: 0, paymentMethod: '' });
    expect(result.success).toBe(false);
    expect(result.message).toMatch(/มากกว่า 0/);
  });
});

describe('giveByPhone — happy path (explicit points, brand-new phone ghost)', () => {
  it('creates a new offline ghost, credits points transactionally, and returns the expected shape', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('ORDER BY (line_user_id')) {
        return []; // no existing matches for this phone
      }
      if (sqlText.includes('INSERT INTO users')) {
        // mysql2 "OkPacket" shape — Kysely's driver only reads insertId/affectedRows off an
        // object matching this shape (see mysql-driver.js's isOkPacket()), not a rows array.
        return { insertId: 501, affectedRows: 1 };
      }
      if (sqlText.includes('INSERT INTO points_claims')) {
        return { insertId: 9001, affectedRows: 1 };
      }
      if (sqlText.includes('WHERE id = ?') && sqlText.includes('LIMIT 1') && sqlText.includes('available_points')) {
        return [{ id: 501, line_user_id: 'offline:0812345678', display_name: 'ลูกค้า 5678', real_name: null, first_name: null, last_name: null, available_points: 0 }];
      }
      if (sqlText.includes('points_transactions') && sqlText.includes('reference_type')) {
        return [{ id: 9001 }];
      }
      return [];
    });

    const result = await giveByPhone(db, {
      lineAccountId: 7,
      adminUserId: 3,
      phone: '081-234-5678',
      name: '',
      userId: 0,
      amount: 0,
      points: 20,
      paymentMethod: 'cash',
    });

    expect(result.success).toBe(true);
    expect(result.points).toBe(20);
    expect(result.isNew).toBe(true);
    expect(result.hasLine).toBe(false);
    expect(result.voucherNo).toMatch(/^WI\d{8}-\d{3}$/);

    // A transaction ran (BEGIN/COMMIT bracket the points_claims insert + addPoints writes).
    expect(queries.some((q) => q.sql.includes('INSERT INTO points_claims'))).toBe(true);
    expect(queries.some((q) => q.sql.includes('UPDATE users SET total_points'))).toBe(true);
    expect(queries.some((q) => q.sql.includes("INSERT INTO points_transactions"))).toBe(true);
  });
});

describe('getMemberDetail', () => {
  it('rejects missing/invalid ids without querying', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    const result = await getMemberDetail(db, 0, 5);
    expect(result.success).toBe(false);
    expect(queries).toHaveLength(0);
  });

  it('returns not-found when no matching user row exists', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getMemberDetail(db, 1, 999);
    expect(result.success).toBe(false);
    expect(result.message).toMatch(/ไม่พบลูกค้า/);
  });

  it('returns the customer summary + transaction ledger', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id = ?')) {
        return [
          {
            id: 5,
            line_user_id: 'offline:0812345678',
            display_name: 'ลูกค้า',
            real_name: 'สมศรี ใจดี',
            first_name: null,
            last_name: null,
            phone: '0812345678',
            available_points: 100,
            total_points: 150,
            used_points: 50,
            created_at: new Date('2026-01-01T00:00:00Z'),
          },
        ];
      }
      if (sqlText.includes('FROM points_transactions')) {
        return [{ type: 'earn', points: 20, balance_after: 100, description: 'รับแต้มจากการซื้อหน้าร้าน #WI20260101-001', created_at: new Date('2026-01-02T00:00:00Z') }];
      }
      return [];
    });

    const result = await getMemberDetail(db, 1, 5);
    expect(result.success).toBe(true);
    expect(result.customer).toMatchObject({ userId: 5, name: 'สมศรี ใจดี', phone: '0812345678', hasLine: false, availablePoints: 100 });
    expect(result.transactions).toHaveLength(1);
    expect(result.transactions?.[0]).toMatchObject({ type: 'earn', points: 20, balanceAfter: 100 });
  });
});
