/**
 * @jest-environment node
 */
import { documentsFetch, documentsInsert, documentsNormItems, type DocumentInsertInput } from './documents';
import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';

const baseDoc: DocumentInsertInput = {
  line_account_id: 7,
  doc_type: 'QT',
  doc_number: 'QT-6905-0001',
  ref_transaction_id: null,
  ref_doc_id: null,
  customer_user_id: null,
  customer_name: 'ทดสอบ',
  customer_tax_id: null,
  customer_branch_code: null,
  customer_address: null,
  customer_phone: null,
  customer_email: null,
  issue_date: '2026-05-24',
  due_date: null,
  valid_until: null,
  subtotal: 100,
  discount_amount: 0,
  vat_rate: 7,
  vat_amount: 7,
  total_amount: 107,
  payment_method: null,
  payment_ref: null,
  status: 'pending_approval',
  note: null,
  internal_note: null,
  created_by: 42,
};

describe('documentsNormItems — line-total math + skip semantics (case documents.php lines 113-157)', () => {
  it('line_total = qty*unitPrice*(1-discountPercent/100) - discountAmount, floored at 0, rounded to 2dp', () => {
    const norm = documentsNormItems(
      [{ product_name: 'ยาพารา', quantity: 3, unit_price: 25, discount_percent: 10, discount_amount: 2 }],
      7,
      false
    );
    expect(norm.items).toHaveLength(1);
    // gross = 3*25 = 75; after 10% disc = 67.5; minus 2 = 65.5
    expect(norm.items[0]?.line_total).toBe(65.5);
  });

  it('floors at 0 when discount exceeds gross', () => {
    const norm = documentsNormItems([{ product_name: 'X', quantity: 1, unit_price: 5, discount_amount: 1000 }], 7, false);
    expect(norm.items[0]?.line_total).toBe(0);
  });

  it('an item with a blank product_name (after trim) is silently skipped, not an error', () => {
    const norm = documentsNormItems(
      [
        { product_name: '   ', quantity: 1, unit_price: 10 },
        { product_name: '', quantity: 1, unit_price: 10 },
        { quantity: 1, unit_price: 10 }, // product_name entirely absent
        { product_name: 'Valid', quantity: 1, unit_price: 10 },
      ],
      7,
      false
    );
    expect(norm.items).toHaveLength(1);
    expect(norm.items[0]?.product_name).toBe('Valid');
    expect(norm.items[0]?.line_no).toBe(1); // skipped rows never consume a line_no
  });

  it('an item with quantity<=0 is silently skipped, not an error', () => {
    const norm = documentsNormItems(
      [
        { product_name: 'Zero qty', quantity: 0, unit_price: 10 },
        { product_name: 'Negative qty', quantity: -5, unit_price: 10 },
        { product_name: 'Kept', quantity: 1, unit_price: 10 },
      ],
      7,
      false
    );
    expect(norm.items).toHaveLength(1);
    expect(norm.items[0]?.product_name).toBe('Kept');
  });

  it('quantity defaults to 1 when absent (PHP\'s `?? 1`, not `?? 0`) — an item with no quantity key survives', () => {
    const norm = documentsNormItems([{ product_name: 'No qty key' }], 7, false);
    expect(norm.items).toHaveLength(1);
    expect(norm.items[0]?.quantity).toBe(1);
  });

  it('quantity explicitly 0 is NOT defaulted to 1 (PHP\'s ?? only triggers on null/unset, not on 0) and is skipped', () => {
    const norm = documentsNormItems([{ product_name: 'Explicit zero', quantity: 0 }], 7, false);
    expect(norm.items).toHaveLength(0);
  });

  it('line_no is 1-based and only increments for kept items', () => {
    const norm = documentsNormItems(
      [
        { product_name: 'A', quantity: 1, unit_price: 1 },
        { product_name: '', quantity: 1, unit_price: 1 }, // skipped
        { product_name: 'B', quantity: 1, unit_price: 1 },
      ],
      7,
      false
    );
    expect(norm.items.map((i) => i.line_no)).toEqual([1, 2]);
  });

  it('subtotal/discount_amount/vat_amount/total_amount are derived via calcVAT over the sum of kept items', () => {
    const norm = documentsNormItems(
      [
        { product_name: 'A', quantity: 2, unit_price: 100 }, // gross 200, no discount
        { product_name: 'B', quantity: 1, unit_price: 50, discount_percent: 10 }, // gross 50 -> 45, discount 5
      ],
      7,
      false
    );
    expect(norm.subtotal).toBe(250); // 200 + 50 (raw qty*unitPrice sum, pre-discount)
    expect(norm.discount_amount).toBe(5);
    // base = 250 - 5 = 245; vat = round(245*0.07,2) = 17.15; total = 262.15
    expect(norm.vat_amount).toBeCloseTo(17.15, 2);
    expect(norm.total_amount).toBeCloseTo(262.15, 2);
  });

  it('property: 200 random item sets never throw and every kept item has line_total >= 0', () => {
    function mulberry32(seed: number): () => number {
      let a = seed;
      return () => {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
      };
    }
    const rand = mulberry32(2026);
    for (let i = 0; i < 200; i++) {
      const rawItems = Array.from({ length: 1 + Math.floor(rand() * 5) }, (_, idx) => ({
        product_name: rand() > 0.1 ? `Item ${idx}` : '', // ~10% blank names
        quantity: Math.round((rand() * 20 - 5) * 100) / 100, // includes negative/zero
        unit_price: Math.round(rand() * 100000) / 100,
        discount_percent: Math.round(rand() * 100) / 10,
        discount_amount: Math.round(rand() * 10000) / 100,
      }));
      const norm = documentsNormItems(rawItems, 7, rand() > 0.5);
      for (const item of norm.items) {
        expect(item.line_total).toBeGreaterThanOrEqual(0);
        expect(item.quantity).toBeGreaterThan(0);
        expect(item.product_name).not.toBe('');
      }
    }
  });
});

describe('documentsFetch — port of documents_fetch() (api/documents.php lines 84-98)', () => {
  it('returns null when no row matches id+line_account_id', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await documentsFetch(db, 1, 999);
    expect(result).toBeNull();
  });

  it('scopes the lookup by BOTH id and line_account_id, and fetches items ordered by line_no,id', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (/from\s+`?business_documents`?/i.test(sqlText)) {
        return [
          {
            id: 5,
            line_account_id: 7,
            doc_type: 'QT',
            doc_number: 'QT-6905-0001',
            status: 'pending_approval',
            issue_date: new Date(2026, 4, 24),
            due_date: null,
            valid_until: null,
            created_at: new Date(2026, 4, 24, 10, 30, 15),
            approved_at: null,
            cancelled_at: null,
            updated_at: new Date(2026, 4, 24, 10, 30, 15),
            subtotal: '100.00',
            discount_amount: '0.00',
            vat_rate: '7.00',
            vat_amount: '7.00',
            total_amount: '107.00',
          },
        ];
      }
      if (/from\s+`?business_document_items`?/i.test(sqlText)) {
        return [{ id: 1, document_id: 5, line_no: 1, product_name: 'A' }];
      }
      return [];
    });

    const result = await documentsFetch(db, 7, 5);
    expect(result).not.toBeNull();
    expect(result!.id).toBe(5);
    expect(result!.items).toHaveLength(1);
    // Wire shape: date columns are plain PHP-style strings, not Date objects.
    expect(result!.issue_date).toBe('2026-05-24');
    expect(result!.created_at).toBe('2026-05-24 10:30:15');
    expect(result!.due_date).toBeNull();

    const docsQuery = queries.find((q) => /from\s+`?business_documents`?/i.test(q.sql));
    expect(docsQuery!.params).toEqual([5, 7]);

    const itemsQuery = queries.find((q) => /from\s+`?business_document_items`?/i.test(q.sql));
    expect(itemsQuery!.sql.toLowerCase()).toContain('order by');
    expect(itemsQuery!.sql.toLowerCase()).toContain('line_no');
    expect(itemsQuery!.params).toEqual([5]);
  });
});

describe('documentsInsert — port of documents_insert() (api/documents.php lines 159-204)', () => {
  it('inserts into business_documents then business_document_items, returning the new id', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (/insert into\s+`?business_documents`?/i.test(sqlText)) return { insertId: 501, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });

    const items = [
      {
        line_no: 1,
        product_id: null,
        product_sku: null,
        product_name: 'ยา A',
        description: null,
        quantity: 1,
        unit: null,
        unit_price: 100,
        discount_percent: 0,
        discount_amount: 0,
        line_total: 100,
      },
    ];

    const docId = await documentsInsert(db, baseDoc, items);
    expect(docId).toBe(501);

    const docInsert = queries.find((q) => /business_documents/i.test(q.sql));
    expect(docInsert!.params).toEqual([
      7, 'QT', 'QT-6905-0001', null, null,
      null, 'ทดสอบ', null, null,
      null, null, null,
      '2026-05-24', null, null,
      100, 0, 7, 7, 107,
      null, null,
      'pending_approval', null, null, 42,
    ]);

    const itemInsert = queries.find((q) => /business_document_items/i.test(q.sql));
    expect(itemInsert).toBeDefined();
    expect(itemInsert!.params).toEqual([501, 1, null, null, 'ยา A', null, 1, null, 100, 0, 0, 100]);
  });

  it('does not attempt a business_document_items insert when items is empty', async () => {
    const { db, queries } = makeFakeTenantDb(() => ({ insertId: 900, affectedRows: 1 }));
    const docId = await documentsInsert(db, baseDoc, []);
    expect(docId).toBe(900);
    expect(queries.some((q) => /business_document_items/i.test(q.sql))).toBe(false);
  });

  it('inserts one row per item, in order, for multiple items', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (/insert into\s+`?business_documents`?/i.test(sqlText)) return { insertId: 10, affectedRows: 1 };
      return { insertId: 0, affectedRows: 1 };
    });
    const items = [1, 2, 3].map((n) => ({
      line_no: n,
      product_id: null,
      product_sku: null,
      product_name: `Item ${n}`,
      description: null,
      quantity: 1,
      unit: null,
      unit_price: 10,
      discount_percent: 0,
      discount_amount: 0,
      line_total: 10,
    }));
    await documentsInsert(db, baseDoc, items);
    const itemInserts = queries.filter((q) => /business_document_items/i.test(q.sql));
    expect(itemInserts).toHaveLength(3);
    expect(itemInserts.map((q) => q.params[4])).toEqual(['Item 1', 'Item 2', 'Item 3']);
  });
});

describe('documentsInsert — atomicity via a shared transaction (mirrors route.ts create)', () => {
  it('when the business_documents insert throws, the transaction rejects and no query after it ever runs', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (/^(begin|commit|rollback)/i.test(sqlText.trim())) return {};
      if (/insert into\s+`?business_documents`?/i.test(sqlText)) throw new Error('simulated insert failure');
      throw new Error(`unexpected: ${sqlText}`);
    });

    await expect(
      db.transaction().execute(async (trx) => {
        return documentsInsert(trx, baseDoc, []);
      })
    ).rejects.toThrow('simulated insert failure');

    const controlStatements = queries.filter((q) => /^(begin|commit|rollback)/i.test(q.sql.trim())).map((q) => q.sql.trim().toLowerCase());
    expect(controlStatements).toEqual(['begin', 'rollback']);
  });
});
