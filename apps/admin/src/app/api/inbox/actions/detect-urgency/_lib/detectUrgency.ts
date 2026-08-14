import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * detectUrgency.ts — literal port of `classes/ConsultationAnalyzerService.php`'s
 * `detectUrgency()` (lines 1638-1728) and `getUrgencyLabel()` (1735-1744),
 * as driven by api/inbox-v2.php's `case 'detect_urgency': case
 * 'detect-urgency':` (lines ~1648-1675).
 *
 * ```php
 * public function detectUrgency(int $userId): array
 * {
 *     $messages = $this->getRecentMessages($userId, 10);
 *     if (empty($messages)) {
 *         return ['needsReferral' => false, 'reason' => null, 'urgency' => 'normal', 'detectedKeywords' => []];
 *     }
 *     $detectedKeywords = [];
 *     $urgencyLevel = 'normal';
 *     $reason = null;
 *     foreach ($messages as $message) {
 *         $content = mb_strtolower($message['content'] ?? '');
 *         foreach ($this->urgentKeywords as $keyword) {
 *             if (mb_stripos($content, mb_strtolower($keyword)) !== false) {
 *                 $detectedKeywords[] = $keyword;
 *             }
 *         }
 *     }
 *     $detectedKeywords = array_unique($detectedKeywords);
 *     if (!empty($detectedKeywords)) {
 *         $criticalKeywords = [
 *             'หายใจลำบาก', 'หายใจไม่ออก', 'แน่นหน้าอก', 'เจ็บหน้าอก',
 *             'ชัก', 'หมดสติ', 'เลือดออก', 'อาเจียนเป็นเลือด',
 *             'difficulty breathing', 'cant breathe', 'chest pain', 'seizure', 'unconscious'
 *         ];
 *         $hasCritical = false;
 *         foreach ($detectedKeywords as $keyword) {
 *             foreach ($criticalKeywords as $critical) {
 *                 if (mb_stripos($keyword, $critical) !== false || mb_stripos($critical, $keyword) !== false) {
 *                     $hasCritical = true; break 2;
 *                 }
 *             }
 *         }
 *         if ($hasCritical) {
 *             $urgencyLevel = 'critical';
 *             $reason = 'ตรวจพบอาการฉุกเฉิน: ' . implode(', ', array_slice($detectedKeywords, 0, 3));
 *         } elseif (count($detectedKeywords) >= 2) {
 *             $urgencyLevel = 'high';
 *             $reason = 'ตรวจพบอาการรุนแรงหลายอย่าง: ' . implode(', ', array_slice($detectedKeywords, 0, 3));
 *         } else {
 *             $urgencyLevel = 'moderate';
 *             $reason = 'ตรวจพบอาการที่ควรระวัง: ' . implode(', ', $detectedKeywords);
 *         }
 *     }
 *     try {
 *         $stmt = $this->db->prepare("SELECT has_urgent_symptoms FROM consultation_stages WHERE user_id = ?");
 *         $stmt->execute([$userId]);
 *         $stage = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($stage && $stage['has_urgent_symptoms'] && $urgencyLevel === 'normal') {
 *             $urgencyLevel = 'moderate';
 *             $reason = 'มีประวัติอาการที่ควรระวังก่อนหน้านี้';
 *         }
 *     } catch (PDOException $e) { }
 *     $needsReferral = in_array($urgencyLevel, ['critical', 'high']);
 *     return [
 *         'needsReferral' => $needsReferral, 'reason' => $reason, 'urgency' => $urgencyLevel,
 *         'urgencyLabel' => $this->getUrgencyLabel($urgencyLevel), 'detectedKeywords' => $detectedKeywords,
 *         'recommendation' => $needsReferral ? 'แนะนำให้พบแพทย์โดยเร็ว' : ($urgencyLevel === 'moderate' ? 'ควรติดตามอาการอย่างใกล้ชิด' : null)
 *     ];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TWO DIFFERENT RETURN SHAPES — the 0-messages short circuit has FEWER keys
 * ═══════════════════════════════════════════════════════════════════════
 * The `empty($messages)` early return has only 4 keys (`needsReferral`,
 * `reason`, `urgency`, `detectedKeywords`) — no `urgencyLabel`, no
 * `recommendation` at all (not even `null`). The full path always returns
 * all 6 keys. Ported as a discriminated union (`UrgencyResultShort` vs.
 * `UrgencyResultFull`) to preserve this literally.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `array_unique($detectedKeywords)` gap-key artifact — NOT reproduced
 * ═══════════════════════════════════════════════════════════════════════
 * `array_unique()` here is called WITHOUT a following `array_values()`, so
 * PHP's underlying array keeps gaps in its integer keys after a duplicate is
 * removed from the middle. That gapped array is later returned directly as
 * `'detectedKeywords' => $detectedKeywords` — which would make PHP's
 * `json_encode()` emit a JSON **object** instead of an array for that field,
 * on any request where a duplicate was actually removed from a
 * non-tail position. Same precedent as
 * `../../medical-history/_lib/medicalHistory.ts`'s own module doc: this is
 * an unintended PHP artifact (a missing `array_values()` call), not a
 * documented contract, so this port does not reproduce it — `[...new
 * Set(...)]` yields the INTENDED "dedupe, preserve first-occurrence order"
 * result as a proper JSON array. (`array_slice($detectedKeywords, 0, 3)`
 * used for the `reason` string interpolation is unaffected either way —
 * PHP's `array_slice()` always reindexes a numerically-keyed array, gaps or
 * not.)
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `$this->urgentKeywords` — duplicated, not cross-imported
 * ═══════════════════════════════════════════════════════════════════════
 * Same literal 46-entry list as `../../consultation-stage/_lib/
 * consultationStage.ts`'s own `URGENT_KEYWORDS` (both back a single PHP
 * class property, `ConsultationAnalyzerService::$urgentKeywords`). Kept as
 * an independent copy here per this batch's brief — only the one
 * `detectStage` cross-import (consultation-stage -> quick-actions) is
 * specified; every other shared PHP list/helper stays duplicated per-route,
 * matching this codebase's established `session.ts`/`testHelpers/
 * fakeTenantDb.ts` per-route duplication convention.
 *
 * `getRecentMessages()` is likewise its own independent copy (same SQL,
 * same no-`line_account_id`-filter behavior, same PDOException-swallowing
 * `catch`).
 */

const URGENT_KEYWORDS: string[] = [
  // Thai
  'หายใจลำบาก', 'หายใจไม่ออก', 'แน่นหน้าอก', 'เจ็บหน้าอก',
  'ชัก', 'หมดสติ', 'เลือดออก', 'เลือดไหล', 'อาเจียนเป็นเลือด',
  'ไข้สูงมาก', 'ปวดรุนแรง', 'บวมมาก', 'แพ้รุนแรง',
  'ผื่นทั้งตัว', 'ปากบวม', 'ลิ้นบวม', 'กลืนลำบาก',
  'ตาพร่า', 'มองไม่เห็น', 'อัมพาต', 'ชา', 'อ่อนแรง',
  // English
  'difficulty breathing', 'cant breathe', 'chest pain', 'chest tight',
  'seizure', 'unconscious', 'bleeding', 'vomiting blood',
  'high fever', 'severe pain', 'severe swelling', 'severe allergy',
  'rash all over', 'swollen lips', 'swollen tongue', 'difficulty swallowing',
  'blurred vision', 'cant see', 'paralysis', 'numbness', 'weakness',
  'emergency', 'urgent', 'critical',
];

/** PHP `detectUrgency()`'s local `$criticalKeywords` (lines 1672-1676) — a SHORTER subset of `URGENT_KEYWORDS`. */
const CRITICAL_KEYWORDS: string[] = [
  'หายใจลำบาก', 'หายใจไม่ออก', 'แน่นหน้าอก', 'เจ็บหน้าอก',
  'ชัก', 'หมดสติ', 'เลือดออก', 'อาเจียนเป็นเลือด',
  'difficulty breathing', 'cant breathe', 'chest pain', 'seizure', 'unconscious',
];

/** PHP `mb_stripos($haystack, $needle) !== false` — case-insensitive substring test. */
function mbStripos(haystack: string, needle: string): boolean {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

interface MessageRow {
  content: string | null;
  message_type: string | null;
  direction: 'incoming' | 'outgoing';
  created_at: Date;
}

/** PHP `getRecentMessages()` (lines 161-177) — own independent copy, see module doc. */
async function getRecentMessages(db: Kysely<TenantDB>, userId: number, limit = 10): Promise<MessageRow[]> {
  try {
    const result = await sql<MessageRow>`
      SELECT content, message_type, direction, created_at
      FROM messages
      WHERE user_id = ${userId} AND message_type = 'text'
      ORDER BY created_at DESC
      LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

export type UrgencyLevel = 'normal' | 'moderate' | 'high' | 'critical';

/** PHP `getUrgencyLabel()` (lines 1735-1744). */
function getUrgencyLabel(level: string): string {
  const labels: Record<string, string> = {
    normal: 'ปกติ',
    moderate: 'ควรระวัง',
    high: 'รุนแรง',
    critical: 'ฉุกเฉิน',
  };
  return labels[level] ?? 'ไม่ระบุ';
}

export interface UrgencyResultShort {
  needsReferral: false;
  reason: null;
  urgency: 'normal';
  detectedKeywords: [];
}

export interface UrgencyResultFull {
  needsReferral: boolean;
  reason: string | null;
  urgency: UrgencyLevel;
  urgencyLabel: string;
  detectedKeywords: string[];
  recommendation: string | null;
}

export type UrgencyResult = UrgencyResultShort | UrgencyResultFull;

/** PHP `$v && $v !== '0'`-style truthiness for a DB `has_urgent_symptoms` tinyint/flag column value. */
function phpTruthy(value: unknown): boolean {
  if (value === null || value === undefined || value === false) return false;
  if (value === 0 || value === '0' || value === '') return false;
  return true;
}

export async function detectUrgency(db: Kysely<TenantDB>, userId: number): Promise<UrgencyResult> {
  const messages = await getRecentMessages(db, userId, 10);

  if (messages.length === 0) {
    return { needsReferral: false, reason: null, urgency: 'normal', detectedKeywords: [] };
  }

  const detectedRaw: string[] = [];
  for (const message of messages) {
    const content = (message.content ?? '').toLowerCase();
    for (const keyword of URGENT_KEYWORDS) {
      if (mbStripos(content, keyword)) {
        detectedRaw.push(keyword);
      }
    }
  }

  // array_unique() — dedupe, preserve first-occurrence order (see module doc re: the gap-key artifact NOT reproduced).
  const detectedKeywords = [...new Set(detectedRaw)];

  let urgencyLevel: UrgencyLevel = 'normal';
  let reason: string | null = null;

  if (detectedKeywords.length > 0) {
    let hasCritical = false;
    outer: for (const keyword of detectedKeywords) {
      for (const critical of CRITICAL_KEYWORDS) {
        if (mbStripos(keyword, critical) || mbStripos(critical, keyword)) {
          hasCritical = true;
          break outer;
        }
      }
    }

    if (hasCritical) {
      urgencyLevel = 'critical';
      reason = `ตรวจพบอาการฉุกเฉิน: ${detectedKeywords.slice(0, 3).join(', ')}`;
    } else if (detectedKeywords.length >= 2) {
      urgencyLevel = 'high';
      reason = `ตรวจพบอาการรุนแรงหลายอย่าง: ${detectedKeywords.slice(0, 3).join(', ')}`;
    } else {
      urgencyLevel = 'moderate';
      reason = `ตรวจพบอาการที่ควรระวัง: ${detectedKeywords.join(', ')}`;
    }
  }

  try {
    const result = await sql<{ has_urgent_symptoms: unknown }>`
      SELECT has_urgent_symptoms FROM consultation_stages WHERE user_id = ${userId}
    `.execute(db);
    const stage = result.rows[0];
    if (stage && phpTruthy(stage.has_urgent_symptoms) && urgencyLevel === 'normal') {
      urgencyLevel = 'moderate';
      reason = 'มีประวัติอาการที่ควรระวังก่อนหน้านี้';
    }
  } catch {
    // Ignore — matches PHP's empty `catch (PDOException $e) { }`.
  }

  const needsReferral = urgencyLevel === 'critical' || urgencyLevel === 'high';

  return {
    needsReferral,
    reason,
    urgency: urgencyLevel,
    urgencyLabel: getUrgencyLabel(urgencyLevel),
    detectedKeywords,
    recommendation: needsReferral ? 'แนะนำให้พบแพทย์โดยเร็ว' : urgencyLevel === 'moderate' ? 'ควรติดตามอาการอย่างใกล้ชิด' : null,
  };
}
