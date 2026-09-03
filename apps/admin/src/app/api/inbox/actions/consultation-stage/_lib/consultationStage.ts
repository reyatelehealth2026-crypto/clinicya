import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * consultationStage.ts — literal port of `classes/ConsultationAnalyzerService.php`'s
 * `detectStage()` (lines 116-153) and its private helpers
 * `getRecentMessages()` (161-177), `analyzeStagePatterns()` (184-236),
 * `determineStage()` (243-256), `calculateStageConfidence()` (264-287),
 * `collectSignals()` (295-311), `hasUrgentSymptoms()` (318-331),
 * `saveStage()` (341-364), `createStageResult()` (373-385),
 * `getStageLabel()` (392-401), `getStageLabelTh()` (408-417) — as driven by
 * api/inbox-v2.php's `case 'consultation_stage': case 'consultation-stage':
 * case 'detect_stage':` (lines ~1570-1598).
 *
 * ```php
 * public function detectStage(int $userId): array
 * {
 *     $messages = $this->getRecentMessages($userId, 10);
 *     if (empty($messages)) {
 *         return $this->createStageResult(self::STAGE_SYMPTOM, 0.3, ['no_messages']);
 *     }
 *     $scores = $this->analyzeStagePatterns($messages);
 *     $stage = $this->determineStage($scores);
 *     $confidence = $this->calculateStageConfidence($scores, $stage);
 *     $signals = $this->collectSignals($messages, $stage);
 *     $hasUrgentSymptoms = $this->hasUrgentSymptoms($messages);
 *     $this->saveStage($userId, $stage, $confidence, $signals, $hasUrgentSymptoms);
 *     return [
 *         'stage' => $stage, 'stageLabel' => $this->getStageLabel($stage),
 *         'stageLabelTh' => $this->getStageLabelTh($stage), 'confidence' => round($confidence, 2),
 *         'signals' => $signals, 'hasUrgentSymptoms' => $hasUrgentSymptoms,
 *         'scores' => $scores, 'messageCount' => count($messages)
 *     ];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * WRITE SIDE EFFECT — `saveStage()` runs INSERT ... ON DUPLICATE KEY UPDATE
 * on every call that reaches the non-empty-messages branch
 * ═══════════════════════════════════════════════════════════════════════
 * This is a GET action with a real DB write. Ported via a raw `sql` tagged
 * template (not `.insertInto().onDuplicateKeyUpdate()`) per this batch's
 * brief, matching the literal column list the PHP source binds — NO
 * `line_account_id` in the INSERT, even though `ConsultationStages` (see
 * `packages/db/src/generated/tenant-db.d.ts`) declares that column
 * `Generated<number>` (has a DB-side default): `saveStage()`'s PHP SQL
 * genuinely omits it, and this port does not add it. `saveStage()` never
 * throws — its own `catch (PDOException $e)` swallows write failures into
 * `error_log()` and returns silently; ported the same way here.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `scores` field shape differs between the short-circuit and full paths
 * ═══════════════════════════════════════════════════════════════════════
 * `createStageResult()`'s PHP literal is `'scores' => []` — an empty PHP
 * array, which `json_encode()`s to a JSON **array** `[]`. The full path's
 * `analyzeStagePatterns()` always returns a 4-key associative array
 * (`symptom_assessment`/`drug_recommendation`/`purchase`/`follow_up` => a
 * float each), which `json_encode()`s to a JSON **object**. This port keeps
 * that literal type difference (`scores: []` on the 0-messages short
 * circuit vs. `scores: Record<Stage, number>` otherwise) rather than
 * normalizing both branches to the same shape.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `getRecentMessages()` is NOT scoped by `line_account_id`
 * ═══════════════════════════════════════════════════════════════════════
 * The PHP SQL (`WHERE user_id = ? AND message_type = 'text'`) has no
 * `line_account_id` filter at all — ported literally, not "fixed" to add
 * one; this is how the source behaves today across every multi-account
 * tenant.
 */

export const STAGE_SYMPTOM = 'symptom_assessment' as const;
export const STAGE_RECOMMENDATION = 'drug_recommendation' as const;
export const STAGE_PURCHASE = 'purchase' as const;
export const STAGE_FOLLOWUP = 'follow_up' as const;

export type Stage = typeof STAGE_SYMPTOM | typeof STAGE_RECOMMENDATION | typeof STAGE_PURCHASE | typeof STAGE_FOLLOWUP;

interface StageKeywords {
  positive: string[];
  negative: string[];
}

/** `ConsultationAnalyzerService::$stageKeywords` (lines 23-77) — literal, byte-for-byte copy. */
const STAGE_KEYWORDS: Record<Stage, StageKeywords> = {
  [STAGE_SYMPTOM]: {
    positive: [
      // Thai
      'ปวด', 'เจ็บ', 'ไข้', 'ไอ', 'คัน', 'ผื่น', 'บวม', 'อักเสบ',
      'ท้องเสีย', 'ท้องผูก', 'คลื่นไส้', 'อาเจียน', 'เวียนหัว',
      'อ่อนเพลีย', 'นอนไม่หลับ', 'แพ้', 'ระคายเคือง',
      'เป็นอะไร', 'อาการ', 'รู้สึก', 'ไม่สบาย',
      // English
      'pain', 'hurt', 'fever', 'cough', 'itch', 'rash', 'swelling',
      'diarrhea', 'constipation', 'nausea', 'vomit', 'dizzy',
      'tired', 'insomnia', 'allergy', 'symptom', 'feel sick',
    ],
    negative: ['ซื้อ', 'สั่ง', 'ราคา', 'จ่าย', 'ส่ง', 'buy', 'order', 'price'],
  },
  [STAGE_RECOMMENDATION]: {
    positive: [
      // Thai
      'ยา', 'แนะนำ', 'ตัวไหน', 'อะไรดี', 'ใช้อะไร', 'กินอะไร',
      'เปรียบเทียบ', 'ต่างกัน', 'ดีกว่า', 'ผลข้างเคียง',
      'วิธีใช้', 'ขนาด', 'ปริมาณ', 'กี่เม็ด', 'กี่ครั้ง',
      // English
      'drug', 'medicine', 'recommend', 'which one', 'what should',
      'compare', 'difference', 'better', 'side effect',
      'dosage', 'how to use', 'how many',
    ],
    negative: ['ซื้อ', 'สั่ง', 'จ่าย', 'buy', 'order', 'pay'],
  },
  [STAGE_PURCHASE]: {
    positive: [
      // Thai
      'ซื้อ', 'สั่ง', 'เอา', 'ต้องการ', 'ราคา', 'เท่าไหร่',
      'จ่าย', 'โอน', 'ชำระ', 'ส่ง', 'จัดส่ง', 'รับ',
      'ลด', 'ส่วนลด', 'โปรโมชั่น', 'ตะกร้า', 'ออเดอร์',
      // English
      'buy', 'order', 'want', 'price', 'how much',
      'pay', 'transfer', 'delivery', 'ship', 'receive',
      'discount', 'promotion', 'cart', 'checkout',
    ],
    negative: [],
  },
  [STAGE_FOLLOWUP]: {
    positive: [
      // Thai
      'ดีขึ้น', 'หาย', 'ไม่หาย', 'ยังไม่ดี', 'เหมือนเดิม',
      'กินหมด', 'ใช้หมด', 'เติม', 'ซื้อเพิ่ม', 'ต่อ',
      'ผลเป็นอย่างไร', 'รายงาน', 'อัพเดท',
      // English
      'better', 'recovered', 'not better', 'same', 'still',
      'finished', 'refill', 'more', 'continue',
      'result', 'update', 'follow up',
    ],
    negative: [],
  },
};

/**
 * `ConsultationAnalyzerService::$urgentKeywords` (lines 80-94) — literal,
 * byte-for-byte copy. Duplicated (not cross-imported) in
 * `../../detect-urgency/_lib/detectUrgency.ts`, which needs the identical
 * list for its own, unrelated `detectUrgency()` port — see this batch's
 * runbook for the rationale (only the ONE `detectStage` cross-import into
 * `quick-actions` is specified by the brief; every other shared PHP
 * constant/helper stays duplicated per-route, matching the established
 * `session.ts`/`testHelpers/fakeTenantDb.ts` duplication convention already
 * used across every `api/inbox/actions/*` sibling).
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

/** PHP `mb_stripos($haystack, $needle) !== false` — case-insensitive substring test. */
function mbStripos(haystack: string, needle: string): boolean {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

/** PHP `round($x, $precision)` — round-half-away-from-zero (values here are always >= 0, so this matches `Math.round` scaling too, but written generally). */
function phpRound(value: number, precision: number): number {
  const factor = 10 ** precision;
  return Math.sign(value) * Math.round(Math.abs(value) * factor) / factor;
}

interface MessageRow {
  content: string | null;
  message_type: string | null;
  direction: 'incoming' | 'outgoing';
  created_at: Date;
}

/** PHP `getRecentMessages()` (lines 161-177) — swallows PDOException into `[]`. */
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

type StageScores = Record<Stage, number>;

const STAGE_ORDER: Stage[] = [STAGE_SYMPTOM, STAGE_RECOMMENDATION, STAGE_PURCHASE, STAGE_FOLLOWUP];

/** PHP `analyzeStagePatterns()` (lines 184-236). */
function analyzeStagePatterns(messages: MessageRow[]): StageScores {
  const scores: StageScores = {
    [STAGE_SYMPTOM]: 0,
    [STAGE_RECOMMENDATION]: 0,
    [STAGE_PURCHASE]: 0,
    [STAGE_FOLLOWUP]: 0,
  };

  if (messages.length === 0) {
    return scores;
  }

  const totalMessages = messages.length;

  messages.forEach((message, index) => {
    const content = message.content ?? '';
    const isIncoming = (message.direction ?? 'incoming') === 'incoming';

    // Weight: most recent = 1.0, oldest = 0.5
    const weight = 1.0 - index / (totalMessages * 2);

    // Only analyze incoming (customer) messages for stage detection
    if (!isIncoming) {
      return;
    }

    for (const stage of STAGE_ORDER) {
      const keywords = STAGE_KEYWORDS[stage];
      for (const keyword of keywords.positive) {
        if (mbStripos(content, keyword)) {
          scores[stage] += weight;
        }
      }
      for (const keyword of keywords.negative) {
        if (mbStripos(content, keyword)) {
          scores[stage] -= weight * 0.5;
        }
      }
    }
  });

  // Normalize scores
  const maxScore = Math.max(...STAGE_ORDER.map((stage) => scores[stage]));
  if (maxScore > 0) {
    for (const stage of STAGE_ORDER) {
      scores[stage] = Math.max(0, phpRound(scores[stage] / maxScore, 2));
    }
  }

  return scores;
}

/** PHP `determineStage()` (lines 243-256) — first-strictly-greater wins; default STAGE_SYMPTOM on an all-zero tie. */
function determineStage(scores: StageScores): Stage {
  let maxScore = 0;
  let stage: Stage = STAGE_SYMPTOM;

  for (const s of STAGE_ORDER) {
    const score = scores[s];
    if (score > maxScore) {
      maxScore = score;
      stage = s;
    }
  }

  return stage;
}

/** PHP `calculateStageConfidence()` (lines 264-287). */
function calculateStageConfidence(scores: StageScores, selectedStage: Stage): number {
  const selectedScore = scores[selectedStage] ?? 0;
  const otherScores = STAGE_ORDER.filter((s) => s !== selectedStage).map((s) => scores[s]);
  const maxOther = otherScores.length > 0 ? Math.max(...otherScores) : 0;

  if (selectedScore === 0) {
    return 0.3; // Base confidence when no clear signals
  }

  const diff = selectedScore - maxOther;

  if (diff >= 0.5) {
    return Math.min(1.0, 0.8 + (diff - 0.5) * 0.4);
  } else if (diff >= 0.2) {
    return 0.5 + (diff - 0.2) * 1.0;
  } else {
    return 0.3 + diff * 1.0;
  }
}

/**
 * PHP `collectSignals()` (lines 295-311) — `array_slice($signals, 0, 5)`
 * happens BEFORE `array_unique()`: every keyword match from every message is
 * collected first (message loop outer, keyword loop inner — NOT stopping at
 * the first match per message), THEN the first 5 raw entries are taken,
 * THEN deduped preserving order of first occurrence.
 */
function collectSignals(messages: MessageRow[], stage: Stage): string[] {
  const keywords = STAGE_KEYWORDS[stage]?.positive ?? [];
  const signals: string[] = [];

  for (const message of messages) {
    const content = message.content ?? '';
    for (const keyword of keywords) {
      if (mbStripos(content, keyword)) {
        signals.push(keyword);
      }
    }
  }

  const sliced = signals.slice(0, 5);
  return [...new Set(sliced)];
}

/** PHP `hasUrgentSymptoms()` (lines 318-331). */
function hasUrgentSymptomsInMessages(messages: MessageRow[]): boolean {
  for (const message of messages) {
    const content = (message.content ?? '').toLowerCase();
    for (const keyword of URGENT_KEYWORDS) {
      if (mbStripos(content, keyword)) {
        return true;
      }
    }
  }
  return false;
}

/**
 * PHP `saveStage()` (lines 341-364) — raw `sql` INSERT ... ON DUPLICATE KEY
 * UPDATE, no `line_account_id` column (see module doc). Swallows write
 * failures (matching the PHP `catch (PDOException $e) { error_log(...); }`
 * — never throws).
 */
async function saveStage(
  db: Kysely<TenantDB>,
  userId: number,
  stage: Stage,
  confidence: number,
  signals: string[],
  hasUrgentSymptomsFlag: boolean
): Promise<void> {
  try {
    await sql`
      INSERT INTO consultation_stages (user_id, stage, confidence, signals, has_urgent_symptoms, updated_at)
      VALUES (${userId}, ${stage}, ${confidence}, ${JSON.stringify(signals)}, ${hasUrgentSymptomsFlag ? 1 : 0}, NOW())
      ON DUPLICATE KEY UPDATE
        stage = VALUES(stage),
        confidence = VALUES(confidence),
        signals = VALUES(signals),
        has_urgent_symptoms = VALUES(has_urgent_symptoms),
        updated_at = NOW()
    `.execute(db);
  } catch {
    // ConsultationAnalyzer saveStage error — PHP swallows via error_log(), never throws.
  }
}

/** PHP `getStageLabel()` (lines 392-401). */
function getStageLabel(stage: string): string {
  const labels: Record<string, string> = {
    [STAGE_SYMPTOM]: 'Symptom Assessment',
    [STAGE_RECOMMENDATION]: 'Drug Recommendation',
    [STAGE_PURCHASE]: 'Purchase Decision',
    [STAGE_FOLLOWUP]: 'Follow Up',
  };
  return labels[stage] ?? 'Unknown';
}

/** PHP `getStageLabelTh()` (lines 408-417). */
export function getStageLabelTh(stage: string): string {
  const labels: Record<string, string> = {
    [STAGE_SYMPTOM]: 'ประเมินอาการ',
    [STAGE_RECOMMENDATION]: 'แนะนำยา',
    [STAGE_PURCHASE]: 'ตัดสินใจซื้อ',
    [STAGE_FOLLOWUP]: 'ติดตามผล',
  };
  return labels[stage] ?? 'ไม่ระบุ';
}

export interface StageResultShort {
  stage: Stage;
  stageLabel: string;
  stageLabelTh: string;
  confidence: number;
  signals: string[];
  hasUrgentSymptoms: false;
  scores: [];
  messageCount: 0;
}

export interface StageResultFull {
  stage: Stage;
  stageLabel: string;
  stageLabelTh: string;
  confidence: number;
  signals: string[];
  hasUrgentSymptoms: boolean;
  scores: StageScores;
  messageCount: number;
}

export type StageResult = StageResultShort | StageResultFull;

/** PHP `createStageResult()` (lines 373-385) — see module doc for the `scores: []` vs `scores: {...}` shape note. */
function createStageResult(stage: Stage, confidence: number, signals: string[]): StageResultShort {
  return {
    stage,
    stageLabel: getStageLabel(stage),
    stageLabelTh: getStageLabelTh(stage),
    confidence,
    signals,
    hasUrgentSymptoms: false,
    scores: [],
    messageCount: 0,
  };
}

/** PHP `detectStage()` (lines 116-153) — see module doc for the WRITE side effect. */
export async function detectStage(db: Kysely<TenantDB>, userId: number): Promise<StageResult> {
  const messages = await getRecentMessages(db, userId, 10);

  if (messages.length === 0) {
    return createStageResult(STAGE_SYMPTOM, 0.3, ['no_messages']);
  }

  const scores = analyzeStagePatterns(messages);
  const stage = determineStage(scores);
  const confidence = calculateStageConfidence(scores, stage);
  const signals = collectSignals(messages, stage);
  const hasUrgentSymptomsFlag = hasUrgentSymptomsInMessages(messages);

  await saveStage(db, userId, stage, confidence, signals, hasUrgentSymptomsFlag);

  return {
    stage,
    stageLabel: getStageLabel(stage),
    stageLabelTh: getStageLabelTh(stage),
    confidence: phpRound(confidence, 2),
    signals,
    hasUrgentSymptoms: hasUrgentSymptomsFlag,
    scores,
    messageCount: messages.length,
  };
}
