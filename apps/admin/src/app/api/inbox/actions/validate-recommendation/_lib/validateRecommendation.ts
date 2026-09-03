import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getDrugInventory, type DrugInventoryResult } from '../../drug-inventory/_lib/drugInventory';
import { getUserMedicalHistory } from '../../medical-history/_lib/medicalHistory';
import { checkDrugInteractions, SEVERITY_CONTRAINDICATED, type Severity } from '../../patient-profile/_lib/patientProfile';

/**
 * validateRecommendation.ts — port of `classes/PharmacyIntegrationService.php`'s
 * `validateDrugRecommendation()` (lines 952-1025), as driven by
 * api/inbox-v2.php's `case 'validate_recommendation': case
 * 'validate-recommendation':` (lines ~1052-1081).
 *
 * ```php
 * public function validateDrugRecommendation(int $userId, int $productId): array
 * {
 *     $issues = [];
 *     $canRecommend = true;
 *
 *     $drugInfo = $this->getDrugInventory($productId);
 *     if (!$drugInfo['found']) {
 *         return ['canRecommend' => false, 'issues' => [['type' => 'not_found', 'message' => 'ไม่พบข้อมูลยา']]];
 *     }
 *
 *     if (!$drugInfo['inStock']) {
 *         $issues[] = ['type' => 'out_of_stock', 'message' => 'ยาหมดสต็อก', 'severity' => 'high'];
 *         $canRecommend = false;
 *     }
 *
 *     $medicalHistory = $this->getUserMedicalHistory($userId);
 *
 *     $drugName = $drugInfo['name'];
 *     $genericName = $drugInfo['genericName'] ?? '';
 *
 *     foreach ($medicalHistory['allergies'] as $allergy) {
 *         $allergyLower = mb_strtolower($allergy);
 *         if (mb_strpos(mb_strtolower($drugName), $allergyLower) !== false ||
 *             mb_strpos(mb_strtolower($genericName), $allergyLower) !== false) {
 *             $issues[] = ['type' => 'allergy', 'message' => "ลูกค้าแพ้ยา: {$allergy}", 'severity' => 'critical'];
 *             $canRecommend = false;
 *         }
 *     }
 *
 *     $currentMeds = $medicalHistory['currentMedications'];
 *     if (!empty($currentMeds)) {
 *         $interactionCheck = $this->checkDrugInteractions([$drugName, $genericName], $userId);
 *         if ($interactionCheck['hasInteractions']) {
 *             foreach ($interactionCheck['interactions'] as $interaction) {
 *                 $issues[] = [
 *                     'type' => 'interaction',
 *                     'message' => "ยาตีกับ {$interaction['drug2']}: {$interaction['description']}",
 *                     'severity' => $interaction['severity'],
 *                     'recommendation' => $interaction['recommendation']
 *                 ];
 *                 if ($interaction['severity'] === self::SEVERITY_CONTRAINDICATED) { $canRecommend = false; }
 *             }
 *         }
 *     }
 *
 *     return [
 *         'canRecommend' => $canRecommend, 'drugInfo' => $drugInfo, 'issues' => $issues,
 *         'issueCount' => count($issues), 'hasCriticalIssues' => !$canRecommend
 *     ];
 * }
 * ```
 *
 * This is almost entirely COMPOSITION, per this batch's brief — the three
 * building blocks are all imported read-only from already-merged sibling
 * routes, never reimplemented:
 *   - `getDrugInventory` from `../../drug-inventory/_lib/drugInventory.ts`
 *     (Phase 4 batch 4a — carries its own `is_prescription` ->
 *     `requires_prescription` schema-drift fix, transitively inherited here).
 *   - `getUserMedicalHistory` from `../../medical-history/_lib/medicalHistory.ts`
 *     (Phase 4 batch 4b — carries schema-drift fixes (A)/(B), transitively
 *     inherited here).
 *   - `checkDrugInteractions` from `../../patient-profile/_lib/patientProfile.ts`
 *     (Phase 4 batch 4b) — called with `[drugName, genericName]` and the
 *     real `userId` (NOT `null` — this differs from `check-interactions`'s
 *     own call, which passes `userId ?: null`; PHP's `validateDrugRecommendation()`
 *     always passes the real `$userId`, so `checkDrugInteractions()`
 *     additionally merges in the user's OWN current medications via its own
 *     internal `getUserMedicalHistory()` call). `genericName` may be `''`
 *     (`$drugInfo['genericName'] ?? ''`) — `checkDrugInteractions()`'s own
 *     `array_unique(array_filter(...))`-equivalent drops empty entries, so
 *     this is safe.
 *
 * `SEVERITY_CONTRAINDICATED` is the same exported constant `checkDrugInteractions`
 * itself uses internally — imported directly rather than re-declared, so a
 * severity-string typo can't silently desync the two.
 *
 * `mb_strpos(...) !== false` — ported as JS `.includes()` (see
 * `../../check-allergy/_lib/checkAllergy.ts`'s doc for the equivalence
 * rationale — this is the SAME 2-way substring check `checkUserAllergy()`
 * uses, duplicated here since PHP's own `validateDrugRecommendation()` does
 * its own allergy loop rather than calling `checkUserAllergy()`).
 */

/** `mb_strpos($a, $b) !== false` — case-normalized substring search (2-way, matching the literal PHP: only drugLower-in-allergy and genericLower-in-allergy, NOT the reverse direction). */
function ciIncludesLower(haystackLower: string, needleLower: string): boolean {
  return haystackLower.includes(needleLower);
}

export interface ValidationIssueNotFound {
  type: 'not_found';
  message: string;
}

export interface ValidationIssueOutOfStock {
  type: 'out_of_stock';
  message: string;
  severity: 'high';
}

export interface ValidationIssueAllergy {
  type: 'allergy';
  message: string;
  severity: 'critical';
}

export interface ValidationIssueInteraction {
  type: 'interaction';
  message: string;
  severity: Severity;
  recommendation: string | null;
}

export type ValidationIssue = ValidationIssueOutOfStock | ValidationIssueAllergy | ValidationIssueInteraction;

export type ValidateDrugRecommendationResult =
  | { canRecommend: false; issues: [ValidationIssueNotFound] }
  | {
      canRecommend: boolean;
      drugInfo: DrugInventoryResult;
      issues: ValidationIssue[];
      issueCount: number;
      hasCriticalIssues: boolean;
    };

export async function validateDrugRecommendation(db: Kysely<TenantDB>, userId: number, productId: number): Promise<ValidateDrugRecommendationResult> {
  const issues: ValidationIssue[] = [];
  let canRecommend = true;

  const drugInfo = await getDrugInventory(db, productId);
  if (!drugInfo.found) {
    return { canRecommend: false, issues: [{ type: 'not_found', message: 'ไม่พบข้อมูลยา' }] };
  }

  if (!drugInfo.inStock) {
    issues.push({ type: 'out_of_stock', message: 'ยาหมดสต็อก', severity: 'high' });
    canRecommend = false;
  }

  const medicalHistory = await getUserMedicalHistory(db, userId);

  const drugName = drugInfo.name;
  const genericName = drugInfo.genericName ?? '';
  const drugNameLower = drugName.toLowerCase();
  const genericNameLower = genericName.toLowerCase();

  for (const allergy of medicalHistory.allergies) {
    const allergyLower = allergy.toLowerCase();
    if (ciIncludesLower(drugNameLower, allergyLower) || ciIncludesLower(genericNameLower, allergyLower)) {
      issues.push({ type: 'allergy', message: `ลูกค้าแพ้ยา: ${allergy}`, severity: 'critical' });
      canRecommend = false;
    }
  }

  const currentMeds = medicalHistory.currentMedications;
  if (currentMeds.length > 0) {
    const interactionCheck = await checkDrugInteractions(db, [drugName, genericName], userId);
    if (interactionCheck.hasInteractions) {
      for (const interaction of interactionCheck.interactions) {
        issues.push({
          type: 'interaction',
          message: `ยาตีกับ ${interaction.drug2}: ${interaction.description}`,
          severity: interaction.severity,
          recommendation: interaction.recommendation,
        });
        if (interaction.severity === SEVERITY_CONTRAINDICATED) {
          canRecommend = false;
        }
      }
    }
  }

  return {
    canRecommend,
    drugInfo,
    issues,
    issueCount: issues.length,
    hasCriticalIssues: !canRecommend,
  };
}
