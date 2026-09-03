/**
 * slipVerifier.ts — TypeScript port of classes/SlipVerifier.php (262 LOC), the
 * GhostX QR slip-verification client used by the `verify_slip` POST action and
 * by every payment-slip card's GhostX-result panel on this page.
 *
 * Verifies a Thai bank-transfer slip by sending the raw QR payload to the
 * GhostX API (https://externalauth.ghostxapi.xyz/qr/scan) and checking the
 * returned transfer against the expected order amount and shop account.
 *
 * The HTTP transport is injectable (mirrors @reya/line's `LineFetch`
 * injectable-transport pattern in packages/line/src/api.ts) so the decision
 * logic can be unit-tested without hitting the network — same rationale the
 * PHP class's own doc comment gives for its `?callable $httpClient`
 * constructor param.
 *
 * @spec ghostx-slip-verification (see classes/SlipVerifier.php, tests/Payment/SlipVerifierTest.php)
 */

const DEFAULT_ENDPOINT = 'https://externalauth.ghostxapi.xyz/qr/scan';
const DEFAULT_TIMEOUT_MS = 15_000;

// ---------------------------------------------------------------------------
// Injectable HTTP transport — mirrors packages/line/src/api.ts's LineFetch.
// ---------------------------------------------------------------------------

export interface SlipHttpResponse {
  status: number;
  body: string;
}

/** Injectable transport signature. Tests supply a mock satisfying this; production code doesn't need to. */
export type SlipHttpTransport = (url: string, payload: Record<string, unknown>) => Promise<SlipHttpResponse>;

/**
 * Real (non-stubbed) default transport — POSTs JSON via the runtime's global
 * `fetch` (Node 18+, no imports needed). Port of SlipVerifier::defaultHttpPost()
 * (PHP lines 241-261, cURL-based there; fetch-based here — same wire contract:
 * POST, `Content-Type: application/json`, JSON body, returns `{status, body}`).
 */
const defaultHttpPost: SlipHttpTransport = async (url, payload) => {
  const runtimeFetch = (globalThis as unknown as { fetch?: typeof fetch }).fetch;
  if (typeof runtimeFetch !== 'function') {
    throw new Error('[slipVerifier] global fetch is unavailable in this runtime; pass an explicit transport.');
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), DEFAULT_TIMEOUT_MS);
  try {
    const res = await runtimeFetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      signal: controller.signal,
    });
    const body = await res.text();
    return { status: res.status, body };
  } finally {
    clearTimeout(timer);
  }
};

// ---------------------------------------------------------------------------
// Normalized shapes — port of SlipVerifier::normalize()'s return shape.
// ---------------------------------------------------------------------------

export interface NormalizedSlip {
  type: string | null;
  ref: string | null;
  amount: number | null;
  toAccountNo: string | null;
  raw: Record<string, unknown>;
}

export interface VerifyResult {
  verified: boolean;
  reason: string;
  ref: string | null;
  amount: number | null;
  data: Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// normalize() — pure, used by both scan() and verifyStored() (no HTTP).
// Port of SlipVerifier::normalize() (PHP lines 77-88).
// ---------------------------------------------------------------------------

interface GhostxTransfer {
  transactionRef?: unknown;
  amount?: { amount?: unknown };
  toAccountNo?: unknown;
}

interface GhostxResponse {
  type?: unknown;
  slipVerification?: { transfer?: GhostxTransfer };
  [key: string]: unknown;
}

export function normalize(json: Record<string, unknown>): NormalizedSlip {
  const j = json as GhostxResponse;
  const transfer = j.slipVerification?.transfer ?? {};

  const ref = typeof transfer.transactionRef === 'string' ? transfer.transactionRef : null;
  const rawAmount = transfer.amount?.amount;
  const amount = rawAmount !== undefined && rawAmount !== null ? Number(rawAmount) : null;
  const toAccountNo = typeof transfer.toAccountNo === 'string' ? transfer.toAccountNo : null;

  return {
    type: typeof j.type === 'string' ? j.type : null,
    ref,
    amount,
    toAccountNo,
    raw: json,
  };
}

// ---------------------------------------------------------------------------
// amountMatches() / accountMatches() — pure decision helpers.
// Port of SlipVerifier::amountMatches()/::accountMatches() (PHP lines 182-238).
// ---------------------------------------------------------------------------

/** Amounts match when equal to the nearest satang (avoids float epsilon traps). Port of PHP lines 182-185. */
export function amountMatches(expected: number, actual: number): boolean {
  return Math.round(expected * 100) === Math.round(actual * 100);
}

/**
 * Whether a slip's destination account matches the expected account,
 * tolerant of separators and bank-side masking (e.g. "xxx-x-x4321-0").
 * Port of SlipVerifier::accountMatches() (PHP lines 191-238) — branch order
 * preserved exactly.
 */
export function accountMatches(expected: string, actual: string): boolean {
  const e = expected.replace(/\D/g, ''); // expected: digits only
  const a = actual.replace(/[\s-]/g, ''); // actual: drop separators, keep digits + mask chars
  if (e === '' || a === '') {
    return false;
  }

  const hasMask = /\D/.test(a);

  if (!hasMask) {
    if (e === a) {
      return true;
    }
    const min = Math.min(e.length, a.length);
    if (min >= 4 && e.slice(-min) === a.slice(-min)) {
      return true;
    }
    // PromptPay phone numbers appear in many envelope formats
    // (0xxxxxxxxx / 66xxxxxxxxx / 0066xxxxxxxxx) — compare trailing 9
    // digits (the phone without its leading 0) as a fallback.
    if (e.length >= 9 && a.length >= 9 && e.slice(-9) === a.slice(-9)) {
      return true;
    }
    return false;
  }

  // Masked: align position-by-position when lengths match.
  if (a.length === e.length) {
    let visible = 0;
    for (let i = 0; i < a.length; i++) {
      const ch = a[i]!;
      if (ch >= '0' && ch <= '9') {
        visible++;
        if (ch !== e[i]) {
          return false;
        }
      }
    }
    return visible >= 4;
  }

  // Different lengths: compare the trailing run of visible digits.
  const m = /(\d+)$/.exec(a);
  if (m) {
    const vis = m[1]!;
    return vis.length >= 4 && e.slice(-vis.length) === vis;
  }
  return false;
}

// ---------------------------------------------------------------------------
// evaluate() — decision logic over a normalized scan result. Pure — no HTTP.
// Port of SlipVerifier::evaluate() (PHP lines 136-179).
// ---------------------------------------------------------------------------

export function evaluate(
  s: NormalizedSlip,
  expectedAmount: number,
  shopAccounts: string[],
  requireAccountMatch = true
): VerifyResult {
  const result: VerifyResult = {
    verified: false,
    reason: '',
    ref: s.ref ?? null,
    amount: s.amount ?? null,
    data: s.raw ?? {},
  };

  // A valid slip is identified by a transaction reference. The docs-example
  // top-level "type":"SLIP" is NOT always present in real GhostX responses,
  // so requiring it would wrongly reject genuine slips.
  if ((s.ref ?? null) === null) {
    result.reason = 'not_a_slip';
    return result;
  }

  if (!amountMatches(expectedAmount, s.amount ?? 0)) {
    result.reason = 'amount_mismatch';
    return result;
  }

  // Account match is an extra safety check that can be turned off
  // (amount-only mode). The destination account is still surfaced to the
  // admin for a visual spot-check either way.
  if (requireAccountMatch) {
    let accountOk = false;
    for (const acct of shopAccounts) {
      if (accountMatches(String(acct), String(s.toAccountNo ?? ''))) {
        accountOk = true;
        break;
      }
    }
    if (!accountOk) {
      result.reason = 'account_mismatch';
      return result;
    }
  }

  result.verified = true;
  result.reason = 'ok';
  return result;
}

// ---------------------------------------------------------------------------
// SlipVerifier class — mirrors the PHP class shape (constructor options +
// scan()/verify()/verifyStored() instance methods) so call sites read the
// same as the PHP source (`new SlipVerifier()`, `$verifier->verify(...)`).
// ---------------------------------------------------------------------------

export class SlipVerifier {
  private readonly endpoint: string;
  private readonly transport: SlipHttpTransport;

  constructor(endpoint: string = DEFAULT_ENDPOINT, transport: SlipHttpTransport | null = null) {
    this.endpoint = endpoint;
    this.transport = transport ?? defaultHttpPost;
  }

  /**
   * Send the QR payload to GhostX and return the normalized transfer.
   * Port of SlipVerifier::scan() (PHP lines 43-69).
   * Throws on transport failure, non-200 (without slip data), or an
   * unparseable body.
   */
  async scan(qrData: string): Promise<NormalizedSlip> {
    const res = await this.transport(this.endpoint, { qrData });
    const status = res.status ?? 0;
    const body = res.body ?? '';

    let json: unknown = null;
    try {
      json = JSON.parse(body);
    } catch {
      json = null;
    }

    // GhostX returns HTTP 409 when a QR was already scanned, but still
    // includes the slip data in the body — accept any status whose body
    // carries a valid slip so re-checks of an already-scanned slip work.
    const j = json && typeof json === 'object' ? (json as GhostxResponse) : null;
    const hasSlip = j !== null && (j.type === 'SLIP' || j.slipVerification?.transfer !== undefined);

    if (status !== 200 && !hasSlip) {
      // Surface GhostX's own error message (e.g. "ไม่มีรหัสอ้างอิงรายการ")
      // so admins see exactly why a slip could not be verified.
      const msg = j ? String((j.message as string | undefined) ?? (j.title as string | undefined) ?? '') : '';
      throw new Error(`GhostX HTTP ${status}${msg !== '' ? ': ' + msg : ''}`);
    }
    if (j === null) {
      throw new Error('GhostX returned an unparseable body');
    }

    return normalize(j as Record<string, unknown>);
  }

  /**
   * Verify a slip against the expected amount and the shop's account list.
   * Never throws — transport errors degrade to verified=false so the caller
   * can safely fall back to manual admin review. Port of SlipVerifier::verify()
   * (PHP lines 99-115).
   */
  async verify(
    qrData: string,
    expectedAmount: number,
    shopAccounts: string[],
    requireAccountMatch = true
  ): Promise<VerifyResult> {
    let s: NormalizedSlip;
    try {
      s = await this.scan(qrData);
    } catch (e) {
      const message = e instanceof Error ? e.message : String(e);
      return {
        verified: false,
        reason: 'scan_error: ' + message,
        ref: null,
        amount: null,
        // Keep the GhostX message so admins can see what happened.
        data: { error: message },
      };
    }

    return evaluate(s, expectedAmount, shopAccounts, requireAccountMatch);
  }

  /**
   * Re-evaluate a GhostX response we already stored at upload, WITHOUT calling
   * GhostX again. GhostX rejects re-scans of the same QR with HTTP 409, so the
   * admin "verify" button reuses the saved response instead of re-scanning.
   * Port of SlipVerifier::verifyStored() (PHP lines 125-128).
   */
  verifyStored(
    ghostxResponse: Record<string, unknown>,
    expectedAmount: number,
    shopAccounts: string[],
    requireAccountMatch = true
  ): VerifyResult {
    return evaluate(normalize(ghostxResponse), expectedAmount, shopAccounts, requireAccountMatch);
  }

  /**
   * Decision logic over a normalized scan result. Pure — no HTTP. Port of
   * SlipVerifier::evaluate() (PHP lines 136-179) — delegates to the free
   * function `evaluate()` above (kept both as a static-ish free function, for
   * direct unit testing, and as an instance method, to match the PHP call
   * shape `$verifier->evaluate(...)`).
   */
  evaluate(s: NormalizedSlip, expectedAmount: number, shopAccounts: string[], requireAccountMatch = true): VerifyResult {
    return evaluate(s, expectedAmount, shopAccounts, requireAccountMatch);
  }
}
