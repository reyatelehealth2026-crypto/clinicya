import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { extractMentionedDrugs, getLastCustomerMessage } from '../../ghost-draft/_lib/ghostDraft';

/**
 * learnDraft.ts — literal port of
 * `classes/PharmacyGhostDraftService.php::learnFromEdit()` (lines 447-496)
 * and `calculateEditDistance()`/`unicodeLevenshtein()` (lines 504-564).
 *
 * ```php
 * public function learnFromEdit(int $userId, string $originalDraft, string $finalMessage, array $context = []): bool
 * {
 *     try {
 *         $editDistance = $this->calculateEditDistance($originalDraft, $finalMessage);
 *         $originalLength = mb_strlen($originalDraft);
 *         $wasAccepted = $originalLength > 0 && ($editDistance / $originalLength) < 0.2;
 *         $mentionedDrugs = $this->extractMentionedDrugs($finalMessage);
 *         $customerMessage = $context['customerMessage'] ?? $this->getLastCustomerMessage($userId);
 *         $contextJson = json_encode([
 *             'stage' => $context['stage'] ?? null, 'healthProfile' => $context['healthProfile'] ?? null,
 *             'symptoms' => $context['symptoms'] ?? null, 'communicationType' => $context['communicationType'] ?? null
 *         ], JSON_UNESCAPED_UNICODE);
 *         $stmt = $this->db->prepare("
 *             INSERT INTO pharmacy_ghost_learning
 *             (user_id, customer_message, ai_draft, pharmacist_final, edit_distance, was_accepted, context, mentioned_drugs)
 *             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
 *         ");
 *         $result = $stmt->execute([$userId, $customerMessage, $originalDraft, $finalMessage, $editDistance,
 *             $wasAccepted ? 1 : 0, $contextJson, json_encode($mentionedDrugs, JSON_UNESCAPED_UNICODE)]);
 *         return $result;
 *     } catch (PDOException $e) {
 *         error_log("PharmacyGhostDraft learnFromEdit error: " . $e->getMessage());
 *         return false;
 *     }
 * }
 *
 * private function calculateEditDistance(string $str1, string $str2): int
 * {
 *     if (mb_strlen($str1) === strlen($str1) && mb_strlen($str2) === strlen($str2)) {
 *         if (strlen($str1) <= 255 && strlen($str2) <= 255) {
 *             return levenshtein($str1, $str2);
 *         }
 *     }
 *     return $this->unicodeLevenshtein($str1, $str2);
 * }
 *
 * private function unicodeLevenshtein(string $str1, string $str2): int
 * {
 *     $len1 = mb_strlen($str1); $len2 = mb_strlen($str2);
 *     if ($len1 > 500 || $len2 > 500) {
 *         return abs($len1 - $len2) + (int)(min($len1, $len2) * 0.3);
 *     }
 *     if ($len1 === 0) return $len2;
 *     if ($len2 === 0) return $len1;
 *     // ... full O(n*m) DP matrix over mb_substr($str, $i, 1) code points ...
 * }
 * ```
 *
 * `extractMentionedDrugs()` and `getLastCustomerMessage()` are imported
 * directly from `../../ghost-draft/_lib/ghostDraft.ts` — the deliberate,
 * documented single-owner cross-route import for this batch's
 * `ghost-draft`/`learn-draft` pair (both directories belong to this same
 * builder stream — see that module's own doc), matching the precedent
 * already set by `../../drug-info/_lib/drugInfo.ts` importing
 * `calculateMargin` from `../../max-discount/_lib/drugPricingEngine.ts`.
 *
 * `mb_strlen($str1) === strlen($str1)` (PHP's "is this string pure ASCII?"
 * test — true only when every character is exactly 1 byte in UTF-8) is
 * ported as a plain ASCII-range regex test (`/^[\x00-\x7F]*$/`), which is
 * the exact same condition expressed directly rather than via a byte/
 * code-point length comparison (Node strings are UTF-16, not UTF-8, so the
 * PHP comparison has no literal JS equivalent — the regex is the correct
 * translation of INTENT, not of the specific byte-counting mechanism).
 */

/** PHP's ASCII-only fast-path gate: `mb_strlen($s) === strlen($s)` — true iff every character is single-byte (i.e. the whole string is ASCII). */
function isAsciiOnly(str: string): boolean {
  // eslint-disable-next-line no-control-regex
  return /^[\x00-\x7F]*$/.test(str);
}

/** Standard iterative Levenshtein DP over an array of single characters/code points — used by both the ASCII fast path and the Unicode path (the two PHP implementations compute the identical distance; this port shares one DP core). */
function levenshteinDp(a: readonly string[], b: readonly string[]): number {
  const len1 = a.length;
  const len2 = b.length;
  const matrix: number[][] = [];

  for (let i = 0; i <= len1; i++) {
    matrix[i] = [i];
  }
  for (let j = 0; j <= len2; j++) {
    matrix[0]![j] = j;
  }

  for (let i = 1; i <= len1; i++) {
    for (let j = 1; j <= len2; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      matrix[i]![j] = Math.min(
        matrix[i - 1]![j]! + 1, // deletion
        matrix[i]![j - 1]! + 1, // insertion
        matrix[i - 1]![j - 1]! + cost // substitution
      );
    }
  }

  return matrix[len1]![len2]!;
}

/** `unicodeLevenshtein()` (lines 525-564) — Unicode-code-point-aware DP, with the same `>500` length-based approximation PHP uses for very long strings. */
export function unicodeLevenshtein(str1: string, str2: string): number {
  const chars1 = Array.from(str1); // mb_str -> array of code points
  const chars2 = Array.from(str2);
  const len1 = chars1.length;
  const len2 = chars2.length;

  if (len1 > 500 || len2 > 500) {
    return Math.abs(len1 - len2) + Math.floor(Math.min(len1, len2) * 0.3);
  }
  if (len1 === 0) return len2;
  if (len2 === 0) return len1;

  return levenshteinDp(chars1, chars2);
}

/** `calculateEditDistance()` (lines 504-517) — ASCII fast path (<=255 chars each) delegates to the same DP core; everything else goes through `unicodeLevenshtein()`. */
export function calculateEditDistance(str1: string, str2: string): number {
  if (isAsciiOnly(str1) && isAsciiOnly(str2)) {
    if (str1.length <= 255 && str2.length <= 255) {
      return levenshteinDp(str1.split(''), str2.split(''));
    }
  }
  return unicodeLevenshtein(str1, str2);
}

/** `$context[$key] ?? $default` — PHP's `??` triggers on BOTH a missing key and an explicit `null` value. */
function contextValue(context: Record<string, unknown>, key: string): unknown {
  const v = context[key];
  return v === undefined ? null : v;
}

export async function learnFromEdit(
  db: Kysely<TenantDB>,
  lineAccountId: number | null,
  userId: number,
  originalDraft: string,
  finalMessage: string,
  context: Record<string, unknown>
): Promise<boolean> {
  try {
    const editDistance = calculateEditDistance(originalDraft, finalMessage);
    const originalLength = Array.from(originalDraft).length; // mb_strlen
    const wasAccepted = originalLength > 0 && editDistance / originalLength < 0.2;

    const mentionedDrugs = await extractMentionedDrugs(db, lineAccountId, finalMessage);

    const rawCustomerMessage = context.customerMessage;
    const customerMessage =
      rawCustomerMessage !== undefined && rawCustomerMessage !== null
        ? typeof rawCustomerMessage === 'string'
          ? rawCustomerMessage
          : String(rawCustomerMessage)
        : await getLastCustomerMessage(db, userId);

    const contextJson = JSON.stringify({
      stage: contextValue(context, 'stage'),
      healthProfile: contextValue(context, 'healthProfile'),
      symptoms: contextValue(context, 'symptoms'),
      communicationType: contextValue(context, 'communicationType'),
    });

    await db
      .insertInto('pharmacy_ghost_learning')
      .values({
        user_id: userId,
        customer_message: customerMessage,
        ai_draft: originalDraft,
        pharmacist_final: finalMessage,
        edit_distance: editDistance,
        was_accepted: wasAccepted ? 1 : 0,
        context: contextJson,
        mentioned_drugs: JSON.stringify(mentionedDrugs),
      })
      .execute();

    return true;
  } catch (error) {
    console.error('PharmacyGhostDraft learnFromEdit error:', error instanceof Error ? error.message : error);
    return false;
  }
}
