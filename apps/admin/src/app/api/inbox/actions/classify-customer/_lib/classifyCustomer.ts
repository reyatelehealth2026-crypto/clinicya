import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getDraftStyle } from '../../draft-style/_lib/draftStyle';

/**
 * classifyCustomer.ts — literal port of
 * `classes/CustomerHealthEngineService.php::classifyCustomer()` (lines
 * 604-656) and everything it transitively calls: `detectEmotion()` (663-705),
 * `getMessageCount()` (713-727), `getRecentMessages()` (735-750),
 * `analyzeMessagePatterns()` (757-863), `determineType()` (870-883),
 * `calculateConfidence()` (891-920), `getDefaultTips()` (1017-1021, which
 * delegates to `getDraftStyle()['tips']` — imported here from
 * `../../draft-style/_lib/draftStyle.ts`, the single-owner cross-route
 * import, same as `../../customer-health/_lib/customerHealth.ts`), and
 * `saveProfile()` (1090-1116). `getHealthProfile()`'s own tree belongs to
 * `../../customer-health/_lib/customerHealth.ts`.
 *
 * ```php
 * public function classifyCustomer(int $userId, int $minMessages = self::MIN_MESSAGES_FOR_CLASSIFICATION): array
 * {
 *     $messageCount = $this->getMessageCount($userId);
 *     $messages = $this->getRecentMessages($userId, 50);
 *     $emotion = 'neutral';
 *     if (!empty($messages)) {
 *         $latestMessage = $messages[0]['content'] ?? '';
 *         $emotion = $this->detectEmotion($latestMessage);
 *     }
 *     if ($messageCount < $minMessages || empty($messages)) {
 *         return ['type' => self::TYPE_DIRECT, 'confidence' => 0.0, 'tips' => $this->getDefaultTips(self::TYPE_DIRECT),
 *                 'messageCount' => $messageCount, 'minRequired' => $minMessages, 'insufficientData' => true, 'emotion' => $emotion];
 *     }
 *     $scores = $this->analyzeMessagePatterns($messages);
 *     $type = $this->determineType($scores);
 *     $confidence = $this->calculateConfidence($scores, $type);
 *     $tips = $this->getDefaultTips($type);
 *     $this->saveProfile($userId, $type, $confidence, $tips, $messageCount);
 *     return ['type' => $type, 'confidence' => round($confidence, 2), 'tips' => $tips,
 *             'messageCount' => $messageCount, 'scores' => $scores, 'insufficientData' => false, 'emotion' => $emotion];
 * }
 * ```
 *
 * NOTE — `MIN_MESSAGES_FOR_CLASSIFICATION` default (1) vs the route's own
 * default (5): `classifyCustomer()`'s PHP *function signature* defaults to
 * `self::MIN_MESSAGES_FOR_CLASSIFICATION` (`= 1`), but `api/inbox-v2.php`'s
 * `case 'classify_customer':` ALWAYS calls it with an explicit second
 * argument (`(int) ($_GET['min_messages'] ?? 5)` — the ROUTE's own literal
 * default of 5, computed before the call). This function mirrors that same
 * "5 unless the caller says otherwise" default for parity with how the only
 * real caller (`../route.ts`) invokes it, while keeping the parameter
 * optional (matching the PHP method's own signature shape).
 */

/** PHP `stripos`/`mb_stripos` !== false — case-insensitive substring check (Thai has no case, so plain lowercasing suffices). */
function ciIncludes(haystack: string, needle: string): boolean {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

/** PHP `round($x, 2)` — half-up rounding to 2 decimal places. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

type CommType = 'A' | 'B' | 'C';

// ═══════════════════════════════════════════════════════════════════════
// detectEmotion() — lines 663-705
// ═══════════════════════════════════════════════════════════════════════

const EMOTION_PATTERNS: ReadonlyArray<{ emotion: string; pattern: RegExp }> = [
  { emotion: 'angry', pattern: /โกรธ|โมโห|หัวร้อน|บ้า|เวร|ห่า|สัตว์|ไอ้|อี|แม่ง|เหี้ย|!{2,}|ไม่พอใจ|แย่มาก/u },
  { emotion: 'frustrated', pattern: /หงุดหงิด|รำคาญ|เบื่อ|ช้า|นาน|รอ|ไม่ได้|ไม่ดี|แย่|ผิดหวัง|เสียเวลา/u },
  { emotion: 'happy', pattern: /ขอบคุณ|ดีมาก|เยี่ยม|สุดยอด|ชอบ|รัก|ปลื้ม|ดีใจ|ประทับใจ|เก่ง|เจ๋ง|👍|😊|🙏/u },
  { emotion: 'satisfied', pattern: /โอเค|ได้|ดี|เข้าใจ|ตกลง|ok|okay|รับทราบ|เรียบร้อย|ครับ$|ค่ะ$|คะ$/iu },
  { emotion: 'confused', pattern: /งง|ไม่เข้าใจ|อะไร|ยังไง|หมายความว่า|\?{2,}|สับสน|ไม่รู้/u },
  { emotion: 'worried', pattern: /กังวล|กลัว|เป็นห่วง|ไม่แน่ใจ|อันตราย|ผลข้างเคียง|ปลอดภัย|แพ้/u },
  { emotion: 'urgent', pattern: /ด่วน|เร่ง|รีบ|ตอนนี้|ทันที|asap|urgent|วันนี้|พรุ่งนี้/iu },
];

export function detectEmotion(message: string): string {
  if (message === '' || message === '0') return 'neutral'; // PHP `empty($message)`

  const msg = message.toLowerCase(); // mb_strtolower — Thai has no case, so plain .toLowerCase() is equivalent
  for (const { emotion, pattern } of EMOTION_PATTERNS) {
    if (pattern.test(msg)) return emotion;
  }
  return 'neutral';
}

// ═══════════════════════════════════════════════════════════════════════
// getMessageCount() / getRecentMessages() — lines 713-750
// ═══════════════════════════════════════════════════════════════════════

interface RecentMessageRow {
  content: string;
  message_type: string | null;
  created_at: unknown;
}

async function getMessageCount(db: Kysely<TenantDB>, userId: number): Promise<number> {
  try {
    const result = await sql<{ count: unknown }>`
      SELECT COUNT(*) as count FROM messages WHERE user_id = ${userId} AND direction = 'incoming'
    `.execute(db);
    return Number(result.rows[0]?.count ?? 0);
  } catch {
    return 0;
  }
}

async function getRecentMessages(db: Kysely<TenantDB>, userId: number, limit = 50): Promise<RecentMessageRow[]> {
  try {
    const result = await sql<RecentMessageRow>`
      SELECT content, message_type, created_at
      FROM messages
      WHERE user_id = ${userId} AND direction = 'incoming' AND message_type = 'text'
      ORDER BY created_at DESC
      LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

// ═══════════════════════════════════════════════════════════════════════
// analyzeMessagePatterns() / determineType() / calculateConfidence() —
// lines 26-42 (typeKeywords), 757-920
// ═══════════════════════════════════════════════════════════════════════

const TYPE_KEYWORDS: Record<CommType, { positive: string[]; negative: string[] }> = {
  A: {
    // Type A: Direct, transactional, minimal words
    positive: ['รีบ', 'ด่วน', 'เร็ว', 'ตอนนี้', 'ทันที', 'วันนี้', 'พรุ่งนี้', 'asap', 'urgent'],
    negative: ['ทำไม', 'อธิบาย', 'รายละเอียด', 'กังวล', 'กลัว', 'ห่วง', 'เปรียบเทียบ', 'ข้อมูล'],
  },
  B: {
    // Type B: Concerned, asks about safety, needs reassurance
    positive: [
      'กังวล', 'กลัว', 'ห่วง', 'เป็นอะไร', 'อันตราย', 'ปลอดภัย', 'ผลข้างเคียง', 'แพ้', 'ไม่แน่ใจ', 'ช่วย',
      'แนะนำ', 'ขอบคุณ', 'ดีใจ', 'หมอ', 'คุณหมอ', 'เภสัช',
    ],
    negative: ['รีบ', 'ด่วน', 'เร็ว'],
  },
  C: {
    // Type C: Detail-oriented, wants information, compares options
    positive: [
      'รายละเอียด', 'อธิบาย', 'ทำไม', 'อย่างไร', 'เปรียบเทียบ', 'ข้อมูล', 'วิจัย', 'หลักฐาน', 'ส่วนประกอบ',
      'กลไก', 'ต่างกัน', 'ดีกว่า', 'แบบไหน', 'ยี่ห้อ', 'ตัวไหน',
    ],
    negative: ['รีบ', 'ด่วน', 'เร็ว'],
  },
};

export type Scores = Record<CommType, number>;

export function analyzeMessagePatterns(messages: ReadonlyArray<{ content: string }>): Scores {
  const scores: Scores = { A: 0.0, B: 0.0, C: 0.0 };

  if (messages.length === 0) {
    return scores;
  }

  const totalMessages = messages.length;
  let totalLength = 0;
  let questionCount = 0;
  let politeCount = 0;
  let comparisonCount = 0;

  for (const message of messages) {
    const content = message.content ?? '';
    totalLength += Array.from(content).length; // mb_strlen

    if (/[?？]/u.test(content)) questionCount++;
    if (/ครับ|ค่ะ|คะ|ขอบคุณ|รบกวน|ช่วย/u.test(content)) politeCount++;
    if (/ต่างกัน|เปรียบเทียบ|แบบไหน|ตัวไหน|ดีกว่า|ยี่ห้อ/u.test(content)) comparisonCount++;

    for (const type of ['A', 'B', 'C'] as const) {
      const keywords = TYPE_KEYWORDS[type];
      for (const keyword of keywords.positive) {
        if (ciIncludes(content, keyword)) scores[type] += 1.0;
      }
      for (const keyword of keywords.negative) {
        if (ciIncludes(content, keyword)) scores[type] -= 0.3;
      }
    }
  }

  // Adjust based on message length patterns (reduced weight)
  const avgLength = totalLength / totalMessages;
  if (avgLength < 15) {
    scores.A += 1.0;
  } else if (avgLength > 100) {
    scores.C += 1.5;
  } else if (avgLength >= 30 && avgLength <= 80) {
    scores.B += 0.5;
  }

  // Adjust based on question frequency
  const questionRatio = questionCount / totalMessages;
  if (questionRatio > 0.3) {
    scores.B += 1.0;
    scores.C += 0.8;
  }

  // Adjust based on politeness (indicates Type B - relationship-focused)
  const politeRatio = politeCount / totalMessages;
  if (politeRatio > 0.5) {
    scores.B += 1.5;
  } else if (politeRatio > 0.3) {
    scores.B += 0.8;
  }

  // Adjust based on comparison requests (indicates Type C)
  if (comparisonCount > 0) {
    scores.C += comparisonCount * 0.8;
  }

  // If no strong signals, default to balanced scores
  const totalScore = scores.A + scores.B + scores.C;
  if (totalScore < 1.0) {
    scores.A = 0.4;
    scores.B = 0.3;
    scores.C = 0.3;
  }

  // Normalize scores
  const maxScore = Math.max(scores.A, scores.B, scores.C);
  if (maxScore > 0) {
    scores.A = round2(scores.A / maxScore);
    scores.B = round2(scores.B / maxScore);
    scores.C = round2(scores.C / maxScore);
  }

  return scores;
}

export function determineType(scores: Scores): CommType {
  let maxScore = 0;
  let type: CommType = 'A'; // Default

  for (const t of ['A', 'B', 'C'] as const) {
    if (scores[t] > maxScore) {
      maxScore = scores[t];
      type = t;
    }
  }

  return type;
}

export function calculateConfidence(scores: Scores, selectedType: CommType): number {
  const selectedScore = scores[selectedType] ?? 0;
  const otherTypes = (['A', 'B', 'C'] as const).filter((t) => t !== selectedType);
  const otherScores = otherTypes.map((t) => scores[t]);
  const maxOther = otherScores.length > 0 ? Math.max(...otherScores) : 0;

  if (selectedScore === 0) {
    return 0.0;
  }

  // If selected is much higher than others, high confidence
  const diff = selectedScore - maxOther;

  // Map difference to confidence (0-1):
  // diff of 0.5+   = high confidence (0.8+)
  // diff of 0.2-0.5 = medium confidence (0.5-0.8)
  // diff of 0-0.2  = low confidence (0.3-0.5)
  if (diff >= 0.5) {
    return Math.min(1.0, 0.8 + (diff - 0.5) * 0.4);
  } else if (diff >= 0.2) {
    return 0.5 + (diff - 0.2) * 1.0;
  } else {
    return 0.3 + diff * 1.0;
  }
}

/** `getDefaultTips()` (lines 1017-1021) — delegates to `getDraftStyle()['tips']`. */
function getDefaultTips(type: CommType): string[] {
  return getDraftStyle(type).tips;
}

// ═══════════════════════════════════════════════════════════════════════
// saveProfile() — lines 1090-1116
// ═══════════════════════════════════════════════════════════════════════

async function saveProfile(
  db: Kysely<TenantDB>,
  userId: number,
  type: CommType,
  confidence: number,
  tips: string[],
  messageCount: number
): Promise<boolean> {
  try {
    const tipsJson = JSON.stringify(tips);
    await db
      .insertInto('customer_health_profiles')
      .values({
        user_id: userId,
        communication_type: type,
        confidence,
        communication_tips: tipsJson,
        last_analyzed_at: sql<Date>`NOW()`,
        message_count_analyzed: messageCount,
      })
      .onDuplicateKeyUpdate({
        communication_type: type,
        confidence,
        communication_tips: tipsJson,
        last_analyzed_at: sql<Date>`NOW()`,
        message_count_analyzed: messageCount,
      })
      .execute();
    return true;
  } catch (error) {
    console.error('CustomerHealthEngine saveProfile error:', error instanceof Error ? error.message : error);
    return false;
  }
}

// ═══════════════════════════════════════════════════════════════════════
// classifyCustomer() — top-level orchestrator, lines 604-656
// ═══════════════════════════════════════════════════════════════════════

export type ClassificationResult =
  | {
      type: CommType;
      confidence: number;
      tips: string[];
      messageCount: number;
      minRequired: number;
      insufficientData: true;
      emotion: string;
    }
  | {
      type: CommType;
      confidence: number;
      tips: string[];
      messageCount: number;
      scores: Scores;
      insufficientData: false;
      emotion: string;
    };

/**
 * `minMessages` defaults to 5 — the ROUTE's own literal default (see module
 * doc for why this differs from the PHP method signature's own `= 1`
 * default; the only real caller always passes an explicit value).
 */
export async function classifyCustomer(db: Kysely<TenantDB>, userId: number, minMessages = 5): Promise<ClassificationResult> {
  const messageCount = await getMessageCount(db, userId);
  const messages = await getRecentMessages(db, userId, 50);

  let emotion = 'neutral';
  if (messages.length > 0) {
    const latestMessage = messages[0]?.content ?? '';
    emotion = detectEmotion(latestMessage);
  }

  if (messageCount < minMessages || messages.length === 0) {
    return {
      type: 'A',
      confidence: 0.0,
      tips: getDefaultTips('A'),
      messageCount,
      minRequired: minMessages,
      insufficientData: true,
      emotion,
    };
  }

  const scores = analyzeMessagePatterns(messages);
  const type = determineType(scores);
  const confidence = calculateConfidence(scores, type);
  const tips = getDefaultTips(type);

  await saveProfile(db, userId, type, confidence, tips, messageCount);

  return {
    type,
    confidence: round2(confidence),
    tips,
    messageCount,
    scores,
    insufficientData: false,
    emotion,
  };
}
