import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * prescriptionHistory.ts — CANONICAL port of
 * `classes/PharmacyIntegrationService.php`'s `getUserPrescriptionHistory()`
 * (lines 420-452), as driven by api/inbox-v2.php's `case
 * 'prescription_history': case 'prescription-history':` (lines ~1126-1155).
 *
 * ```php
 * public function getUserPrescriptionHistory(int $userId, int $limit = 20): array
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT
 *                 t.id as transaction_id,
 *                 t.order_number,
 *                 t.created_at,
 *                 t.status,
 *                 ti.product_name,
 *                 ti.quantity,
 *                 bi.generic_name,
 *                 bi.is_prescription,
 *                 bi.drug_category
 *             FROM transactions t
 *             JOIN transaction_items ti ON t.id = ti.transaction_id
 *             LEFT JOIN business_items bi ON ti.product_id = bi.id
 *             WHERE t.user_id = ?
 *             AND t.status NOT IN ('cancelled', 'failed')
 *             AND (bi.is_prescription = 1 OR bi.drug_category IN ('dangerous', 'controlled'))
 *             ORDER BY t.created_at DESC
 *             LIMIT ?
 *         ");
 *         $stmt->execute([$userId, $limit]);
 *         return $stmt->fetchAll(PDO::FETCH_ASSOC);
 *     } catch (PDOException $e) {
 *         error_log("PharmacyIntegration getUserPrescriptionHistory error: " . $e->getMessage());
 *         return [];
 *     }
 * }
 * ```
 *
 * Exported for `patient-profile`'s documented cross-import (`getComprehensivePatientProfile()`
 * calls this with `limit=10`, PHP line 851) — this file is the SINGLE-OWNER
 * canonical implementation, same "same builder, same round" precedent as
 * Phase 4 batch 4a's `drug-info` -> `max-discount/_lib/drugPricingEngine`
 * import.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX (D) — `is_prescription` -> `requires_prescription`
 * ═══════════════════════════════════════════════════════════════════════
 * Same confirmed finding as Phase 4 batch 4a's `drug-inventory`/
 * `low-stock-drugs` (see `../../drug-inventory/_lib/drugInventory.ts`'s
 * module doc for the full writeup): `business_items.is_prescription` does
 * not exist — the real column is `requires_prescription`.
 *
 * UNLIKE those two siblings, `is_prescription` appears TWICE in this query
 * — once in the SELECT list (`bi.is_prescription`) AND once in the WHERE
 * clause (`bi.is_prescription = 1 OR ...`). Both must be fixed together:
 * fixing only the WHERE clause (as a narrow reading of "affects the WHERE
 * clause" might suggest) while leaving the SELECT's reference to the
 * nonexistent column in place would still make the ENTIRE query throw
 * ("Unknown column") on every call, leaving `prescription_history` exactly
 * as broken as before — defeating the point of the fix. This port
 * therefore:
 *   - WHERE clause: `bi.is_prescription = 1` -> `bi.requires_prescription = 1`
 *     (a filter condition has no output shape, so no aliasing is needed or
 *     possible there).
 *   - SELECT list: `bi.is_prescription` -> `bi.requires_prescription AS is_prescription`,
 *     ALIASED so the output row's key name is unchanged — same
 *     alias-preserving technique as `drug-inventory`/`low-stock-drugs`.
 *
 * EFFECT IN CURRENT PRODUCTION: the unfixed query throws a PDOException on
 * EVERY call — caught by this method's own `catch (PDOException $e)`,
 * returning a bare `[]`. `case 'prescription_history':` does
 * `sendResponse(['success' => true, 'data' => $result, 'count' =>
 * count($result)])` UNCONDITIONALLY (no `found`/error check), so today this
 * action ALWAYS returns `{success: true, data: [], count: 0}` in
 * production, even when the user has real dispensed-drug transactions. This
 * fix also transitively repairs `patient_profile`'s
 * `prescriptionHistory` field (both actions share this one function).
 *
 * `catch (PDOException $e) { return []; }` is KEPT for any genuinely
 * unrelated DB failure — `case 'prescription_history':` has no case-level
 * try/catch of its own (this function never throws, matching the literal
 * PHP method's own internal catch).
 *
 * `created_at` is converted to a MySQL `YYYY-MM-DD HH:MM:SS` string (not a
 * raw JS `Date`/ISO string) to match PDO's unconverted string read — same
 * convention as `../../messages/_lib/query.ts`'s `toMysqlDateTimeString()`.
 */

interface PrescriptionHistoryRow {
  transaction_id: number;
  order_number: string;
  created_at: Date;
  status: string | null;
  product_name: string;
  quantity: number;
  generic_name: string | null;
  is_prescription: number | null;
  drug_category: string | null;
}

export interface PrescriptionHistoryRowJson extends Omit<PrescriptionHistoryRow, 'created_at'> {
  created_at: string;
}

/** `YYYY-MM-DD HH:MM:SS` in local wall-clock (mysql2 hydrates DATETIME columns via the process's local TZ, pinned to Asia/Bangkok in prod/CI — see `../../messages/_lib/query.ts`). */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

export async function getUserPrescriptionHistory(
  db: Kysely<TenantDB>,
  userId: number,
  limit = 20
): Promise<PrescriptionHistoryRowJson[]> {
  try {
    const result = await sql<PrescriptionHistoryRow>`
      SELECT
        t.id as transaction_id,
        t.order_number,
        t.created_at,
        t.status,
        ti.product_name,
        ti.quantity,
        bi.generic_name,
        bi.requires_prescription AS is_prescription,
        bi.drug_category
      FROM transactions t
      JOIN transaction_items ti ON t.id = ti.transaction_id
      LEFT JOIN business_items bi ON ti.product_id = bi.id
      WHERE t.user_id = ${userId}
      AND t.status NOT IN ('cancelled', 'failed')
      AND (bi.requires_prescription = 1 OR bi.drug_category IN ('dangerous', 'controlled'))
      ORDER BY t.created_at DESC
      LIMIT ${limit}
    `.execute(db);

    return result.rows.map((row) => ({ ...row, created_at: toMysqlDateTimeString(row.created_at) }));
  } catch {
    // PharmacyIntegrationService::getUserPrescriptionHistory()'s own `catch (PDOException $e)` — see module doc.
    return [];
  }
}
