import { SlipVerifier, amountMatches, accountMatches, evaluate, normalize } from './slipVerifier';

/**
 * slipVerifier.test.ts — mirrors tests/Payment/SlipVerifierTest.php's cases
 * (PHPUnit port), one-to-one, per the batch brief's acceptance criteria.
 */

function validSlipBody(amount = 500.0, toAccountNo = '987-6-54321-0'): string {
  return JSON.stringify({
    type: 'SLIP',
    slipVerification: {
      transfer: {
        transactionRef: '202504270001234567',
        transactionDateTime: '2025-04-27T10:30:00+07:00',
        fromBankName: 'SCB',
        fromAccountNo: '123-4-56789-0',
        fromAccountName: 'นาย ตัวอย่าง ทดสอบ',
        toBankName: 'KTB',
        toAccountNo,
        toAccountName: 'นาย ปลายทาง ทดสอบ',
        amount: { amount, currency: { code: 'THB', symbol: '฿' } },
      },
    },
    contact: { website: 'ghostxapi.xyz', telegram: '@ghostx168' },
  });
}

function verifierReturning(status: number, body: string): SlipVerifier {
  return new SlipVerifier('https://test.invalid/qr/scan', async () => ({ status, body }));
}

function offlineVerifier(): SlipVerifier {
  return new SlipVerifier('https://test.invalid/qr/scan', async () => {
    throw new Error('network must not be called');
  });
}

function storedResponse(amount = 500.0, toAccountNo = '987-6-54321-0'): Record<string, unknown> {
  return JSON.parse(validSlipBody(amount, toAccountNo));
}

describe('SlipVerifier', () => {
  it('scan() returns the normalized transfer', async () => {
    const v = verifierReturning(200, validSlipBody());
    const r = await v.scan('QRDATA');

    expect(r.type).toBe('SLIP');
    expect(r.ref).toBe('202504270001234567');
    expect(r.amount).toBe(500.0);
    expect(r.toAccountNo).toBe('987-6-54321-0');
    expect(typeof r.raw).toBe('object');
  });

  it('verify() succeeds when amount and account match', async () => {
    const v = verifierReturning(200, validSlipBody(500.0, '987-6-54321-0'));
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(true);
    expect(r.reason).toBe('ok');
    expect(r.ref).toBe('202504270001234567');
    expect(r.amount).toBe(500.0);
  });

  it('verify() fails on amount mismatch', async () => {
    const v = verifierReturning(200, validSlipBody(499.0, '987-6-54321-0'));
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(false);
    expect(r.reason).toBe('amount_mismatch');
    // Ref is still surfaced so an admin can review the real slip.
    expect(r.ref).toBe('202504270001234567');
  });

  it('verify() fails on account mismatch', async () => {
    const v = verifierReturning(200, validSlipBody(500.0, '111-1-11111-1'));
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(false);
    expect(r.reason).toBe('account_mismatch');
  });

  it('verify() accepts a slip without the top-level "type"', () => {
    // The real GhostX success response may omit the docs-example top-level
    // "type":"SLIP" — a transactionRef in the transfer is the real signal.
    return (async () => {
      const body = JSON.stringify({
        slipVerification: {
          transfer: {
            transactionRef: '202606071Uk8OghbzZ4JYLAQS',
            amount: { amount: 198.0 },
            toAccountNo: '0141111111111',
          },
        },
      });
      const v = verifierReturning(200, body);
      const r = await v.verify('QRDATA', 198.0, ['9876543210'], false);

      expect(r.verified).toBe(true);
      expect(r.ref).toBe('202606071Uk8OghbzZ4JYLAQS');
    })();
  });

  it('verify() fails when not a slip', async () => {
    const v = verifierReturning(200, JSON.stringify({ type: 'UNKNOWN' }));
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(false);
    expect(r.reason).toBe('not_a_slip');
  });

  it('verify() fails gracefully on an HTTP transport error', async () => {
    const v = new SlipVerifier('https://test.invalid/qr/scan', async () => {
      throw new Error('connection refused');
    });
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(false);
    expect(r.reason.startsWith('scan_error')).toBe(true);
  });

  it('verify() fails gracefully on a non-200 with no slip data', async () => {
    const v = verifierReturning(502, 'Bad Gateway');
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(false);
    expect(r.reason.startsWith('scan_error')).toBe(true);
  });

  it('scan() accepts an already-scanned 409 that still carries slip data', async () => {
    const v = verifierReturning(409, validSlipBody(500.0, '987-6-54321-0'));
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(true);
    expect(r.reason).toBe('ok');
  });

  it('scan() still throws on a 409 without slip data', async () => {
    const v = verifierReturning(409, JSON.stringify({ error: 'already used' }));
    const r = await v.verify('QRDATA', 500.0, ['9876543210']);

    expect(r.verified).toBe(false);
    expect(r.reason.startsWith('scan_error')).toBe(true);
  });

  it('verify() matches any of multiple shop accounts', async () => {
    const v = verifierReturning(200, validSlipBody(500.0, '987-6-54321-0'));
    const r = await v.verify('QRDATA', 500.0, ['1112223334', '9876543210', '5556667778']);

    expect(r.verified).toBe(true);
  });

  // --- pure helpers ---------------------------------------------------

  describe('accountMatches', () => {
    it('ignores formatting', () => {
      expect(accountMatches('9876543210', '987-6-54321-0')).toBe(true);
      expect(accountMatches('987-6-54321-0', '9876543210')).toBe(true);
    });

    it('matches a bank-masked slip (position-aligned)', () => {
      // Bank masks leading digits; visible digits align with the real account.
      expect(accountMatches('9876543210', 'xxx-x-x4321-0')).toBe(true);
      expect(accountMatches('9876543210', 'xxx-x-x4329-9')).toBe(false);
    });

    it('rejects a genuinely different account', () => {
      expect(accountMatches('9876543210', '1234567890')).toBe(false);
    });

    it('matches PromptPay phone envelope formats (trailing 9 digits)', () => {
      // Same PromptPay phone in different envelope formats must match.
      expect(accountMatches('0989919556', '66989919556')).toBe(true);
      expect(accountMatches('0989919556', '0066989919556')).toBe(true);
      // A genuinely different destination must still be rejected.
      expect(accountMatches('0989919556', '0141111111111')).toBe(false);
    });

    it('matches an unmasked trailing suffix of >=4 digits either direction', () => {
      // No mask chars on either side (`a` has none) — falls into the plain
      // "trailing suffix, min(len) >= 4" branch (PHP lines 205-208), not the
      // masked branch.
      expect(accountMatches('9876543210', '3210')).toBe(true);
      expect(accountMatches('3210', '9876543210')).toBe(true);
    });

    it('matches a masked slip via the trailing visible-digit run when lengths differ', () => {
      // `a` is shorter than `e` and carries mask chars — different-lengths
      // masked branch (PHP lines 232-236): the trailing run of visible
      // digits ("3210", 4 digits) must match expected's own trailing 4.
      expect(accountMatches('9876543210', '**3210')).toBe(true);
      expect(accountMatches('9876543210', '**3299')).toBe(false);
    });
  });

  describe('amountMatches', () => {
    it('matches to the nearest satang', () => {
      expect(amountMatches(500.0, 500.0)).toBe(true);
      // Sub-satang float drift (e.g. from JSON parsing) is tolerated.
      expect(amountMatches(500.001, 500.0)).toBe(true);
      // A full satang difference is a real mismatch.
      expect(amountMatches(500.01, 500.0)).toBe(false);
      expect(amountMatches(499.99, 500.0)).toBe(false);
    });
  });

  // --- verifyStored: re-evaluate a saved GhostX response, NO new HTTP call --

  describe('verifyStored', () => {
    it('approves without an HTTP call', () => {
      const r = offlineVerifier().verifyStored(storedResponse(500.0, '987-6-54321-0'), 500.0, ['9876543210']);

      expect(r.verified).toBe(true);
      expect(r.reason).toBe('ok');
      expect(r.ref).toBe('202504270001234567');
    });

    it('rejects an amount mismatch without an HTTP call', () => {
      const r = offlineVerifier().verifyStored(storedResponse(499.0, '987-6-54321-0'), 500.0, ['9876543210']);

      expect(r.verified).toBe(false);
      expect(r.reason).toBe('amount_mismatch');
    });

    it('rejects empty/non-slip data', () => {
      const r = offlineVerifier().verifyStored({}, 500.0, ['9876543210']);

      expect(r.verified).toBe(false);
      expect(r.reason).toBe('not_a_slip');
    });
  });

  // --- amount-only mode (requireAccountMatch = false) ---------------------

  describe('amount-only mode', () => {
    it('approves despite an account mismatch', async () => {
      const v = verifierReturning(200, validSlipBody(500.0, '111-1-11111-1'));
      const r = await v.verify('QRDATA', 500.0, ['9876543210'], false);

      expect(r.verified).toBe(true);
      expect(r.reason).toBe('ok');
    });

    it('still rejects an amount mismatch', async () => {
      const v = verifierReturning(200, validSlipBody(499.0, '9876543210'));
      const r = await v.verify('QRDATA', 500.0, ['9876543210'], false);

      expect(r.verified).toBe(false);
      expect(r.reason).toBe('amount_mismatch');
    });

    it('still rejects a non-slip', async () => {
      const v = verifierReturning(200, JSON.stringify({ type: 'UNKNOWN' }));
      const r = await v.verify('QRDATA', 500.0, [], false);

      expect(r.verified).toBe(false);
      expect(r.reason).toBe('not_a_slip');
    });
  });

  // --- evaluate()'s three rejection reasons + the verified/ok path, exercised
  // directly against normalize() output (no HTTP/class involved at all). ---

  describe('evaluate() — the three rejection reasons + the ok path', () => {
    it('not_a_slip: no transactionRef', () => {
      const s = normalize({ type: 'UNKNOWN' });
      const r = evaluate(s, 500.0, ['9876543210']);
      expect(r.reason).toBe('not_a_slip');
      expect(r.verified).toBe(false);
    });

    it('amount_mismatch: ref present, amount differs', () => {
      const s = normalize(storedResponse(499.0, '9876543210'));
      const r = evaluate(s, 500.0, ['9876543210']);
      expect(r.reason).toBe('amount_mismatch');
      expect(r.verified).toBe(false);
    });

    it('account_mismatch: ref+amount ok, no account in shopAccounts matches', () => {
      const s = normalize(storedResponse(500.0, '111-1-11111-1'));
      const r = evaluate(s, 500.0, ['9876543210']);
      expect(r.reason).toBe('account_mismatch');
      expect(r.verified).toBe(false);
    });

    it('ok: ref+amount+account all match', () => {
      const s = normalize(storedResponse(500.0, '9876543210'));
      const r = evaluate(s, 500.0, ['9876543210']);
      expect(r.reason).toBe('ok');
      expect(r.verified).toBe(true);
    });
  });
});
