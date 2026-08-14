import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getMaxDiscount } from '../../max-discount/_lib/drugPricingEngine';

/**
 * suggestAlternatives.ts — port of `classes/DrugPricingEngineService.php`'s
 * `suggestAlternatives()` (lines 187-258), as driven by api/inbox-v2.php's
 * `case 'suggest_alternatives': case 'suggest-alternatives':` (lines ~807-833).
 *
 * ```php
 * public function suggestAlternatives(int $drugId, float $requestedDiscount): array
 * {
 *     $maxDiscountInfo = $this->getMaxDiscount($drugId);
 *     if (isset($maxDiscountInfo['error'])) {
 *         return ['alternatives' => [], 'exceedsThreshold' => false, 'error' => $maxDiscountInfo['error']];
 *     }
 *     $maxDiscount = $maxDiscountInfo['maxDiscount'];
 *     $exceedsThreshold = $requestedDiscount > $maxDiscount;
 *     $alternatives = [];
 *     if ($exceedsThreshold) {
 *         $excessAmount = $requestedDiscount - $maxDiscount;
 *
 *         $alternatives[] = ['type' => self::ALT_FREE_DELIVERY, 'name' => 'ส่งฟรี', 'description' => 'ฟรีค่าจัดส่ง', 'value' => 50.0, 'icon' => 'fa-truck'];
 *
 *         $alternatives[] = ['type' => self::ALT_BONUS_VITAMINS, 'name' => 'แถมวิตามิน', 'description' => 'แถมวิตามินซี 10 เม็ด', 'value' => round($excessAmount * 0.8, 2), 'icon' => 'fa-pills'];
 *
 *         $bonusPoints = (int)ceil($excessAmount * 2); // 2 points per baht
 *         $alternatives[] = ['type' => self::ALT_LOYALTY_POINTS, 'name' => 'แต้มพิเศษ', 'description' => "รับแต้มสะสมเพิ่ม {$bonusPoints} แต้ม", 'value' => $bonusPoints, 'icon' => 'fa-star'];
 *
 *         $nextDiscount = round($excessAmount * 1.2, 2); // 120% of excess as future discount
 *         $alternatives[] = ['type' => self::ALT_NEXT_PURCHASE, 'name' => 'ส่วนลดครั้งหน้า', 'description' => "รับส่วนลด ฿{$nextDiscount} สำหรับการซื้อครั้งถัดไป", 'value' => $nextDiscount, 'icon' => 'fa-ticket'];
 *     }
 *     return [
 *         'drugId' => $drugId, 'requestedDiscount' => round($requestedDiscount, 2), 'maxAllowableDiscount' => round($maxDiscount, 2),
 *         'exceedsThreshold' => $exceedsThreshold, 'excessAmount' => $exceedsThreshold ? round($requestedDiscount - $maxDiscount, 2) : 0.0,
 *         'alternatives' => $alternatives,
 *         'recommendation' => $exceedsThreshold ? 'ส่วนลดที่ขอเกินกว่าที่กำหนด แนะนำให้เสนอทางเลือกอื่นแทน' : 'สามารถให้ส่วนลดได้ตามที่ขอ'
 *     ];
 * }
 * ```
 *
 * `getMaxDiscount()` itself is imported directly from the already-merged
 * `../../max-discount/_lib/drugPricingEngine.ts` (Phase 4 batch 4a) rather
 * than re-derived — per this batch's brief's "same builder, same round"
 * cross-import precedent (mirroring Phase 4 batch 4a's own `drug-info` ->
 * `max-discount` import). Called with NO `minMarginPercent` override
 * (matching PHP's `$this->getMaxDiscount($drugId)` — no second arg — so
 * `max-discount/_lib/drugPricingEngine.ts`'s default `10.0` applies).
 *
 * Only the four Thai alternative-offer builders below are new to this
 * file: free delivery is a flat `50.0`; bonus vitamins is
 * `round(excessAmount * 0.8, 2)`; loyalty points is `Math.ceil(excessAmount
 * * 2)` (an integer, 2 points per baht of excess); next-purchase discount
 * is `round(excessAmount * 1.2, 2)` (120% of the excess, as a future
 * incentive). All four fire ONLY when `requestedDiscount > maxDiscount`
 * (per `getMaxDiscount()`, computed with the DEFAULT 10% minimum margin —
 * this action never lets the caller override that threshold).
 *
 * `$nextDiscount`/`$bonusPoints` are interpolated directly into Thai
 * description strings via PHP's default float/int-to-string conversion —
 * ported via JS template-literal number interpolation, which produces an
 * identical decimal representation for the `round(x, 2)` magnitudes this
 * function ever handles (both languages emit the shortest round-trippable
 * decimal for values already rounded to 2 places, with no trailing zero).
 */

/** PHP `round($x, 2)`. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

export type AlternativeType = 'free_delivery' | 'bonus_vitamins' | 'loyalty_points' | 'next_purchase_discount';

export interface AlternativeOffer {
  type: AlternativeType;
  name: string;
  description: string;
  value: number;
  icon: string;
}

export type SuggestAlternativesResult =
  | {
      alternatives: [];
      exceedsThreshold: false;
      error: string;
    }
  | {
      drugId: number;
      requestedDiscount: number;
      maxAllowableDiscount: number;
      exceedsThreshold: boolean;
      excessAmount: number;
      alternatives: AlternativeOffer[];
      recommendation: string;
    };

export async function suggestAlternatives(db: Kysely<TenantDB>, drugId: number, requestedDiscount: number): Promise<SuggestAlternativesResult> {
  const maxDiscountInfo = await getMaxDiscount(db, drugId);

  if ('error' in maxDiscountInfo) {
    return { alternatives: [], exceedsThreshold: false, error: maxDiscountInfo.error };
  }

  const maxDiscount = maxDiscountInfo.maxDiscount;
  const exceedsThreshold = requestedDiscount > maxDiscount;

  const alternatives: AlternativeOffer[] = [];

  if (exceedsThreshold) {
    const excessAmount = requestedDiscount - maxDiscount;

    alternatives.push({ type: 'free_delivery', name: 'ส่งฟรี', description: 'ฟรีค่าจัดส่ง', value: 50.0, icon: 'fa-truck' });

    alternatives.push({
      type: 'bonus_vitamins',
      name: 'แถมวิตามิน',
      description: 'แถมวิตามินซี 10 เม็ด',
      value: round2(excessAmount * 0.8),
      icon: 'fa-pills',
    });

    const bonusPoints = Math.ceil(excessAmount * 2);
    alternatives.push({
      type: 'loyalty_points',
      name: 'แต้มพิเศษ',
      description: `รับแต้มสะสมเพิ่ม ${bonusPoints} แต้ม`,
      value: bonusPoints,
      icon: 'fa-star',
    });

    const nextDiscount = round2(excessAmount * 1.2);
    alternatives.push({
      type: 'next_purchase_discount',
      name: 'ส่วนลดครั้งหน้า',
      description: `รับส่วนลด ฿${nextDiscount} สำหรับการซื้อครั้งถัดไป`,
      value: nextDiscount,
      icon: 'fa-ticket',
    });
  }

  return {
    drugId,
    requestedDiscount: round2(requestedDiscount),
    maxAllowableDiscount: round2(maxDiscount),
    exceedsThreshold,
    excessAmount: exceedsThreshold ? round2(requestedDiscount - maxDiscount) : 0.0,
    alternatives,
    recommendation: exceedsThreshold ? 'ส่วนลดที่ขอเกินกว่าที่กำหนด แนะนำให้เสนอทางเลือกอื่นแทน' : 'สามารถให้ส่วนลดได้ตามที่ขอ',
  };
}
