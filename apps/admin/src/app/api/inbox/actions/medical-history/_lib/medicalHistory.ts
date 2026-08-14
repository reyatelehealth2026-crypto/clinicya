import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * medicalHistory.ts — CANONICAL port of `classes/PharmacyIntegrationService.php`'s
 * `getUserMedicalHistory()` (lines 240-314) and `parseTextToArray()` (lines
 * 778-792), as driven by api/inbox-v2.php's `case 'medical_history': case
 * 'medical-history': case 'get_medical_history':` (lines ~918-946).
 *
 * ```php
 * public function getUserMedicalHistory(int $userId): array
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT
 *                 id, display_name, first_name, last_name,
 *                 weight, height, birth_date, gender,
 *                 drug_allergies, chronic_diseases, current_medications, medical_conditions
 *             FROM users
 *             WHERE id = ?
 *         ");
 *         $stmt->execute([$userId]);
 *         $user = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *         if (!$user) {
 *             return ['userId' => $userId, 'found' => false, 'allergies' => [], 'conditions' => [],
 *                     'currentMedications' => [], 'weight' => null, 'height' => null, 'age' => null, 'gender' => null];
 *         }
 *
 *         $allergies = $this->parseTextToArray($user['drug_allergies']);
 *         $conditions = array_merge(
 *             $this->parseTextToArray($user['chronic_diseases']),
 *             $this->parseTextToArray($user['medical_conditions'])
 *         );
 *         $conditions = array_unique($conditions);
 *         $medications = $this->parseTextToArray($user['current_medications']);
 *
 *         $age = null;
 *         if ($user['birth_date']) {
 *             $birthDate = new DateTime($user['birth_date']);
 *             $today = new DateTime();
 *             $age = $birthDate->diff($today)->y;
 *         }
 *
 *         return [
 *             'userId' => $userId, 'found' => true,
 *             'displayName' => $user['display_name'], 'firstName' => $user['first_name'], 'lastName' => $user['last_name'],
 *             'allergies' => $allergies, 'conditions' => $conditions, 'currentMedications' => $medications,
 *             'weight' => $user['weight'] ? (float)$user['weight'] : null,
 *             'height' => $user['height'] ? (float)$user['height'] : null,
 *             'age' => $age, 'gender' => $user['gender'],
 *             'hasAllergies' => !empty($allergies), 'hasConditions' => !empty($conditions), 'hasMedications' => !empty($medications)
 *         ];
 *     } catch (PDOException $e) {
 *         error_log("PharmacyIntegration getUserMedicalHistory error: " . $e->getMessage());
 *         return ['userId' => $userId, 'found' => false, 'error' => $e->getMessage(),
 *                 'allergies' => [], 'conditions' => [], 'currentMedications' => []];
 *     }
 * }
 *
 * private function parseTextToArray(?string $text): array
 * {
 *     if (empty($text)) { return []; }
 *     $items = preg_split('/[,;\n]+/', $text);
 *     $items = array_map('trim', $items);
 *     $items = array_filter($items);
 *     return array_values($items);
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX (A) — `birth_date` -> `birthday`
 * ═══════════════════════════════════════════════════════════════════════
 * `users.birth_date` does not exist on tenant DBs (confirmed against
 * `packages/db/src/generated/tenant-db.d.ts`'s `Users` interface):
 * `birth_date` is an `admin_users`-only column (added by
 * `install/run_admin_user_fields_migration.php`, an internal-staff field
 * unrelated to customers). The `users` (customer) table instead has
 * `birthday` (used everywhere else in the codebase for this exact purpose —
 * `users.php`, `user-detail.php`, `api/member.php`,
 * `classes/AutoTagManager.php`, `modules/PDPA/Services/DataRightsService.php`)
 * and a separate `date_of_birth` column that is used only by the unrelated
 * `retail-api/` per CLAUDE.md, not the right source here.
 *
 * EFFECT IN CURRENT PRODUCTION: the explicit `SELECT ... birth_date ...`
 * above throws a PDOException ("Unknown column") on EVERY call — caught by
 * this method's own `catch (PDOException $e)`, which returns `found: false`
 * with an `error` string. Because `case 'medical_history':`'s
 * `sendResponse(['success' => $result['found'] ?? false, ...])` derives
 * `success` from `found`, this action ALWAYS returns `{success: false, ...}`
 * in production today, regardless of whether the user actually exists.
 * `patient_profile` and `check_allergy` are affected transitively (both call
 * this method, directly or indirectly).
 *
 * This is a deliberate, documented FIX-FORWARD deviation (not a
 * reproduction of the always-broken PHP behavior): the query below selects
 * the real `birthday` column, ALIASED to `birth_date` so the row-shaping
 * code below (age calculation) is otherwise unchanged. Same precedent
 * already set by Phase 4 batch 3's assign-conversation route and Phase 4
 * batch 4a's `drug-inventory`/`low-stock-drugs` (`is_prescription` ->
 * `requires_prescription`) — see
 * `docs/runbooks/phase4-batch3-inbox-actions-parity.md` §1 and
 * `../../drug-inventory/_lib/drugInventory.ts`'s module doc.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX (B) — `chronic_diseases` dropped from SELECT
 * ═══════════════════════════════════════════════════════════════════════
 * `users.chronic_diseases` does not exist in `packages/db/src/generated/
 * tenant-db.d.ts`'s `Users` interface — though it IS defined in the legacy
 * `database/install_complete.sql` / `database/schema_complete.sql` dumps, so
 * this is a template-vs-live drift, not a nonexistent concept. The same
 * concept already has an in-repo precedent of being sourced from
 * `medical_conditions` when a dedicated column isn't available:
 * `includes/ai-chat-context.php` line 124 sets
 * `$ctx['chronic_diseases'] = ... $user['medical_conditions'] ...` directly.
 *
 * Combined with fix (A), the `SELECT ... birth_date, ..., chronic_diseases,
 * ...` above would throw on EITHER unknown column even if only one were
 * fixed — both must be corrected together for the query to succeed at all.
 * This port drops `chronic_diseases` from the SELECT entirely. The PHP
 * source's `conditions` field is `array_unique(array_merge(
 * parseTextToArray($user['chronic_diseases']), parseTextToArray($user['medical_conditions'])))`
 * — with `chronic_diseases` removed, this degrades gracefully to
 * `array_unique(parseTextToArray($user['medical_conditions']))`, which is
 * strictly better than today's total failure (an empty `conditions: []` on
 * every call). This port implements exactly that reduced form (a single
 * `parseTextToArray(medical_conditions)` deduped) rather than merging in an
 * empty/absent second source for its own sake.
 *
 * (Note: PHP's `array_unique($conditions)` here is called WITHOUT a
 * following `array_values()`, so a duplicate removed from the middle of the
 * array leaves a gap in the PHP array's integer keys — which would make
 * PHP's `json_encode` emit a JSON *object* instead of an array for that one
 * response. This port intentionously does not reproduce that quirk: it is
 * an unintended PHP artifact (a missing `array_values()` call), not a
 * documented contract, and `[...new Set(...)]` — used here — reproduces the
 * INTENDED "deduplicate, preserve first-occurrence order" behavior as a
 * proper JSON array, which is what every real consumer of this field
 * expects.)
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `catch (PDOException $e)` is KEPT (this function still never throws)
 * ═══════════════════════════════════════════════════════════════════════
 * Fixes (A) and (B) remove the two columns that made this query always
 * fail — but the try/catch itself is preserved for any genuinely unrelated
 * DB failure (e.g. a dropped connection), exactly matching
 * `getDrugInventory`'s/`getLowStockDrugs`'s "DB errors are swallowed, not
 * thrown" pattern (see `../../drug-inventory/_lib/drugInventory.ts`'s
 * module doc) — `case 'medical_history':` has no case-level try/catch of
 * its own to fall back on.
 */

/**
 * PHP `parseTextToArray()` — split on comma/semicolon/newline, trim each
 * piece, then `array_filter()` with no callback (removes PHP-falsy values:
 * `''` and the exact string `'0'`), preserving order.
 *
 * Exported as the SINGLE-OWNER canonical implementation — `check-allergy`
 * and `patient-profile` do not need it directly (they consume this file's
 * `getUserMedicalHistory()` output, which already parsed these fields), but
 * it is exported here per this batch's brief as the shared helper.
 */
export function parseTextToArray(text: string | null | undefined): string[] {
  if (text === null || text === undefined || text === '' || text === '0') {
    return [];
  }
  return text
    .split(/[,;\n]+/)
    .map((item) => item.trim())
    .filter((item) => item !== '' && item !== '0');
}

/**
 * `DateTime::diff()->y` equivalent — full completed years between
 * `birthDate` and `today`, using local wall-clock date parts. mysql2
 * hydrates DATE columns via the Node process's local time zone (pinned to
 * Asia/Bangkok in production/CI per CLAUDE.md), matching PDO's unconverted
 * string read — same convention documented on
 * `../../messages/_lib/query.ts`'s `toMysqlDateTimeString()`.
 */
function calculateAge(birthDate: Date, today: Date): number {
  let age = today.getFullYear() - birthDate.getFullYear();
  const monthDiff = today.getMonth() - birthDate.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
    age -= 1;
  }
  return age;
}

/**
 * PHP `$v ? (float) $v : null` — PHP truthiness on the raw DB value (only
 * `null`, `''`, `0`, or the exact string `'0'` are falsy; any other numeric
 * string, including `'0.00'`, is truthy in PHP — but this port mirrors the
 * SAME simplified `Number(value) === 0 -> null` convention already
 * established by `../../drug-inventory/_lib/drugInventory.ts`'s
 * `phpTruthyToFloatOrNull()`, for consistency across this codebase rather
 * than a bespoke, more literal reimplementation here).
 */
function phpTruthyToFloatOrNull(value: unknown): number | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'number' && value === 0) return null;
  if (typeof value === 'string' && (value === '' || Number(value) === 0)) return null;
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

interface UserMedicalHistoryRow {
  id: number;
  display_name: string | null;
  first_name: string | null;
  last_name: string | null;
  weight: unknown;
  height: unknown;
  birth_date: Date | null;
  gender: string | null;
  drug_allergies: string | null;
  current_medications: string | null;
  medical_conditions: string | null;
}

export type MedicalHistoryResult =
  | {
      userId: number;
      found: false;
      allergies: [];
      conditions: [];
      currentMedications: [];
      weight: null;
      height: null;
      age: null;
      gender: null;
    }
  | {
      userId: number;
      found: false;
      error: string;
      allergies: [];
      conditions: [];
      currentMedications: [];
    }
  | {
      userId: number;
      found: true;
      displayName: string | null;
      firstName: string | null;
      lastName: string | null;
      allergies: string[];
      conditions: string[];
      currentMedications: string[];
      weight: number | null;
      height: number | null;
      age: number | null;
      gender: string | null;
      hasAllergies: boolean;
      hasConditions: boolean;
      hasMedications: boolean;
    };

export async function getUserMedicalHistory(db: Kysely<TenantDB>, userId: number): Promise<MedicalHistoryResult> {
  try {
    const result = await sql<UserMedicalHistoryRow>`
      SELECT
        id, display_name, first_name, last_name,
        weight, height, birthday AS birth_date, gender,
        drug_allergies, current_medications, medical_conditions
      FROM users
      WHERE id = ${userId}
    `.execute(db);
    const user = result.rows[0];

    if (!user) {
      return {
        userId,
        found: false,
        allergies: [],
        conditions: [],
        currentMedications: [],
        weight: null,
        height: null,
        age: null,
        gender: null,
      };
    }

    const allergies = parseTextToArray(user.drug_allergies);
    // FIX (B): chronic_diseases dropped from SELECT — see module doc.
    const conditions = [...new Set(parseTextToArray(user.medical_conditions))];
    const medications = parseTextToArray(user.current_medications);

    let age: number | null = null;
    if (user.birth_date) {
      age = calculateAge(user.birth_date, new Date());
    }

    return {
      userId,
      found: true,
      displayName: user.display_name,
      firstName: user.first_name,
      lastName: user.last_name,
      allergies,
      conditions,
      currentMedications: medications,
      weight: phpTruthyToFloatOrNull(user.weight),
      height: phpTruthyToFloatOrNull(user.height),
      age,
      gender: user.gender,
      hasAllergies: allergies.length > 0,
      hasConditions: conditions.length > 0,
      hasMedications: medications.length > 0,
    };
  } catch (error) {
    // PharmacyIntegrationService::getUserMedicalHistory()'s own `catch (PDOException $e)` — see module doc.
    const message = error instanceof Error ? error.message : String(error);
    return {
      userId,
      found: false,
      error: message,
      allergies: [],
      conditions: [],
      currentMedications: [],
    };
  }
}
