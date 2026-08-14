import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * genDocNumber.ts — TypeScript port of `includes/document-helpers.php`'s
 * `REYA_DOCUMENT_TYPES` constant (lines 27-41) and `genDocNumber()`
 * (lines 55-113).
 */

// ---------------------------------------------------------------------------
// REYA_DOCUMENT_TYPES — port of the PHP `define('REYA_DOCUMENT_TYPES', [...])`
// map (lines 28-40). All 11 document types, sales + purchase groups.
// ---------------------------------------------------------------------------

export interface DocTypeMeta {
  label: string;
  labelEn: string;
  prefix: string;
  group: 'sales' | 'purchase';
}

export const REYA_DOCUMENT_TYPES = {
  QT: { label: 'ใบเสนอราคา', labelEn: 'Quotation', prefix: 'QT', group: 'sales' },
  BL: { label: 'ใบวางบิล', labelEn: 'Billing Note', prefix: 'BL', group: 'sales' },
  INV: { label: 'ใบแจ้งหนี้', labelEn: 'Invoice', prefix: 'INV', group: 'sales' },
  RE: { label: 'ใบเสร็จรับเงิน', labelEn: 'Receipt', prefix: 'RE', group: 'sales' },
  TAX: { label: 'ใบกำกับภาษี', labelEn: 'Tax Invoice', prefix: 'TAX', group: 'sales' },
  DN: { label: 'ใบเพิ่มหนี้', labelEn: 'Debit Note', prefix: 'DN', group: 'sales' },
  CN: { label: 'ใบลดหนี้', labelEn: 'Credit Note', prefix: 'CN', group: 'sales' },
  PO: { label: 'ใบสั่งซื้อ', labelEn: 'Purchase Order', prefix: 'PO', group: 'purchase' },
  GR: { label: 'ใบรับสินค้า', labelEn: 'Goods Receipt', prefix: 'GR', group: 'purchase' },
  DNP: { label: 'ใบเพิ่มหนี้ (ซื้อ)', labelEn: 'Debit Note (P)', prefix: 'DNP', group: 'purchase' },
  CNP: { label: 'ใบลดหนี้ (ซื้อ)', labelEn: 'Credit Note (P)', prefix: 'CNP', group: 'purchase' },
} as const satisfies Record<string, DocTypeMeta>;

export type DocType = keyof typeof REYA_DOCUMENT_TYPES;

export function isDocType(value: string): value is DocType {
  return Object.prototype.hasOwnProperty.call(REYA_DOCUMENT_TYPES, value);
}

// ---------------------------------------------------------------------------
// genDocNumber
// ---------------------------------------------------------------------------

export type GenDocNumberErrorCode = 'unknown_doc_type' | 'invalid_line_account_id';

/** Port of PHP's `InvalidArgumentException` throws in `genDocNumber()` (lines 59, 62). */
export class GenDocNumberError extends Error {
  readonly code: GenDocNumberErrorCode;
  constructor(code: GenDocNumberErrorCode, message: string) {
    super(message);
    this.name = 'GenDocNumberError';
    this.code = code;
  }
}

/**
 * Bangkok (UTC+7, no DST) wall-clock year/month for "now", OR — when `when`
 * is supplied — the year/month read directly off `when`'s UTC getters.
 *
 * PHP's default path is `new DateTimeImmutable('now', new
 * DateTimeZone('Asia/Bangkok'))`, which is immune to the server's local
 * timezone by construction; the shift-by-7-hours-then-read-UTC-fields trick
 * below achieves the same result without depending on the host's tz
 * database or `TZ` env var (Bangkok has no DST, so a fixed +07:00 offset is
 * always correct).
 *
 * When a caller passes `when` explicitly (tests, or a future backdated-doc
 * feature), PHP instead calls `->format('Y')`/`->format('n')` directly on
 * that already-tz-aware `DateTimeInterface` — i.e. it trusts the object's
 * own embedded timezone rather than re-applying Bangkok. Since a bare JS
 * `Date` carries no timezone (only a UTC epoch instant), this port's
 * documented contract is: `when`'s UTC-getter fields (`getUTCFullYear()`/
 * `getUTCMonth()`) ARE the intended Bangkok wall-clock year/month — build it
 * with `new Date(Date.UTC(year, monthIndex, day))` at the call site, exactly
 * as this package's own tests do.
 */
function currentBangkokYearMonth(when?: Date): { year: number; month: number } {
  if (when) {
    return { year: when.getUTCFullYear(), month: when.getUTCMonth() + 1 };
  }
  const bangkok = new Date(Date.now() + 7 * 60 * 60 * 1000);
  return { year: bangkok.getUTCFullYear(), month: bangkok.getUTCMonth() + 1 };
}

/**
 * Port of:
 * ```php
 * function genDocNumber(PDO $db, int $lineAccountId, string $docType, ?DateTimeInterface $when = null): string
 * {
 *     $docType = strtoupper(trim($docType));
 *     if (!isset(REYA_DOCUMENT_TYPES[$docType])) throw new InvalidArgumentException("Unknown doc_type: {$docType}");
 *     if ($lineAccountId <= 0) throw new InvalidArgumentException('lineAccountId must be > 0');
 *
 *     $now = $when ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
 *     $buddhistYear = (int)$now->format('Y') + 543;
 *     $yearMonth = sprintf('%02d%02d', $buddhistYear % 100, (int)$now->format('n'));
 *
 *     $ownTx = !$db->inTransaction();
 *     if ($ownTx) { $db->beginTransaction(); }
 *     try {
 *         INSERT IGNORE INTO document_sequences (line_account_id, doc_type, `year_month`, last_seq) VALUES (?, ?, ?, 0)
 *         SELECT id, last_seq FROM document_sequences WHERE line_account_id=? AND doc_type=? AND `year_month`=? FOR UPDATE
 *         $nextSeq = last_seq + 1;
 *         UPDATE document_sequences SET last_seq = ? WHERE id = ?
 *         if ($ownTx) { $db->commit(); }
 *     } catch (Throwable $e) {
 *         if ($ownTx && $db->inTransaction()) { $db->rollBack(); }
 *         throw $e;
 *     }
 *     return sprintf('%s-%s-%04d', $docType, $yearMonth, $nextSeq);
 * }
 * ```
 *
 * TRANSACTION OWNERSHIP: PHP's `$ownTx = !$db->inTransaction()` becomes
 * `!db.isTransaction` — `false` on a plain top-level `Kysely<TenantDB>`,
 * `true` on a `Transaction<TenantDB>` handle (Kysely's `isTransaction`
 * getter, per the installed kysely@0.29 typings). When `db` is already a
 * transaction (the create route's shared-transaction case), this function
 * never opens or commits/rolls back its own nested transaction — it just
 * runs its three queries against the caller's `db`/`trx` and lets any error
 * propagate up for the CALLER's transaction to roll back, exactly mirroring
 * PHP's `$ownTx=false` branch (no beginTransaction/commit/rollBack calls at
 * all in that case). This is what makes the create route's "genDocNumber +
 * insert share one atomic transaction" property hold.
 */
export async function genDocNumber(db: Kysely<TenantDB>, lineAccountId: number, docType: string, when?: Date): Promise<string> {
  const normalizedType = docType.trim().toUpperCase();
  if (!isDocType(normalizedType)) {
    throw new GenDocNumberError('unknown_doc_type', `Unknown doc_type: ${normalizedType}`);
  }
  if (lineAccountId <= 0) {
    throw new GenDocNumberError('invalid_line_account_id', 'lineAccountId must be > 0');
  }

  const { year, month } = currentBangkokYearMonth(when);
  const buddhistYear = year + 543;
  const yearMonth = `${String(((buddhistYear % 100) + 100) % 100).padStart(2, '0')}${String(month).padStart(2, '0')}`;

  const run = async (conn: Kysely<TenantDB>): Promise<string> => {
    // NB: `year_month` is a reserved-ish MySQL keyword (used in INTERVAL …
    // YEAR_MONTH) — same reason the PHP source backtick-quotes it in every
    // reference; Kysely's MysqlDialect backtick-quotes all identifiers by
    // default, so no special-casing is needed here.
    await conn
      .insertInto('document_sequences')
      .values({ line_account_id: lineAccountId, doc_type: normalizedType, year_month: yearMonth, last_seq: 0 })
      .ignore()
      .execute();

    const row = await conn
      .selectFrom('document_sequences')
      .select(['id', 'last_seq'])
      .where('line_account_id', '=', lineAccountId)
      .where('doc_type', '=', normalizedType)
      .where('year_month', '=', yearMonth)
      .forUpdate()
      .executeTakeFirst();

    if (!row) {
      throw new Error('document_sequences row missing after INSERT IGNORE');
    }
    const nextSeq = Number(row.last_seq) + 1;

    await conn.updateTable('document_sequences').set({ last_seq: nextSeq }).where('id', '=', row.id).execute();

    return `${normalizedType}-${yearMonth}-${String(nextSeq).padStart(4, '0')}`;
  };

  const ownTx = !db.isTransaction;
  if (ownTx) {
    return db.transaction().execute((trx) => run(trx));
  }
  return run(db);
}
