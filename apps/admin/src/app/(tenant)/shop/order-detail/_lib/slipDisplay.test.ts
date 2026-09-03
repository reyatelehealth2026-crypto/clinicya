import { computeSlipDisplay } from './slipDisplay';

const SHOP_ACCOUNTS = ['9876543210'];

function verifyData(overrides: Record<string, unknown> = {}) {
  return JSON.stringify({
    slipVerification: {
      transfer: {
        transactionRef: 'REF-1',
        transactionDateTime: '2026-08-14T10:00:00+07:00',
        fromAccountName: 'นาย ก.',
        fromBankName: 'SCB',
        toAccountNo: '987-6-54321-0',
        amount: { amount: 500.0 },
      },
    },
    ...overrides,
  });
}

describe('computeSlipDisplay', () => {
  it('extracts transfer fields, amountOk, and accountOk when the amounts/accounts match', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: 'QR', verifyData: verifyData() }, 500.0, SHOP_ACCOUNTS);
    expect(d.transfer).not.toBeNull();
    expect(d.transfer!.amount).toBe(500.0);
    expect(d.transfer!.toAccountNo).toBe('987-6-54321-0');
    expect(d.transfer!.fromName).toBe('นาย ก.');
    expect(d.transfer!.transactionRef).toBe('REF-1');
    expect(d.amountOk).toBe(true);
    expect(d.accountOk).toBe(true);
    expect(d.ghostxError).toBeNull();
  });

  it('falls back to fromBankName when fromAccountName is absent', () => {
    const raw = JSON.parse(verifyData());
    delete raw.slipVerification.transfer.fromAccountName;
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: JSON.stringify(raw) }, 500.0, SHOP_ACCOUNTS);
    expect(d.transfer!.fromName).toBe('SCB');
  });

  it('falls back transactionRef to the stored verify_ref when the transfer carries none', () => {
    const raw = JSON.parse(verifyData());
    delete raw.slipVerification.transfer.transactionRef;
    const d = computeSlipDisplay({ verifyRef: 'STORED-REF', qrPayload: null, verifyData: JSON.stringify(raw) }, 500.0, SHOP_ACCOUNTS);
    expect(d.transfer!.transactionRef).toBe('STORED-REF');
  });

  it('flags amountOk=false on a mismatched amount', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: verifyData() }, 499.0, SHOP_ACCOUNTS);
    expect(d.amountOk).toBe(false);
  });

  it('flags accountOk=false when no shop account matches', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: verifyData() }, 500.0, ['0000000000']);
    expect(d.accountOk).toBe(false);
  });

  it('surfaces a GhostX error message ONLY when there is no transfer', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: JSON.stringify({ error: 'ไม่มีรหัสอ้างอิงรายการ' }) }, 500.0, SHOP_ACCOUNTS);
    expect(d.transfer).toBeNull();
    expect(d.ghostxError).toBe('ไม่มีรหัสอ้างอิงรายการ');
  });

  it('does NOT surface an error field when a transfer IS present', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: verifyData({ error: 'should be ignored' }) }, 500.0, SHOP_ACCOUNTS);
    expect(d.ghostxError).toBeNull();
  });

  it('treats an empty string / "0" error as no error (PHP empty() semantics)', () => {
    expect(computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: JSON.stringify({ error: '' }) }, 500.0, []).ghostxError).toBeNull();
    expect(computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: JSON.stringify({ error: '0' }) }, 500.0, []).ghostxError).toBeNull();
  });

  it('returns transfer=null and ghostxError=null when verify_data is empty/null', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: 'QR', verifyData: null }, 500.0, SHOP_ACCOUNTS);
    expect(d.transfer).toBeNull();
    expect(d.ghostxError).toBeNull();
    expect(d.amountOk).toBe(false);
    expect(d.accountOk).toBe(false);
  });

  it('degrades gracefully on malformed JSON', () => {
    const d = computeSlipDisplay({ verifyRef: null, qrPayload: null, verifyData: 'not json' }, 500.0, SHOP_ACCOUNTS);
    expect(d.transfer).toBeNull();
    expect(d.ghostxError).toBeNull();
  });
});
