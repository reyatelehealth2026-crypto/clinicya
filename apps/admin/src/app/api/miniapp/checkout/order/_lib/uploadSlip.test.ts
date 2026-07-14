/**
 * @jest-environment node
 */
jest.mock('./notify', () => ({
  sendReceiptMessage: jest.fn().mockResolvedValue(true),
  notifyTelegramPayment: jest.fn().mockResolvedValue(true),
}));

import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { handleUploadSlip } from './uploadSlip';
import { notifyTelegramPayment, sendReceiptMessage } from './notify';

function setup(queryImpl: QueryImpl) {
  return makeFakeKyselyDb<TenantDB>(queryImpl);
}

const mockSendReceipt = sendReceiptMessage as jest.Mock;
const mockNotifyPayment = notifyTelegramPayment as jest.Mock;

let tmpDir: string;

beforeEach(() => {
  tmpDir = mkdtempSync(path.join(tmpdir(), 'checkout-slips-'));
  process.env.CHECKOUT_SLIPS_UPLOAD_DIR = tmpDir;
  mockSendReceipt.mockClear();
  mockNotifyPayment.mockClear();
  mockSendReceipt.mockResolvedValue(true);
  mockNotifyPayment.mockResolvedValue(true);
});

afterEach(() => {
  delete process.env.CHECKOUT_SLIPS_UPLOAD_DIR;
  rmSync(tmpDir, { recursive: true, force: true });
});

function makeForm(fields: Record<string, string>, file?: { name: string; type: string; bytes: number }): FormData {
  const form = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    form.append(k, v);
  }
  if (file) {
    const content = new Uint8Array(file.bytes).fill(1);
    form.append('slip', new File([content], file.name, { type: file.type }));
  }
  return form;
}

describe('handleUploadSlip — validation (L1741-1779)', () => {
  it('Order ID required when order_id is missing', async () => {
    const { db } = setup(() => []);
    const result = await handleUploadSlip(db, makeForm({}), 'https://tenant.example.com');
    expect(result.body).toEqual({ success: false, message: 'Order ID required' });
  });

  it('No file uploaded when the slip field is absent', async () => {
    const { db } = setup(() => []);
    const result = await handleUploadSlip(db, makeForm({ order_id: '900' }), 'https://tenant.example.com');
    expect(result.body).toEqual({ success: false, message: 'No file uploaded' });
  });

  it('Invalid file type for a disallowed MIME -> matches fixtures/checkout-order/upload-slip-invalid-mime.json', async () => {
    const { db } = setup(() => []);
    const form = makeForm({ order_id: '900' }, { name: 'slip.pdf', type: 'application/pdf', bytes: 100 });
    const result = await handleUploadSlip(db, form, 'https://tenant.example.com');
    expect(result.body).toEqual({ success: false, message: 'Invalid file type' });
  });

  it('File too large (max 5MB)', async () => {
    const { db } = setup(() => []);
    const form = makeForm({ order_id: '900' }, { name: 'slip.jpg', type: 'image/jpeg', bytes: 5 * 1024 * 1024 + 1 });
    const result = await handleUploadSlip(db, form, 'https://tenant.example.com');
    expect(result.body).toEqual({ success: false, message: 'File too large (max 5MB)' });
  });

  it('Order not found -> matches fixtures/checkout-order/upload-slip-order-not-found.json', async () => {
    const { db } = setup(() => []);
    const form = makeForm({ order_id: '999999' }, { name: 'slip.jpg', type: 'image/jpeg', bytes: 100 });
    const result = await handleUploadSlip(db, form, 'https://tenant.example.com');
    expect(result.body).toEqual({ success: false, message: 'Order not found' });
  });
});

describe('handleUploadSlip — happy path (L1781-1863)', () => {
  it('writes the file to CHECKOUT_SLIPS_UPLOAD_DIR, inserts payment_slips (Level 1 only), builds image_url from the incoming origin -> matches fixtures/checkout-order/upload-slip-ok.json', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('FROM transactions WHERE id')) {
        return [
          {
            id: 900,
            order_number: 'TXN202607140001',
            user_id: 42,
            total_amount: '100',
            shipping_fee: '40',
            grand_total: '140',
            delivery_info: null,
          },
        ];
      }
      if (sqlText.includes('INSERT INTO payment_slips')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('UPDATE transactions SET updated_at')) return { affectedRows: 1 };
      if (sqlText.includes('SELECT display_name FROM users')) return [{ display_name: 'สมชาย' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    // qr_data/user_id are sent by the real client but accepted-and-ignored (grep-verified: neither is
    // ever read anywhere in api/checkout.php).
    const form = makeForm({ order_id: '900', user_id: '42', qr_data: 'ignored-qr-payload' }, { name: 'slip.jpg', type: 'image/jpeg', bytes: 100 });
    const result = await handleUploadSlip(db, form, 'https://tenant-1234.re-ya.com');

    expect(result.status).toBe(200);
    expect(result.body.success).toBe(true);
    expect(result.body.message).toBe('Slip uploaded');
    const imageUrl = result.body.image_url as string;
    expect(imageUrl).toMatch(/^https:\/\/tenant-1234\.re-ya\.com\/uploads\/slips\/slip_TXN202607140001_\d+\.jpg$/);

    // File was actually written to the resolved (env-overridden) upload dir — same physical location PHP
    // writes to, just parameterized for the test.
    const filename = imageUrl.split('/').pop()!;
    const written = readFileSync(path.join(tmpDir, filename));
    expect(written.length).toBe(100);

    const insert = queries.find((q) => q.sql.includes('INSERT INTO payment_slips'));
    // 'pending' is a literal in the SQL text (not a bound param), matching PHP's own
    // `VALUES (?, ?, ?, ?, 'pending')` — only 4 bound params.
    expect(insert?.sql).toContain("'pending'");
    expect(insert?.params).toEqual([900, 900, 42, imageUrl]);

    expect(mockSendReceipt).toHaveBeenCalledTimes(1);
    expect(mockNotifyPayment).toHaveBeenCalledWith(db, 900, 'TXN202607140001', imageUrl, { display_name: 'สมชาย' });
  });

  it('never sets payment_status=paid/status=completed on the transactions UPDATE — only touches updated_at (L1829-1835)', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('FROM transactions WHERE id')) {
        return [{ id: 901, order_number: 'TXN2', user_id: 1, total_amount: '1', shipping_fee: '0', grand_total: '1', delivery_info: null }];
      }
      if (sqlText.includes('INSERT INTO payment_slips')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('UPDATE transactions SET updated_at')) return { affectedRows: 1 };
      if (sqlText.includes('SELECT display_name FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const form = makeForm({ order_id: '901' }, { name: 'slip.png', type: 'image/png', bytes: 10 });
    await handleUploadSlip(db, form, 'https://tenant.example.com');

    const txUpdate = queries.find((q) => q.sql.trim().startsWith('UPDATE transactions'));
    expect(txUpdate?.sql).not.toMatch(/payment_status|status\s*=\s*'(paid|completed)'/i);
    // No matching users row -> slipUser falls back to {} (PHP: `$slipUser ?: []`).
    expect(mockNotifyPayment).toHaveBeenCalledWith(db, 901, 'TXN2', expect.any(String), {});
  });

  it('extension falls back to jpg when the uploaded filename has none', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('FROM transactions WHERE id')) {
        return [{ id: 902, order_number: 'TXN3', user_id: 1, total_amount: '1', shipping_fee: '0', grand_total: '1', delivery_info: null }];
      }
      if (sqlText.includes('INSERT INTO payment_slips')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('UPDATE transactions SET updated_at')) return { affectedRows: 1 };
      if (sqlText.includes('SELECT display_name FROM users')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const form = makeForm({ order_id: '902' }, { name: 'noextension', type: 'image/webp', bytes: 5 });
    const result = await handleUploadSlip(db, form, 'https://tenant.example.com');
    expect(result.body.image_url).toMatch(/\.jpg$/);
  });
});
