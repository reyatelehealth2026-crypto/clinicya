import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getUserMedicalHistory, type MedicalHistoryResult } from '../../medical-history/_lib/medicalHistory';
import { getUserPrescriptionHistory, type PrescriptionHistoryRowJson } from '../../prescription-history/_lib/prescriptionHistory';

/**
 * patientProfile.ts — port of `classes/PharmacyIntegrationService.php`'s
 * `getComprehensivePatientProfile()` (lines 842-884), as driven by
 * api/inbox-v2.php's `case 'patient_profile': case 'patient-profile': case
 * 'get_patient_profile':` (lines ~952-980).
 *
 * ```php
 * public function getComprehensivePatientProfile(int $userId): array
 * {
 *     $medicalHistory = $this->getUserMedicalHistory($userId);
 *     $tagsAndNotes = $this->getUserTagsAndNotes($userId);
 *     $prescriptionHistory = $this->getUserPrescriptionHistory($userId, 10);
 *
 *     $currentMeds = $medicalHistory['currentMedications'] ?? [];
 *     $interactionCheck = [];
 *     if (count($currentMeds) > 1) {
 *         $interactionCheck = $this->checkDrugInteractions($currentMeds);
 *     }
 *
 *     return [
 *         'userId' => $userId,
 *         'found' => $medicalHistory['found'],
 *         'displayName' => $medicalHistory['displayName'] ?? null,
 *         'demographics' => [
 *             'age' => $medicalHistory['age'], 'gender' => $medicalHistory['gender'],
 *             'weight' => $medicalHistory['weight'], 'height' => $medicalHistory['height']
 *         ],
 *         'health' => [
 *             'allergies' => $medicalHistory['allergies'], 'conditions' => $medicalHistory['conditions'],
 *             'currentMedications' => $medicalHistory['currentMedications'],
 *             'hasAllergies' => $medicalHistory['hasAllergies'] ?? false,
 *             'hasConditions' => $medicalHistory['hasConditions'] ?? false,
 *             'hasMedications' => $medicalHistory['hasMedications'] ?? false
 *         ],
 *         'tags' => $tagsAndNotes['tags'], 'notes' => $tagsAndNotes['notes'],
 *         'prescriptionHistory' => $prescriptionHistory,
 *         'currentMedicationInteractions' => $interactionCheck,
 *         'warnings' => $this->generatePatientWarnings($medicalHistory, $interactionCheck)
 *     ];
 * }
 * ```
 *
 * Calls `getUserMedicalHistory` (imported from
 * `../../medical-history/_lib/medicalHistory`) and `getUserPrescriptionHistory`
 * (imported from `../../prescription-history/_lib/prescriptionHistory`,
 * called with `limit=10` per PHP line 851) — the documented single-owner
 * cross-route imports for this batch (same "same builder, same round"
 * precedent as Phase 4 batch 4a's `drug-info` -> `max-discount/_lib/drugPricingEngine`).
 * `patient_profile` therefore transitively benefits from schema-drift fixes
 * (A)/(B) (medical history) and (D) (prescription history).
 *
 * `getUserTagsAndNotes()`, `checkDrugInteractions()`/`findInteraction()`/
 * `getHigherSeverity()`/`getSeverityLabel()`, and `generatePatientWarnings()`
 * are ported HERE as private implementation details of this one action —
 * per this batch's brief, these are used ONLY as internal building blocks
 * of `getComprehensivePatientProfile()` (`checkDrugInteractions` is invoked
 * only when `count(currentMedications) > 1`, PHP line 856) and are
 * DELIBERATELY NOT exposed at their own route — in particular this is NOT a
 * port of the separate `check_drug_interactions` action, which stays out of
 * scope this round.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX (C) — `note_type` dropped from `customer_notes` SELECT
 * ═══════════════════════════════════════════════════════════════════════
 * `customer_notes.note_type` does not exist anywhere in
 * `packages/db/src/generated/tenant-db.d.ts` or any committed SQL file
 * (verified genuinely absent, not merely drifted — unlike fixes (A)/(B)/(D),
 * there is no legacy dump or "real column with a different name" to fall
 * back to). PHP's own `getUserTagsAndNotes()` wraps the `customer_notes`
 * query in its OWN local `catch (PDOException $e)` (independent from the
 * `user_tags`/`user_tag_assignments` query's try/catch — see PHP source
 * below), so this bug does NOT affect the tags half of the response at all;
 * it silently degrades `notes` to `[]` on every call. This port drops
 * `note_type` from the SELECT so `notes` is populated with real data
 * instead.
 *
 * ```php
 * public function getUserTagsAndNotes(int $userId): array
 * {
 *     $result = ['tags' => [], 'notes' => []];
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT ut.id, ut.name, ut.color, ut.description
 *             FROM user_tags ut
 *             JOIN user_tag_assignments uta ON ut.id = uta.tag_id
 *             WHERE uta.user_id = ?
 *         ");
 *         $stmt->execute([$userId]);
 *         $result['tags'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *     } catch (PDOException $e) { // Tags table might not exist
 *     }
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT id, note, note_type, created_at, created_by
 *             FROM customer_notes
 *             WHERE user_id = ?
 *             ORDER BY created_at DESC
 *             LIMIT 10
 *         ");
 *         $stmt->execute([$userId]);
 *         $result['notes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *     } catch (PDOException $e) { // Notes table might not exist
 *     }
 *     return $result;
 * }
 * ```
 *
 * `user_tags`/`user_tag_assignments` (columns `id`/`name`/`color`/
 * `description`, `tag_id`/`user_id`) are all confirmed present in
 * `tenant-db.d.ts` — that half of the query is a fully literal, unmodified
 * port.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `checkDrugInteractions()` / `findInteraction()` (PHP lines 45-146)
 * ═══════════════════════════════════════════════════════════════════════
 * ```php
 * const SEVERITY_CONTRAINDICATED = 'contraindicated';
 * const SEVERITY_SEVERE = 'severe';
 * const SEVERITY_MODERATE = 'moderate';
 * const SEVERITY_MILD = 'mild';
 *
 * public function checkDrugInteractions(array $drugNames, ?int $userId = null): array
 * {
 *     $interactions = [];
 *     $highestSeverity = self::SEVERITY_MILD;
 *     $currentMedications = [];
 *     if ($userId) {
 *         $healthData = $this->getUserMedicalHistory($userId);
 *         $currentMedications = $healthData['currentMedications'] ?? [];
 *     }
 *     $allDrugs = array_merge($drugNames, $currentMedications);
 *     $allDrugs = array_unique(array_filter($allDrugs));
 *     for ($i = 0; $i < count($allDrugs); $i++) {
 *         for ($j = $i + 1; $j < count($allDrugs); $j++) {
 *             $interaction = $this->findInteraction($allDrugs[$i], $allDrugs[$j]);
 *             if ($interaction) {
 *                 $interactions[] = $interaction;
 *                 $highestSeverity = $this->getHigherSeverity($highestSeverity, $interaction['severity']);
 *             }
 *         }
 *     }
 *     return [
 *         'hasInteractions' => !empty($interactions), 'interactions' => $interactions,
 *         'severity' => $highestSeverity, 'severityLabel' => $this->getSeverityLabel($highestSeverity),
 *         'drugsChecked' => $allDrugs, 'interactionCount' => count($interactions)
 *     ];
 * }
 * ```
 * This port's ONLY caller passes just `$currentMeds` (no `$userId`), so the
 * `$userId`-driven branch is included for fidelity but is dead code on this
 * call path (mirrored anyway, in case of future direct testing).
 *
 * (Note: PHP's `array_unique(array_filter($allDrugs))` — WITHOUT a
 * following `array_values()` — can leave gaps in the resulting array's
 * integer keys when a duplicate is removed from the middle, and the
 * subsequent `for ($i = 0; $i < count($allDrugs); $i++) { ... $allDrugs[$i]
 * ... }` loop indexes by sequential integer assuming NO gaps — a genuine,
 * obscure PHP bug reachable only with duplicate drug names. This port does
 * not reproduce that artifact: `[...new Set(...)]` implements the CLEARLY
 * INTENDED "dedupe, preserve order" behavior as a dense array, which is
 * what every realistic input produces correctly under either
 * implementation anyway.)
 *
 * ```php
 * private function findInteraction(string $drug1, string $drug2): ?array
 * {
 *     try {
 *         $tableCheck = $this->db->query("SHOW TABLES LIKE 'drug_interactions'");
 *         if ($tableCheck->rowCount() === 0) { return null; }
 *         $drug1Lower = mb_strtolower(trim($drug1));
 *         $drug2Lower = mb_strtolower(trim($drug2));
 *         $stmt = $this->db->prepare("
 *             SELECT id, drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation
 *             FROM drug_interactions
 *             WHERE (LOWER(drug1_name) LIKE ? OR LOWER(drug1_generic) LIKE ?) AND (LOWER(drug2_name) LIKE ? OR LOWER(drug2_generic) LIKE ?)
 *             UNION
 *             SELECT id, drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation
 *             FROM drug_interactions
 *             WHERE (LOWER(drug1_name) LIKE ? OR LOWER(drug1_generic) LIKE ?) AND (LOWER(drug2_name) LIKE ? OR LOWER(drug2_generic) LIKE ?)
 *             LIMIT 1
 *         ");
 *         $stmt->execute([
 *             "%{$drug1Lower}%", "%{$drug1Lower}%", "%{$drug2Lower}%", "%{$drug2Lower}%",
 *             "%{$drug2Lower}%", "%{$drug2Lower}%", "%{$drug1Lower}%", "%{$drug1Lower}%"
 *         ]);
 *         $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($result) {
 *             return [
 *                 'id' => $result['id'], 'drug1' => $result['drug1_name'], 'drug1Generic' => $result['drug1_generic'],
 *                 'drug2' => $result['drug2_name'], 'drug2Generic' => $result['drug2_generic'],
 *                 'severity' => $result['severity'] ?? self::SEVERITY_MODERATE,
 *                 'description' => $result['description'], 'recommendation' => $result['recommendation'],
 *                 'source' => 'database'
 *             ];
 *         }
 *         return null;
 *     } catch (PDOException $e) {
 *         error_log("PharmacyIntegration findInteraction error: " . $e->getMessage());
 *         return null;
 *     }
 * }
 * ```
 *
 * SIMPLIFICATION: the `SHOW TABLES LIKE 'drug_interactions'` existence
 * guard is dropped — `drug_interactions` is confirmed present in
 * `packages/db/src/generated/tenant-db.d.ts` (with every column this query
 * touches: `id`/`drug1_name`/`drug1_generic`/`drug2_name`/`drug2_generic`/
 * `severity`/`description`/`recommendation`), so the guard is unreachable
 * on the committed schema. Same precedent as
 * `../max-discount/_lib/drugPricingEngine.ts`'s dropped `cost_price`
 * existence-check fallback (Phase 4 batch 4a).
 *
 * ```php
 * private function getHigherSeverity(string $severity1, string $severity2): string
 * {
 *     $order = [self::SEVERITY_MILD => 1, self::SEVERITY_MODERATE => 2, self::SEVERITY_SEVERE => 3, self::SEVERITY_CONTRAINDICATED => 4];
 *     $level1 = $order[$severity1] ?? 1;
 *     $level2 = $order[$severity2] ?? 1;
 *     return $level1 >= $level2 ? $severity1 : $severity2;
 * }
 *
 * private function getSeverityLabel(string $severity): string
 * {
 *     $labels = [self::SEVERITY_MILD => 'เล็กน้อย', self::SEVERITY_MODERATE => 'ปานกลาง', self::SEVERITY_SEVERE => 'รุนแรง', self::SEVERITY_CONTRAINDICATED => 'ห้ามใช้ร่วมกัน'];
 *     return $labels[$severity] ?? 'ไม่ระบุ';
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `generatePatientWarnings()` (PHP lines 893-943) — exact Thai strings kept
 * ═══════════════════════════════════════════════════════════════════════
 * ```php
 * private function generatePatientWarnings(array $medicalHistory, array $interactionCheck): array
 * {
 *     $warnings = [];
 *     if (!empty($medicalHistory['allergies'])) {
 *         $warnings[] = ['type' => 'allergy', 'severity' => 'high',
 *             'message' => 'ลูกค้าแพ้ยา: ' . implode(', ', $medicalHistory['allergies']),
 *             'icon' => 'fa-exclamation-triangle', 'color' => 'red'];
 *     }
 *     if (!empty($medicalHistory['conditions'])) {
 *         $warnings[] = ['type' => 'condition', 'severity' => 'medium',
 *             'message' => 'โรคประจำตัว: ' . implode(', ', $medicalHistory['conditions']),
 *             'icon' => 'fa-heartbeat', 'color' => 'orange'];
 *     }
 *     if (!empty($interactionCheck['interactions'])) {
 *         $warnings[] = ['type' => 'interaction',
 *             'severity' => $interactionCheck['severity'] === self::SEVERITY_CONTRAINDICATED ? 'critical' : 'high',
 *             'message' => 'พบยาตีกันในยาที่ใช้อยู่ ' . count($interactionCheck['interactions']) . ' คู่',
 *             'icon' => 'fa-pills', 'color' => 'purple', 'details' => $interactionCheck['interactions']];
 *     }
 *     if (isset($medicalHistory['age']) && $medicalHistory['age'] >= 65) {
 *         $warnings[] = ['type' => 'elderly', 'severity' => 'medium',
 *             'message' => 'ผู้สูงอายุ (อายุ ' . $medicalHistory['age'] . ' ปี) - ควรระวังขนาดยา',
 *             'icon' => 'fa-user-clock', 'color' => 'blue'];
 *     }
 *     return $warnings;
 * }
 * ```
 * `isset($medicalHistory['age'])` is `false` for BOTH a missing key (the
 * `found: false` "error" variant of `MedicalHistoryResult` has no `age`
 * key at all) AND an explicit `null` value (the `found: false` "not found"
 * variant has `age: null`) — PHP's `isset()` returns `false` for `null`
 * values. Ported via an explicit `age !== null` check after a safe
 * property read, matching both cases.
 */

// ─────────────────────────────────────────────────────────────────────────
// getUserTagsAndNotes()
// ─────────────────────────────────────────────────────────────────────────

export interface UserTagRow {
  id: number;
  name: string;
  color: string | null;
  description: string | null;
}

interface CustomerNoteRow {
  id: number;
  note: string;
  created_at: Date;
  created_by: number | null;
}

export interface CustomerNoteRowJson extends Omit<CustomerNoteRow, 'created_at'> {
  created_at: string;
}

export interface TagsAndNotesResult {
  tags: UserTagRow[];
  notes: CustomerNoteRowJson[];
}

/** `YYYY-MM-DD HH:MM:SS` in local wall-clock — see `../../prescription-history/_lib/prescriptionHistory.ts`. */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

export async function getUserTagsAndNotes(db: Kysely<TenantDB>, userId: number): Promise<TagsAndNotesResult> {
  const result: TagsAndNotesResult = { tags: [], notes: [] };

  try {
    const tagsResult = await sql<UserTagRow>`
      SELECT ut.id, ut.name, ut.color, ut.description
      FROM user_tags ut
      JOIN user_tag_assignments uta ON ut.id = uta.tag_id
      WHERE uta.user_id = ${userId}
    `.execute(db);
    result.tags = tagsResult.rows;
  } catch {
    // user_tags/user_tag_assignments table might not exist — PHP's own swallow.
  }

  try {
    // FIX (C): note_type dropped from SELECT — see module doc.
    const notesResult = await sql<CustomerNoteRow>`
      SELECT id, note, created_at, created_by
      FROM customer_notes
      WHERE user_id = ${userId}
      ORDER BY created_at DESC
      LIMIT 10
    `.execute(db);
    result.notes = notesResult.rows.map((row) => ({ ...row, created_at: toMysqlDateTimeString(row.created_at) }));
  } catch {
    // customer_notes table might not exist — PHP's own swallow.
  }

  return result;
}

// ─────────────────────────────────────────────────────────────────────────
// checkDrugInteractions() / findInteraction() / severity helpers
// ─────────────────────────────────────────────────────────────────────────

export const SEVERITY_CONTRAINDICATED = 'contraindicated';
export const SEVERITY_SEVERE = 'severe';
export const SEVERITY_MODERATE = 'moderate';
export const SEVERITY_MILD = 'mild';

export type Severity =
  | typeof SEVERITY_MILD
  | typeof SEVERITY_MODERATE
  | typeof SEVERITY_SEVERE
  | typeof SEVERITY_CONTRAINDICATED;

export interface DrugInteraction {
  id: number;
  drug1: string;
  drug1Generic: string | null;
  drug2: string;
  drug2Generic: string | null;
  severity: Severity;
  description: string | null;
  recommendation: string | null;
  source: 'database';
}

export interface DrugInteractionCheckResult {
  hasInteractions: boolean;
  interactions: DrugInteraction[];
  severity: Severity;
  severityLabel: string;
  drugsChecked: string[];
  interactionCount: number;
}

interface DrugInteractionRow {
  id: number;
  drug1_name: string;
  drug1_generic: string | null;
  drug2_name: string;
  drug2_generic: string | null;
  severity: Severity | null;
  description: string | null;
  recommendation: string | null;
}

export function getHigherSeverity(severity1: Severity, severity2: Severity): Severity {
  const order: Record<Severity, number> = {
    [SEVERITY_MILD]: 1,
    [SEVERITY_MODERATE]: 2,
    [SEVERITY_SEVERE]: 3,
    [SEVERITY_CONTRAINDICATED]: 4,
  };
  const level1 = order[severity1] ?? 1;
  const level2 = order[severity2] ?? 1;
  return level1 >= level2 ? severity1 : severity2;
}

export function getSeverityLabel(severity: Severity): string {
  const labels: Record<Severity, string> = {
    [SEVERITY_MILD]: 'เล็กน้อย',
    [SEVERITY_MODERATE]: 'ปานกลาง',
    [SEVERITY_SEVERE]: 'รุนแรง',
    [SEVERITY_CONTRAINDICATED]: 'ห้ามใช้ร่วมกัน',
  };
  return labels[severity] ?? 'ไม่ระบุ';
}

async function findInteraction(
  db: Kysely<TenantDB>,
  drug1: string,
  drug2: string
): Promise<DrugInteraction | null> {
  try {
    const drug1Lower = drug1.trim().toLowerCase();
    const drug2Lower = drug2.trim().toLowerCase();
    const d1 = `%${drug1Lower}%`;
    const d2 = `%${drug2Lower}%`;

    const result = await sql<DrugInteractionRow>`
      SELECT id, drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation
      FROM drug_interactions
      WHERE (LOWER(drug1_name) LIKE ${d1} OR LOWER(drug1_generic) LIKE ${d1})
        AND (LOWER(drug2_name) LIKE ${d2} OR LOWER(drug2_generic) LIKE ${d2})
      UNION
      SELECT id, drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation
      FROM drug_interactions
      WHERE (LOWER(drug1_name) LIKE ${d2} OR LOWER(drug1_generic) LIKE ${d2})
        AND (LOWER(drug2_name) LIKE ${d1} OR LOWER(drug2_generic) LIKE ${d1})
      LIMIT 1
    `.execute(db);

    const row = result.rows[0];
    if (!row) return null;

    return {
      id: row.id,
      drug1: row.drug1_name,
      drug1Generic: row.drug1_generic,
      drug2: row.drug2_name,
      drug2Generic: row.drug2_generic,
      severity: row.severity ?? SEVERITY_MODERATE,
      description: row.description,
      recommendation: row.recommendation,
      source: 'database',
    };
  } catch {
    // PharmacyIntegrationService::findInteraction()'s own `catch (PDOException $e)` — see module doc.
    return null;
  }
}

export async function checkDrugInteractions(
  db: Kysely<TenantDB>,
  drugNames: string[],
  userId: number | null = null
): Promise<DrugInteractionCheckResult> {
  const interactions: DrugInteraction[] = [];
  let highestSeverity: Severity = SEVERITY_MILD;

  let currentMedications: string[] = [];
  if (userId) {
    const healthData = await getUserMedicalHistory(db, userId);
    currentMedications = healthData.currentMedications ?? [];
  }

  const allDrugs = [...new Set([...drugNames, ...currentMedications].filter((d) => d !== '' && d !== '0'))];

  for (let i = 0; i < allDrugs.length; i++) {
    for (let j = i + 1; j < allDrugs.length; j++) {
      const drug1 = allDrugs[i];
      const drug2 = allDrugs[j];
      if (drug1 === undefined || drug2 === undefined) continue;

      const interaction = await findInteraction(db, drug1, drug2);
      if (interaction) {
        interactions.push(interaction);
        highestSeverity = getHigherSeverity(highestSeverity, interaction.severity);
      }
    }
  }

  return {
    hasInteractions: interactions.length > 0,
    interactions,
    severity: highestSeverity,
    severityLabel: getSeverityLabel(highestSeverity),
    drugsChecked: allDrugs,
    interactionCount: interactions.length,
  };
}

// ─────────────────────────────────────────────────────────────────────────
// generatePatientWarnings()
// ─────────────────────────────────────────────────────────────────────────

export interface PatientWarning {
  type: 'allergy' | 'condition' | 'interaction' | 'elderly';
  severity: 'high' | 'medium' | 'critical';
  message: string;
  icon: string;
  color: string;
  details?: DrugInteraction[];
}

/** Safe property read across `MedicalHistoryResult`'s three variants, matching PHP's `$arr['key'] ?? $default` for a possibly-absent key. */
function readMaybe<K extends string, V>(obj: Record<string, unknown>, key: K, fallback: V): V {
  return key in obj ? ((obj as Record<K, V>)[key] ?? fallback) : fallback;
}

export function generatePatientWarnings(
  medicalHistory: MedicalHistoryResult,
  interactionCheck: DrugInteractionCheckResult | []
): PatientWarning[] {
  const warnings: PatientWarning[] = [];
  const mh = medicalHistory as unknown as Record<string, unknown>;

  if (medicalHistory.allergies.length > 0) {
    warnings.push({
      type: 'allergy',
      severity: 'high',
      message: `ลูกค้าแพ้ยา: ${medicalHistory.allergies.join(', ')}`,
      icon: 'fa-exclamation-triangle',
      color: 'red',
    });
  }

  if (medicalHistory.conditions.length > 0) {
    warnings.push({
      type: 'condition',
      severity: 'medium',
      message: `โรคประจำตัว: ${medicalHistory.conditions.join(', ')}`,
      icon: 'fa-heartbeat',
      color: 'orange',
    });
  }

  if ('interactions' in interactionCheck && interactionCheck.interactions.length > 0) {
    warnings.push({
      type: 'interaction',
      severity: interactionCheck.severity === SEVERITY_CONTRAINDICATED ? 'critical' : 'high',
      message: `พบยาตีกันในยาที่ใช้อยู่ ${interactionCheck.interactions.length} คู่`,
      icon: 'fa-pills',
      color: 'purple',
      details: interactionCheck.interactions,
    });
  }

  // `isset($medicalHistory['age'])` — false for both a missing key AND an explicit null.
  const age = readMaybe<'age', number | null>(mh, 'age', null);
  if (age !== null && age >= 65) {
    warnings.push({
      type: 'elderly',
      severity: 'medium',
      message: `ผู้สูงอายุ (อายุ ${age} ปี) - ควรระวังขนาดยา`,
      icon: 'fa-user-clock',
      color: 'blue',
    });
  }

  return warnings;
}

// ─────────────────────────────────────────────────────────────────────────
// getComprehensivePatientProfile()
// ─────────────────────────────────────────────────────────────────────────

export interface ComprehensivePatientProfile {
  userId: number;
  found: boolean;
  displayName: string | null;
  demographics: {
    age: number | null;
    gender: string | null;
    weight: number | null;
    height: number | null;
  };
  health: {
    allergies: string[];
    conditions: string[];
    currentMedications: string[];
    hasAllergies: boolean;
    hasConditions: boolean;
    hasMedications: boolean;
  };
  tags: UserTagRow[];
  notes: CustomerNoteRowJson[];
  prescriptionHistory: PrescriptionHistoryRowJson[];
  currentMedicationInteractions: DrugInteractionCheckResult | [];
  warnings: PatientWarning[];
}

export async function getComprehensivePatientProfile(
  db: Kysely<TenantDB>,
  userId: number
): Promise<ComprehensivePatientProfile> {
  const medicalHistory = await getUserMedicalHistory(db, userId);
  const tagsAndNotes = await getUserTagsAndNotes(db, userId);
  const prescriptionHistory = await getUserPrescriptionHistory(db, userId, 10);

  const mh = medicalHistory as unknown as Record<string, unknown>;
  const currentMeds = medicalHistory.currentMedications;

  let interactionCheck: DrugInteractionCheckResult | [] = [];
  if (currentMeds.length > 1) {
    interactionCheck = await checkDrugInteractions(db, currentMeds);
  }

  return {
    userId,
    found: medicalHistory.found,
    displayName: readMaybe<'displayName', string | null>(mh, 'displayName', null),
    demographics: {
      age: readMaybe<'age', number | null>(mh, 'age', null),
      gender: readMaybe<'gender', string | null>(mh, 'gender', null),
      weight: readMaybe<'weight', number | null>(mh, 'weight', null),
      height: readMaybe<'height', number | null>(mh, 'height', null),
    },
    health: {
      allergies: medicalHistory.allergies,
      conditions: medicalHistory.conditions,
      currentMedications: medicalHistory.currentMedications,
      hasAllergies: readMaybe<'hasAllergies', boolean>(mh, 'hasAllergies', false),
      hasConditions: readMaybe<'hasConditions', boolean>(mh, 'hasConditions', false),
      hasMedications: readMaybe<'hasMedications', boolean>(mh, 'hasMedications', false),
    },
    tags: tagsAndNotes.tags,
    notes: tagsAndNotes.notes,
    prescriptionHistory,
    currentMedicationInteractions: interactionCheck,
    warnings: generatePatientWarnings(medicalHistory, interactionCheck),
  };
}
