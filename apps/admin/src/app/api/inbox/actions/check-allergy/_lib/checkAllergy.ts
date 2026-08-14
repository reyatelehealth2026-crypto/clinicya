import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getUserMedicalHistory } from '../../medical-history/_lib/medicalHistory';

/**
 * checkAllergy.ts — port of `classes/PharmacyIntegrationService.php`'s
 * `checkUserAllergy()` (lines 382-409), as driven by api/inbox-v2.php's
 * `case 'check_allergy': case 'check-allergy':` (lines ~1088-1120).
 *
 * ```php
 * public function checkUserAllergy(int $userId, string $drugName): array
 * {
 *     $medicalHistory = $this->getUserMedicalHistory($userId);
 *     $allergies = $medicalHistory['allergies'] ?? [];
 *     $matchedAllergies = [];
 *
 *     $drugLower = mb_strtolower($drugName);
 *
 *     foreach ($allergies as $allergy) {
 *         $allergyLower = mb_strtolower($allergy);
 *
 *         // Check for direct match or partial match
 *         if (mb_strpos($drugLower, $allergyLower) !== false ||
 *             mb_strpos($allergyLower, $drugLower) !== false) {
 *             $matchedAllergies[] = [
 *                 'allergy' => $allergy,
 *                 'drug' => $drugName,
 *                 'matchType' => 'direct'
 *             ];
 *         }
 *     }
 *
 *     return [
 *         'hasAllergy' => !empty($matchedAllergies),
 *         'matchedAllergies' => $matchedAllergies,
 *         'allUserAllergies' => $allergies
 *     ];
 * }
 * ```
 *
 * Calls `getUserMedicalHistory` imported from
 * `../../medical-history/_lib/medicalHistory` — the documented single-owner
 * cross-route import (same "same builder, same round" precedent as Phase 4
 * batch 4a's `drug-info` -> `max-discount/_lib/drugPricingEngine`). This
 * means `check_allergy` automatically benefits from schema-drift fixes (A)
 * and (B) applied there (`birth_date` -> `birthday`, `chronic_diseases`
 * dropped) — before those fixes, `getUserMedicalHistory()` always returned
 * `found: false` with an `allergies: []` degraded result, so `check_allergy`
 * always reported `hasAllergy: false` regardless of the user's real
 * allergies.
 *
 * `mb_strpos($a, $b) !== false` (substring search, PHP's multi-byte-aware
 * variant for correct Thai-text byte handling) is ported as JS's
 * `String.prototype.includes()`, which is already Unicode-code-point-aware
 * (equivalent behavior for this use, including the "empty needle always
 * matches at position 0" edge case — moot in practice since
 * `getUserMedicalHistory()`'s `parseTextToArray()` never produces an empty
 * `allergy` string, and this route requires a non-empty `drugName`).
 * `mb_strtolower()` is ported as `.toLowerCase()`.
 */

export interface AllergyMatch {
  allergy: string;
  drug: string;
  matchType: 'direct';
}

export interface CheckAllergyResult {
  hasAllergy: boolean;
  matchedAllergies: AllergyMatch[];
  allUserAllergies: string[];
}

export async function checkUserAllergy(
  db: Kysely<TenantDB>,
  userId: number,
  drugName: string
): Promise<CheckAllergyResult> {
  const medicalHistory = await getUserMedicalHistory(db, userId);
  const allergies = medicalHistory.allergies ?? [];
  const matchedAllergies: AllergyMatch[] = [];

  const drugLower = drugName.toLowerCase();

  for (const allergy of allergies) {
    const allergyLower = allergy.toLowerCase();

    if (drugLower.includes(allergyLower) || allergyLower.includes(drugLower)) {
      matchedAllergies.push({ allergy, drug: drugName, matchType: 'direct' });
    }
  }

  return {
    hasAllergy: matchedAllergies.length > 0,
    matchedAllergies,
    allUserAllergies: allergies,
  };
}
