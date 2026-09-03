import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * customerHealthEngine.ts — CANONICAL port of `classes/CustomerHealthEngineService.php`'s
 * `getAllergies()` (lines 158-235), `getMedications()` (lines 245-342), and
 * their shared helpers `resolveLineUserId()` (348-358), `mergeMiniAppAllergies()`
 * (413-469), `mergeMiniAppMedications()` (470-524), and
 * `getRecentPurchasedMedications()` (525-592).
 *
 * This is the SINGLE-OWNER canonical implementation for this batch (see this
 * batch's runbook), cross-imported by `../../recommendations/_lib/getForSymptoms.ts`
 * and `../../safe-alternatives/_lib/safeAlternatives.ts` — both of those
 * actions' backing PHP methods (`DrugRecommendEngineService::getForSymptoms()`/
 * `::getSafeAlternatives()`) call `$this->healthEngine->getAllergies()`/
 * `getMedications()` whenever `setHealthEngine()` was called, which
 * `api/inbox-v2.php`'s own case blocks for `check_drug_interactions`,
 * `recommendations`, and `safe_alternatives` ALL do unconditionally before
 * invoking the recommend-engine method (`loadService('CustomerHealthEngineService',
 * ...)` always succeeds in production — the class file always exists — so
 * `setHealthEngine()` is always called; this port therefore ALWAYS routes
 * through this module rather than reimplementing `DrugRecommendEngineService`'s
 * own direct-users-table-query fallback, which is dead code on every real
 * request path. Same "always true" simplification precedent as this
 * codebase's dropped `hasCostPrice`/`SHOW TABLES LIKE` probes elsewhere.
 *
 * ```php
 * public function getAllergies(int $userId): array
 * {
 *     $allergies = [];
 *     try {
 *         $stmt = $this->db->prepare("SELECT drug_allergies FROM users WHERE id = ?");
 *         $stmt->execute([$userId]);
 *         $user = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($user && !empty($user['drug_allergies'])) {
 *             $allergyList = preg_split('/[,\n]+/', $user['drug_allergies']);
 *             foreach ($allergyList as $allergy) {
 *                 $allergy = trim($allergy);
 *                 if (!empty($allergy)) {
 *                     $allergies[] = ['name' => $allergy, 'severity' => 'unknown', 'source' => 'user_profile', 'isActive' => true];
 *                 }
 *             }
 *         }
 *         try {
 *             $stmt = $this->db->prepare("SELECT allergy_name, severity, reaction, notes, is_active FROM user_allergies WHERE user_id = ? AND is_active = 1");
 *             $stmt->execute([$userId]);
 *             $detailedAllergies = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *             foreach ($detailedAllergies as $allergy) {
 *                 $exists = false;
 *                 foreach ($allergies as &$existing) {
 *                     if (stripos($existing['name'], $allergy['allergy_name']) !== false ||
 *                         stripos($allergy['allergy_name'], $existing['name']) !== false) {
 *                         $existing['severity'] = $allergy['severity'] ?? 'unknown';
 *                         $existing['reaction'] = $allergy['reaction'] ?? null;
 *                         $existing['notes'] = $allergy['notes'] ?? null;
 *                         $existing['source'] = 'detailed';
 *                         $exists = true;
 *                         break;
 *                     }
 *                 }
 *                 if (!$exists) {
 *                     $allergies[] = ['name' => $allergy['allergy_name'], 'severity' => $allergy['severity'] ?? 'unknown', 'reaction' => $allergy['reaction'] ?? null, 'notes' => $allergy['notes'] ?? null, 'source' => 'detailed', 'isActive' => true];
 *                 }
 *             }
 *         } catch (PDOException $e) { // user_allergies table might not exist
 *         }
 *         $this->mergeMiniAppAllergies($allergies, $userId);
 *     } catch (PDOException $e) {
 *         error_log("CustomerHealthEngine getAllergies error: " . $e->getMessage());
 *     }
 *     return $allergies;
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `user_allergies` / `user_medications` ("detailed") tables — CONFIRMED ABSENT
 * ═══════════════════════════════════════════════════════════════════════
 * Neither table exists in `packages/db/src/generated/tenant-db.d.ts`. PHP's
 * own inner `try { ... } catch (PDOException $e) { // table might not exist }`
 * around each already degrades this source to a no-op on a real tenant DB
 * (the query throws "table doesn't exist", caught locally, "detailed" merge
 * never runs) — this is PORTED LITERALLY (the query + inner try/catch is
 * still issued, per this batch's brief: "this is NOT a bug to fix"), not
 * special-cased away, so a future migration that adds either table makes
 * this code start working with zero further changes.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `user_drug_allergies` / `user_current_medications` ("miniapp") tables —
 * CONFIRMED PRESENT, safety-critical
 * ═══════════════════════════════════════════════════════════════════════
 * Both are real, typed tables in `tenant-db.d.ts` (`UserDrugAllergies`,
 * `UserCurrentMedications`) — the LINE Mini App's own health-profile
 * write path. `mergeMiniAppAllergies()`/`mergeMiniAppMedications()` are
 * ported as fully live queries (not degraded), matching PHP's own comment
 * that this merge is safety-critical for the pharmacist HUD.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SCHEMA-DRIFT FIX — `getRecentPurchasedMedications()`'s primary query:
 * `bi.is_prescription` -> `bi.requires_prescription` (WHERE clause only)
 * ═══════════════════════════════════════════════════════════════════════
 * PHP line ~541: `... OR bi.name LIKE '%ยา%' OR bi.is_prescription = 1)`.
 * `business_items.is_prescription` does not exist (confirmed against
 * `tenant-db.d.ts` and `database/install_complete_latest.sql` — same
 * confirmed drift as `../../drug-inventory/_lib/drugInventory.ts`'s and
 * `../../low-stock-drugs/_lib/lowStockDrugs.ts`'s identical finding); the
 * real column is `requires_prescription`. EFFECT IN CURRENT PRODUCTION:
 * this WHERE-clause reference makes the PRIMARY (`transactions`-based)
 * query throw ("Unknown column") on EVERY call — caught by PHP's own outer
 * `catch (PDOException $e)` around this method, which silently re-runs the
 * SECONDARY (`orders`-based) fallback query instead (that query has no
 * such reference and succeeds). So today, "recently purchased medications"
 * is silently ALWAYS sourced from `orders`, never `transactions`, in
 * production.
 *
 * This port is a deliberate, documented FIX-FORWARD deviation (same
 * precedent as the identical column elsewhere in this batch/Phase 4 batch
 * 4a): the primary query's WHERE clause reads the real
 * `bi.requires_prescription = 1` (WHERE-only — no SELECT-list aliasing is
 * needed here, since `is_prescription`/`requires_prescription` is never
 * read from the SELECT list of this particular query). The dual
 * try-primary/catch-fallback STRUCTURE is kept exactly as-is — the
 * secondary `orders`-table query remains a genuine, reachable resilience
 * path (a real, unrelated DB failure on the primary query still correctly
 * falls back to it), it just stops being the ALWAYS-taken path once the
 * schema-drift bug that masked the primary query is fixed.
 */

// ─────────────────────────────────────────────────────────────────────────
// Shared types
// ─────────────────────────────────────────────────────────────────────────

export interface AllergyEntry {
  name: string;
  severity: string;
  source: 'user_profile' | 'detailed' | 'miniapp';
  isActive: true;
  reaction?: string | null;
  notes?: string | null;
}

export interface MedicationEntry {
  name: string;
  dosage?: string | null;
  frequency?: string | null;
  startDate?: string | Date | null;
  notes?: string | null;
  source: 'user_profile' | 'detailed' | 'miniapp' | 'purchase_history';
  isActive: true;
  productId?: number;
  lastPurchased?: string | Date | null;
}

/** PHP `empty($v)` for a string value already known to be `string | null | undefined`. */
function isPhpEmptyString(value: string | null | undefined): boolean {
  return value === null || value === undefined || value === '' || value === '0';
}

/** `stripos($a, $b) !== false || stripos($b, $a) !== false` — bidirectional case-insensitive substring match. */
function fuzzyNameMatches(a: string, b: string): boolean {
  const aLower = a.toLowerCase();
  const bLower = b.toLowerCase();
  return aLower.includes(bLower) || bLower.includes(aLower);
}

// ─────────────────────────────────────────────────────────────────────────
// resolveLineUserId()
// ─────────────────────────────────────────────────────────────────────────

interface LineUserIdRow {
  line_user_id: string | null;
}

async function resolveLineUserId(db: Kysely<TenantDB>, userId: number): Promise<string | null> {
  try {
    const result = await sql<LineUserIdRow>`SELECT line_user_id FROM users WHERE id = ${userId}`.execute(db);
    const lid = result.rows[0]?.line_user_id;
    return lid !== undefined && lid !== null && lid !== '' ? String(lid) : null;
  } catch {
    return null;
  }
}

// ─────────────────────────────────────────────────────────────────────────
// mergeMiniAppAllergies()
// ─────────────────────────────────────────────────────────────────────────

const NONE_TOKENS: readonly string[] = ['ไม่มี', 'ไม่แพ้', 'ไม่มีประวัติแพ้ยา', 'none', 'no', 'n/a', '-'];

interface UserDrugAllergyRow {
  drug_name: string;
  severity: 'mild' | 'moderate' | 'severe' | null;
  reaction_type: string | null;
  reaction_notes: string | null;
}

async function mergeMiniAppAllergies(db: Kysely<TenantDB>, allergies: AllergyEntry[], userId: number): Promise<void> {
  try {
    const lid = await resolveLineUserId(db, userId);
    if (lid === null) return;

    const result = await sql<UserDrugAllergyRow>`
      SELECT drug_name, severity, reaction_type, reaction_notes
      FROM user_drug_allergies
      WHERE line_user_id = ${lid}
      ORDER BY created_at DESC
    `.execute(db);

    for (const row of result.rows) {
      const name = (row.drug_name ?? '').trim();
      if (name === '' || NONE_TOKENS.includes(name.toLowerCase())) continue;

      let matched = false;
      for (const existing of allergies) {
        if (fuzzyNameMatches(existing.name, name)) {
          existing.severity = row.severity || existing.severity || 'unknown';
          existing.reaction = row.reaction_type ?? existing.reaction ?? null;
          existing.notes = row.reaction_notes ?? existing.notes ?? null;
          existing.source = 'miniapp';
          matched = true;
          break;
        }
      }

      if (!matched) {
        allergies.push({
          name,
          severity: row.severity || 'unknown',
          reaction: row.reaction_type ?? null,
          notes: row.reaction_notes ?? null,
          source: 'miniapp',
          isActive: true,
        });
      }
    }
  } catch {
    // user_drug_allergies may not exist on legacy tenants.
  }
}

// ─────────────────────────────────────────────────────────────────────────
// mergeMiniAppMedications()
// ─────────────────────────────────────────────────────────────────────────

interface UserCurrentMedicationRow {
  medication_name: string;
  dosage: string | null;
  frequency: string | null;
  notes: string | null;
}

async function mergeMiniAppMedications(db: Kysely<TenantDB>, medications: MedicationEntry[], userId: number): Promise<void> {
  try {
    const lid = await resolveLineUserId(db, userId);
    if (lid === null) return;

    const result = await sql<UserCurrentMedicationRow>`
      SELECT medication_name, dosage, frequency, notes
      FROM user_current_medications
      WHERE line_user_id = ${lid} AND is_active = 1
      ORDER BY created_at DESC
    `.execute(db);

    for (const row of result.rows) {
      const name = (row.medication_name ?? '').trim();
      if (name === '') continue;

      let matched = false;
      for (const existing of medications) {
        if (fuzzyNameMatches(existing.name, name)) {
          existing.dosage = row.dosage || existing.dosage || null;
          existing.frequency = row.frequency || existing.frequency || null;
          existing.notes = row.notes ?? existing.notes ?? null;
          existing.source = 'miniapp';
          matched = true;
          break;
        }
      }

      if (!matched) {
        medications.push({
          name,
          dosage: row.dosage ?? null,
          frequency: row.frequency ?? null,
          notes: row.notes ?? null,
          source: 'miniapp',
          isActive: true,
        });
      }
    }
  } catch {
    // user_current_medications may not exist on legacy tenants.
  }
}

// ─────────────────────────────────────────────────────────────────────────
// getRecentPurchasedMedications()
// ─────────────────────────────────────────────────────────────────────────

interface RecentPurchaseRow {
  name: string;
  product_id: number;
  last_purchased: Date;
}

export async function getRecentPurchasedMedications(db: Kysely<TenantDB>, userId: number, days = 90): Promise<MedicationEntry[]> {
  try {
    // FIX: `bi.is_prescription` -> `bi.requires_prescription` (WHERE clause only) — see module doc.
    const result = await sql<RecentPurchaseRow>`
      SELECT DISTINCT bi.name, bi.id as product_id, MAX(t.created_at) as last_purchased
      FROM transactions t
      JOIN transaction_items ti ON t.id = ti.transaction_id
      JOIN business_items bi ON ti.product_id = bi.id
      LEFT JOIN item_categories ic ON bi.category_id = ic.id
      WHERE t.user_id = ${userId}
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL ${days} DAY)
      AND t.status NOT IN ('cancelled', 'failed')
      AND (ic.name LIKE '%ยา%' OR ic.name LIKE '%drug%' OR ic.name LIKE '%medicine%'
           OR bi.name LIKE '%ยา%' OR bi.requires_prescription = 1)
      GROUP BY bi.id, bi.name
      ORDER BY last_purchased DESC
      LIMIT 10
    `.execute(db);

    return result.rows.map((row) => ({
      name: row.name,
      productId: row.product_id,
      lastPurchased: row.last_purchased,
      source: 'purchase_history' as const,
      isActive: true as const,
    }));
  } catch {
    try {
      const result = await sql<RecentPurchaseRow>`
        SELECT DISTINCT bi.name, bi.id as product_id, MAX(o.created_at) as last_purchased
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN business_items bi ON oi.product_id = bi.id
        WHERE o.user_id = ${userId}
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL ${days} DAY)
        AND o.status IN ('paid', 'confirmed', 'delivered', 'completed')
        GROUP BY bi.id, bi.name
        ORDER BY last_purchased DESC
        LIMIT 10
      `.execute(db);

      return result.rows.map((row) => ({
        name: row.name,
        productId: row.product_id,
        lastPurchased: row.last_purchased,
        source: 'purchase_history' as const,
        isActive: true as const,
      }));
    } catch {
      // Both queries failed — PHP's own "// Ignore" branch.
      return [];
    }
  }
}

// ─────────────────────────────────────────────────────────────────────────
// getAllergies()
// ─────────────────────────────────────────────────────────────────────────

interface DrugAllergiesTextRow {
  drug_allergies: string | null;
}

/** `user_allergies` — CONFIRMED ABSENT from `tenant-db.d.ts`; always throws/skips on a real tenant DB. See module doc. */
interface DetailedAllergyRow {
  allergy_name: string;
  severity: string | null;
  reaction: string | null;
  notes: string | null;
}

export async function getAllergies(db: Kysely<TenantDB>, userId: number): Promise<AllergyEntry[]> {
  const allergies: AllergyEntry[] = [];

  try {
    const textResult = await sql<DrugAllergiesTextRow>`SELECT drug_allergies FROM users WHERE id = ${userId}`.execute(db);
    const user = textResult.rows[0];

    if (user && !isPhpEmptyString(user.drug_allergies)) {
      const list = (user.drug_allergies as string).split(/[,\n]+/);
      for (const raw of list) {
        const allergy = raw.trim();
        if (allergy !== '') {
          allergies.push({ name: allergy, severity: 'unknown', source: 'user_profile', isActive: true });
        }
      }
    }

    try {
      const detailedResult = await sql<DetailedAllergyRow>`
        SELECT allergy_name, severity, reaction, notes, is_active
        FROM user_allergies
        WHERE user_id = ${userId} AND is_active = 1
      `.execute(db);

      for (const row of detailedResult.rows) {
        let matched = false;
        for (const existing of allergies) {
          if (fuzzyNameMatches(existing.name, row.allergy_name)) {
            existing.severity = row.severity ?? 'unknown';
            existing.reaction = row.reaction ?? null;
            existing.notes = row.notes ?? null;
            existing.source = 'detailed';
            matched = true;
            break;
          }
        }
        if (!matched) {
          allergies.push({
            name: row.allergy_name,
            severity: row.severity ?? 'unknown',
            reaction: row.reaction ?? null,
            notes: row.notes ?? null,
            source: 'detailed',
            isActive: true,
          });
        }
      }
    } catch {
      // user_allergies table might not exist — see module doc.
    }

    await mergeMiniAppAllergies(db, allergies, userId);
  } catch {
    // Outer catch (PDOException $e) — only realistically reachable via the
    // initial `users` text-field query (the inner calls never throw).
  }

  return allergies;
}

// ─────────────────────────────────────────────────────────────────────────
// getMedications()
// ─────────────────────────────────────────────────────────────────────────

interface CurrentMedicationsTextRow {
  current_medications: string | null;
}

/** `user_medications` — CONFIRMED ABSENT from `tenant-db.d.ts`; always throws/skips on a real tenant DB. See module doc. */
interface DetailedMedicationRow {
  medication_name: string;
  dosage: string | null;
  frequency: string | null;
  start_date: Date | string | null;
  notes: string | null;
}

export async function getMedications(db: Kysely<TenantDB>, userId: number): Promise<MedicationEntry[]> {
  const medications: MedicationEntry[] = [];

  try {
    const textResult = await sql<CurrentMedicationsTextRow>`SELECT current_medications FROM users WHERE id = ${userId}`.execute(db);
    const user = textResult.rows[0];

    if (user && !isPhpEmptyString(user.current_medications)) {
      const list = (user.current_medications as string).split(/[,\n]+/);
      for (const raw of list) {
        const med = raw.trim();
        if (med !== '') {
          medications.push({ name: med, dosage: null, frequency: null, source: 'user_profile', isActive: true });
        }
      }
    }

    try {
      const detailedResult = await sql<DetailedMedicationRow>`
        SELECT medication_name, dosage, frequency, start_date, notes, is_active
        FROM user_medications
        WHERE user_id = ${userId} AND is_active = 1
      `.execute(db);

      for (const row of detailedResult.rows) {
        let matched = false;
        for (const existing of medications) {
          if (fuzzyNameMatches(existing.name, row.medication_name)) {
            existing.dosage = row.dosage ?? null;
            existing.frequency = row.frequency ?? null;
            existing.startDate = row.start_date ?? null;
            existing.notes = row.notes ?? null;
            existing.source = 'detailed';
            matched = true;
            break;
          }
        }
        if (!matched) {
          medications.push({
            name: row.medication_name,
            dosage: row.dosage ?? null,
            frequency: row.frequency ?? null,
            startDate: row.start_date ?? null,
            notes: row.notes ?? null,
            source: 'detailed',
            isActive: true,
          });
        }
      }
    } catch {
      // user_medications table might not exist — see module doc.
    }

    const purchased = await getRecentPurchasedMedications(db, userId);
    for (const purchasedMed of purchased) {
      const exists = medications.some((existing) => fuzzyNameMatches(existing.name, purchasedMed.name));
      if (!exists) {
        medications.push(purchasedMed);
      }
    }

    await mergeMiniAppMedications(db, medications, userId);
  } catch {
    // Outer catch (PDOException $e) — only realistically reachable via the
    // initial `users` text-field query (the inner calls never throw).
  }

  return medications;
}
