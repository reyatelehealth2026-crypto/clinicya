import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getMedications } from './customerHealthEngine';

/**
 * checkDrugInteractions.ts — OWN, INDEPENDENT port of
 * `classes/DrugRecommendEngineService.php`'s `checkInteractions()` (lines
 * 235-297), `findInteraction()` (345-388), and `getDrugNames()` (968-996),
 * as driven by api/inbox-v2.php's `case 'check_drug_interactions': case
 * 'check-drug-interactions':` (lines ~1363-1401).
 *
 * ```php
 * public function checkInteractions(array $drugIds, int $userId): array
 * {
 *     $drugNames = $this->getDrugNames($drugIds);
 *     $currentMedications = $this->getUserCurrentMedications($userId);
 *     $allDrugNames = array_merge($drugNames, $currentMedications);
 *
 *     if (count($allDrugNames) < 2) {
 *         return ['hasInteractions' => false, 'interactions' => [], 'severity' => null, 'checkedDrugs' => $allDrugNames];
 *     }
 *
 *     $interactions = [];
 *     $maxSeverity = null;
 *     $severityOrder = ['mild' => 1, 'moderate' => 2, 'severe' => 3, 'contraindicated' => 4];
 *
 *     foreach ($drugNames as $drug1) {
 *         foreach ($currentMedications as $drug2) {
 *             $interaction = $this->findInteraction($drug1, $drug2);
 *             if ($interaction) {
 *                 $interactions[] = $interaction;
 *                 $currentSeverityLevel = $severityOrder[$interaction['severity']] ?? 0;
 *                 $maxSeverityLevel = $severityOrder[$maxSeverity] ?? 0;
 *                 if ($currentSeverityLevel > $maxSeverityLevel) { $maxSeverity = $interaction['severity']; }
 *             }
 *         }
 *     }
 *
 *     // Also check interactions between the new drugs themselves
 *     for ($i = 0; $i < count($drugNames); $i++) {
 *         for ($j = $i + 1; $j < count($drugNames); $j++) {
 *             $interaction = $this->findInteraction($drugNames[$i], $drugNames[$j]);
 *             if ($interaction) {
 *                 $interactions[] = $interaction;
 *                 $currentSeverityLevel = $severityOrder[$interaction['severity']] ?? 0;
 *                 $maxSeverityLevel = $severityOrder[$maxSeverity] ?? 0;
 *                 if ($currentSeverityLevel > $maxSeverityLevel) { $maxSeverity = $interaction['severity']; }
 *             }
 *         }
 *     }
 *
 *     return [
 *         'hasInteractions' => !empty($interactions), 'interactions' => $interactions, 'severity' => $maxSeverity,
 *         'checkedDrugs' => $allDrugNames, 'newDrugs' => $drugNames, 'currentMedications' => $currentMedications
 *     ];
 * }
 * ```
 *
 * SAFETY NOTE — this is a DIFFERENT, INDEPENDENT algorithm from
 * `PharmacyIntegrationService::checkDrugInteractions()` (the one imported
 * by `../../check-interactions/_lib/checkInteractions.ts`): current-medication
 * x current-medication pairs are DELIBERATELY NEVER checked against each
 * other (only new-vs-current and new-vs-new), and severity tracking is a
 * MAX-so-far scan across BOTH passes combined (not first-found, not
 * last-found) — a `contraindicated` hit in pass 2 still wins over a `mild`
 * hit already recorded in pass 1. Ported literally via `severityOrder`
 * below; do not merge this with the sibling module's differently-shaped
 * severity constants even though the Thai label strings happen to overlap.
 *
 * ```php
 * private function findInteraction(string $drug1, string $drug2): ?array
 * {
 *     try {
 *         $stmt = $this->db->prepare("
 *             SELECT * FROM drug_interactions
 *             WHERE ((drug1_name LIKE ? OR drug1_generic LIKE ?) AND (drug2_name LIKE ? OR drug2_generic LIKE ?))
 *                OR ((drug1_name LIKE ? OR drug1_generic LIKE ?) AND (drug2_name LIKE ? OR drug2_generic LIKE ?))
 *             LIMIT 1
 *         ");
 *         $drug1Pattern = "%{$drug1}%"; $drug2Pattern = "%{$drug2}%";
 *         $stmt->execute([$drug1Pattern, $drug1Pattern, $drug2Pattern, $drug2Pattern, $drug2Pattern, $drug2Pattern, $drug1Pattern, $drug1Pattern]);
 *         $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($result) {
 *             return ['drug1' => $result['drug1_name'], 'drug1Generic' => $result['drug1_generic'], 'drug2' => $result['drug2_name'], 'drug2Generic' => $result['drug2_generic'], 'severity' => $result['severity'], 'description' => $result['description'], 'recommendation' => $result['recommendation']];
 *         }
 *         return null;
 *     } catch (PDOException $e) {
 *         error_log("DrugRecommendEngine findInteraction error: " . $e->getMessage());
 *         return null;
 *     }
 * }
 * ```
 *
 * This own `findInteraction()` has NO `LOWER()` calls (MySQL's default
 * `utf8mb4_unicode_ci` collation already makes `LIKE` case-insensitive, so
 * this is behaviorally equivalent to the `LOWER()`-wrapped sibling
 * version), NO `UNION` (a single `WHERE (...) OR (...)`), and returns NO
 * `id`/`source` key — do not conflate its result shape with
 * `../../patient-profile/_lib/patientProfile.ts`'s `DrugInteraction`.
 *
 * ```php
 * private function getDrugNames(array $drugIds): array
 * {
 *     if (empty($drugIds)) { return []; }
 *     $placeholders = implode(',', array_fill(0, count($drugIds), '?'));
 *     $stmt = $this->db->prepare("SELECT id, name, generic_name FROM business_items WHERE id IN ({$placeholders})");
 *     $stmt->execute($drugIds);
 *     $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *     $names = [];
 *     foreach ($drugs as $drug) {
 *         $names[] = $drug['name'];
 *         if (!empty($drug['generic_name'])) { $names[] = $drug['generic_name']; }
 *     }
 *     return array_unique($names);
 * }
 * ```
 *
 * `getUserCurrentMedications()` is NOT this class's own direct-query
 * fallback method — per this batch's brief, `CustomerHealthEngineService`
 * is always loaded and `setHealthEngine()` is always called in production,
 * so this port routes straight through the canonical
 * `./customerHealthEngine.ts`'s `getMedications()`, mapped to names (PHP's
 * own healthEngine branch: `array_column($this->healthEngine->getMedications($userId), 'name')`).
 * No `PDO`/`users.current_medications` fallback query is implemented here —
 * see `./customerHealthEngine.ts`'s module doc for why that branch is dead
 * code on every real request path.
 */

interface DrugNameRow {
  id: number;
  name: string;
  generic_name: string | null;
}

/** `empty($drug['generic_name'])` — PHP falsy-string check (`''`/`'0'`/`null`). */
function isPhpEmptyString(value: string | null | undefined): boolean {
  return value === null || value === undefined || value === '' || value === '0';
}

export async function getDrugNames(db: Kysely<TenantDB>, drugIds: number[]): Promise<string[]> {
  if (drugIds.length === 0) return [];

  try {
    const result = await sql<DrugNameRow>`
      SELECT id, name, generic_name FROM business_items WHERE id IN (${sql.join(drugIds)})
    `.execute(db);

    const names: string[] = [];
    for (const drug of result.rows) {
      names.push(drug.name);
      if (!isPhpEmptyString(drug.generic_name)) {
        names.push(drug.generic_name as string);
      }
    }
    return [...new Set(names)];
  } catch {
    return [];
  }
}

/**
 * `array_column($healthEngine->getMedications($userId), 'name')` — see
 * module doc for why this always routes through the canonical
 * `customerHealthEngine.ts` rather than reimplementing the dead-code
 * direct-query fallback.
 */
export async function getUserCurrentMedications(db: Kysely<TenantDB>, userId: number): Promise<string[]> {
  const medications = await getMedications(db, userId);
  return medications.map((m) => m.name);
}

export interface DrugInteractionEngineResult {
  drug1: string;
  drug1Generic: string | null;
  drug2: string;
  drug2Generic: string | null;
  severity: string;
  description: string | null;
  recommendation: string | null;
}

interface DrugInteractionEngineRow {
  drug1_name: string;
  drug1_generic: string | null;
  drug2_name: string;
  drug2_generic: string | null;
  severity: string;
  description: string | null;
  recommendation: string | null;
}

export async function findInteraction(db: Kysely<TenantDB>, drug1: string, drug2: string): Promise<DrugInteractionEngineResult | null> {
  try {
    const drug1Pattern = `%${drug1}%`;
    const drug2Pattern = `%${drug2}%`;

    const result = await sql<DrugInteractionEngineRow>`
      SELECT * FROM drug_interactions
      WHERE ((drug1_name LIKE ${drug1Pattern} OR drug1_generic LIKE ${drug1Pattern}) AND (drug2_name LIKE ${drug2Pattern} OR drug2_generic LIKE ${drug2Pattern}))
         OR ((drug1_name LIKE ${drug2Pattern} OR drug1_generic LIKE ${drug2Pattern}) AND (drug2_name LIKE ${drug1Pattern} OR drug2_generic LIKE ${drug1Pattern}))
      LIMIT 1
    `.execute(db);

    const row = result.rows[0];
    if (!row) return null;

    return {
      drug1: row.drug1_name,
      drug1Generic: row.drug1_generic,
      drug2: row.drug2_name,
      drug2Generic: row.drug2_generic,
      severity: row.severity,
      description: row.description,
      recommendation: row.recommendation,
    };
  } catch {
    return null;
  }
}

const SEVERITY_ORDER: Record<string, number> = { mild: 1, moderate: 2, severe: 3, contraindicated: 4 };

export interface CheckDrugInteractionsResult {
  hasInteractions: boolean;
  interactions: DrugInteractionEngineResult[];
  severity: string | null;
  checkedDrugs: string[];
  newDrugs?: string[];
  currentMedications?: string[];
}

export async function checkInteractions(db: Kysely<TenantDB>, drugIds: number[], userId: number): Promise<CheckDrugInteractionsResult> {
  const drugNames = await getDrugNames(db, drugIds);
  const currentMedications = await getUserCurrentMedications(db, userId);
  const allDrugNames = [...drugNames, ...currentMedications];

  if (allDrugNames.length < 2) {
    return { hasInteractions: false, interactions: [], severity: null, checkedDrugs: allDrugNames };
  }

  const interactions: DrugInteractionEngineResult[] = [];
  let maxSeverity: string | null = null;

  const trackSeverity = (interaction: DrugInteractionEngineResult) => {
    const currentLevel = SEVERITY_ORDER[interaction.severity] ?? 0;
    const maxLevel = maxSeverity !== null ? (SEVERITY_ORDER[maxSeverity] ?? 0) : 0;
    if (currentLevel > maxLevel) {
      maxSeverity = interaction.severity;
    }
  };

  // Pass 1: each new drug x each current medication.
  for (const drug1 of drugNames) {
    for (const drug2 of currentMedications) {
      const interaction = await findInteraction(db, drug1, drug2);
      if (interaction) {
        interactions.push(interaction);
        trackSeverity(interaction);
      }
    }
  }

  // Pass 2: new drugs against each other. current-medications x current-medications
  // pairs are deliberately NEVER checked — matches the literal PHP.
  for (let i = 0; i < drugNames.length; i++) {
    for (let j = i + 1; j < drugNames.length; j++) {
      const drug1 = drugNames[i];
      const drug2 = drugNames[j];
      if (drug1 === undefined || drug2 === undefined) continue;
      const interaction = await findInteraction(db, drug1, drug2);
      if (interaction) {
        interactions.push(interaction);
        trackSeverity(interaction);
      }
    }
  }

  return {
    hasInteractions: interactions.length > 0,
    interactions,
    severity: maxSeverity,
    checkedDrugs: allDrugNames,
    newDrugs: drugNames,
    currentMedications,
  };
}
