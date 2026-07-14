/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { handleValidatePromo, validateHardcodedPromo } from './handlers';

function setup(queryImpl: QueryImpl) {
  return makeFakeKyselyDb<TenantDB>(queryImpl);
}

/** Every test here expects `SHOW TABLES LIKE 'promotions'` to be probed first — matched loosely by the SQL text. */
const NO_PROMOTIONS_TABLE: QueryImpl = (sqlText) => {
  if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [];
  throw new Error(`unexpected query: ${sqlText}`);
};

describe('validateHardcodedPromo (pure)', () => {
  it('WELCOME10: 10% off, min order 100, no cap', () => {
    expect(validateHardcodedPromo('WELCOME10', 200)).toBe(20);
    expect(validateHardcodedPromo('WELCOME10', 99)).toBe(0);
  });
  it('SAVE50: fixed 50, min order 300', () => {
    expect(validateHardcodedPromo('SAVE50', 300)).toBe(50);
    expect(validateHardcodedPromo('SAVE50', 299)).toBe(0);
  });
  it('FREESHIP: fixed 50, no minimum, but capped by subtotal (min(discount, subtotal))', () => {
    expect(validateHardcodedPromo('FREESHIP', 1000)).toBe(50);
    expect(validateHardcodedPromo('FREESHIP', 10)).toBe(10);
    expect(validateHardcodedPromo('FREESHIP', 0)).toBe(0);
  });
  it('NEWUSER: 15% off, min order 200, capped at 100', () => {
    expect(validateHardcodedPromo('NEWUSER', 1000)).toBe(100); // 150 capped to 100
    expect(validateHardcodedPromo('NEWUSER', 500)).toBe(75); // 15% of 500 = 75, under cap
    expect(validateHardcodedPromo('NEWUSER', 199)).toBe(0);
  });
  it('unknown code -> 0', () => {
    expect(validateHardcodedPromo('NOPE', 1000)).toBe(0);
  });
});

describe('handleValidatePromo — no promotions table (committed template) -> validateHardcodedPromo fallback', () => {
  it('WELCOME10 valid -> matches fixtures/checkout-cart/validate-promo-hardcoded-welcome10.json, discount_type hardcoded "fixed"', async () => {
    const { db } = setup(NO_PROMOTIONS_TABLE);
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'welcome10', line_user_id: 'U8888888888888888888888888888bb', subtotal: 200 });
    expect(result.body).toEqual({
      success: true,
      message: 'โค้ดถูกต้อง',
      valid: true,
      discount: 20,
      discount_type: 'fixed',
      code: 'WELCOME10',
    });
  });

  it('unknown code -> matches fixtures/checkout-cart/validate-promo-invalid-code.json', async () => {
    const { db } = setup(NO_PROMOTIONS_TABLE);
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'BADCODE', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'โค้ดไม่ถูกต้องหรือหมดอายุ', valid: false });
  });

  it('known code below minimum -> generic invalid message, NOT the min-order message (matches fixtures/checkout-cart/validate-promo-below-minimum.json)', async () => {
    const { db } = setup(NO_PROMOTIONS_TABLE);
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'SAVE50', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'โค้ดไม่ถูกต้องหรือหมดอายุ', valid: false });
  });

  it('missing/empty code -> validation failure before the table probe even runs', async () => {
    const { db, queries } = setup(() => {
      throw new Error('should not query');
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: '  ' });
    expect(result.body).toEqual({ success: false, message: 'กรุณากรอกโค้ดส่วนลด', valid: false });
    expect(queries).toHaveLength(0);
  });

  it('a thrown query error anywhere -> generic "ไม่สามารถตรวจสอบโค้ดได้" failure (outer try/catch)', async () => {
    const { db } = setup(() => {
      throw new Error('DB exploded');
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'WELCOME10', subtotal: 200 });
    expect(result.body).toEqual({ success: false, message: 'ไม่สามารถตรวจสอบโค้ดได้', valid: false });
  });
});

describe('handleValidatePromo — promotions table present (not reachable on the committed template, but preserved faithfully)', () => {
  const PROMO_ROW = {
    id: 5,
    name: 'ลด 20%',
    code: 'DBCODE',
    is_active: 1,
    line_account_id: 1,
    start_date: null,
    end_date: null,
    min_order_amount: '0',
    usage_limit: null,
    usage_count: 0,
    per_user_limit: null,
    discount_type: 'percentage',
    discount_value: '20',
    max_discount: '150',
  };

  it('valid DB-driven percentage promo with a max_discount cap', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [PROMO_ROW];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'dbcode', subtotal: 1000 });
    // 20% of 1000 = 200, capped to max_discount 150
    expect(result.body).toEqual({
      success: true,
      message: 'โค้ดถูกต้อง',
      valid: true,
      discount: 150,
      discount_type: 'percentage',
      discount_value: 20,
      code: 'DBCODE',
      promo_id: 5,
      promo_name: 'ลด 20%',
    });
  });

  it('promo code not found in the table -> โค้ดไม่ถูกต้อง', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'NOPE', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'โค้ดไม่ถูกต้อง', valid: false });
  });

  it('subtotal below min_order_amount -> the specific min-order message (number_format thousands separator)', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [{ ...PROMO_ROW, min_order_amount: '1500' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'DBCODE', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'ยอดสั่งซื้อขั้นต่ำ ฿1,500', valid: false });
  });

  it('usage_limit reached -> โค้ดถูกใช้ครบจำนวนแล้ว', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [{ ...PROMO_ROW, usage_limit: 10, usage_count: 10 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'DBCODE', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'โค้ดถูกใช้ครบจำนวนแล้ว', valid: false });
  });

  it('per_user_limit reached -> คุณใช้โค้ดนี้ครบจำนวนแล้ว', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [{ ...PROMO_ROW, per_user_limit: 1 }];
      if (sqlText.includes('FROM promotion_usage')) return [{ 'COUNT(*)': 1 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'DBCODE', line_user_id: 'U1', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'คุณใช้โค้ดนี้ครบจำนวนแล้ว', valid: false });
  });

  it('start_date in the future -> โค้ดยังไม่เริ่มใช้งาน', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [{ ...PROMO_ROW, start_date: '2099-01-01 00:00:00' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'DBCODE', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'โค้ดยังไม่เริ่มใช้งาน', valid: false });
  });

  it('end_date in the past -> โค้ดหมดอายุแล้ว', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions')) return [{ ...PROMO_ROW, end_date: '2000-01-01 00:00:00' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'DBCODE', subtotal: 100 });
    expect(result.body).toEqual({ success: false, message: 'โค้ดหมดอายุแล้ว', valid: false });
  });

  it('fixed discount_type is capped by subtotal (min(discount, subtotal))', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes("SHOW TABLES LIKE 'promotions'")) return [{ Tables_in_db: 'promotions' }];
      if (sqlText.includes('FROM promotions'))
        return [{ ...PROMO_ROW, discount_type: 'fixed', discount_value: '500', max_discount: null }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleValidatePromo(db, { action: 'validate_promo', code: 'DBCODE', subtotal: 80 });
    expect(result.body).toMatchObject({ success: true, discount: 80, discount_type: 'fixed' });
  });
});
