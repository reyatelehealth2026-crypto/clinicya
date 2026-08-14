import { amountMatches, accountMatches } from './slipVerifier';

/**
 * slipDisplay.ts — pure derivation of the per-slip GhostX-result panel data,
 * ported from the inline PHP block inside the slip-card render loop (PHP
 * lines 1097-1177: `$vRef`/`$qrPayload`/`$vData`/`$tr`/`$slipAmt`/`$toAcc`/
 * `$fromName`/`$txRef`/`$txTime`/`$amtOk`/`$acctOk`/`$vErr`). Separated from
 * the JSX (SlipCard.tsx) so this data derivation is unit-testable without
 * rendering React.
 */

interface RawTransfer {
  amount?: { amount?: unknown };
  toAccountNo?: unknown;
  fromAccountName?: unknown;
  fromBankName?: unknown;
  transactionRef?: unknown;
  transactionDateTime?: unknown;
}

interface RawVerifyData {
  slipVerification?: { transfer?: RawTransfer };
  error?: unknown;
}

export interface SlipDisplayTransfer {
  amount: number | null;
  toAccountNo: string | null;
  fromName: string | null;
  transactionRef: string | null;
  transactionDateTime: string | null;
}

export interface SlipDisplayInput {
  verifyRef: string | null;
  qrPayload: string | null;
  verifyData: string | null;
}

export interface SlipDisplayComputed {
  verifyRef: string | null;
  qrPayload: string | null;
  /** Non-null exactly when GhostX returned a `slipVerification.transfer` (PHP: `if ($tr)`). */
  transfer: SlipDisplayTransfer | null;
  /** `SlipVerifier::amountMatches($orderGrandTotal, $slipAmt)` — false when there's no transfer at all. */
  amountOk: boolean;
  /** True when `toAccountNo` matches ANY of `shopAccounts` via `SlipVerifier::accountMatches()`. */
  accountOk: boolean;
  /** GhostX's own error message, only surfaced when there's no transfer (PHP: `$vErr`). */
  ghostxError: string | null;
}

/** Mirrors PHP's `!empty($x)` for a possibly-non-string value: falsy for '', '0', null/undefined. */
function phpNonEmptyString(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  if (value === '' || value === '0') return null;
  return value;
}

/** Port of the inline `$vRef`/`$qrPayload`/`$vData`/`$tr`/... computation block (PHP lines 1097-1177). */
export function computeSlipDisplay(slip: SlipDisplayInput, orderGrandTotal: number, shopAccounts: string[]): SlipDisplayComputed {
  let vData: RawVerifyData | null = null;
  if (slip.verifyData) {
    try {
      const parsed: unknown = JSON.parse(slip.verifyData);
      vData = parsed && typeof parsed === 'object' ? (parsed as RawVerifyData) : null;
    } catch {
      vData = null;
    }
  }

  const tr = vData?.slipVerification?.transfer ?? null;

  const slipAmt =
    tr && tr.amount && tr.amount.amount !== undefined && tr.amount.amount !== null ? Number(tr.amount.amount) : null;
  const toAcc = tr?.toAccountNo !== undefined && tr?.toAccountNo !== null ? String(tr.toAccountNo) : null;
  const fromName =
    tr?.fromAccountName !== undefined && tr?.fromAccountName !== null
      ? String(tr.fromAccountName)
      : tr?.fromBankName !== undefined && tr?.fromBankName !== null
        ? String(tr.fromBankName)
        : null;
  const txRefFromTransfer = tr?.transactionRef !== undefined && tr?.transactionRef !== null ? String(tr.transactionRef) : null;
  const txRef = txRefFromTransfer ?? slip.verifyRef;
  const txTime = tr?.transactionDateTime !== undefined && tr?.transactionDateTime !== null ? String(tr.transactionDateTime) : null;

  const amountOk = slipAmt !== null && amountMatches(orderGrandTotal, slipAmt);

  let accountOk = false;
  if (toAcc) {
    for (const a of shopAccounts) {
      if (accountMatches(String(a), toAcc)) {
        accountOk = true;
        break;
      }
    }
  }

  const ghostxError = !tr && vData ? phpNonEmptyString(vData.error) : null;

  return {
    verifyRef: slip.verifyRef,
    qrPayload: slip.qrPayload,
    transfer: tr
      ? { amount: slipAmt, toAccountNo: toAcc, fromName, transactionRef: txRef, transactionDateTime: txTime }
      : null,
    amountOk,
    accountOk,
    ghostxError,
  };
}
