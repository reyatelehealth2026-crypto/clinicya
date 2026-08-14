import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { callGeminiTextApi } from './geminiTextClient';

/**
 * ghostDraft.ts — canonical engine, literal port of
 * `classes/PharmacyGhostDraftService.php`, driven by api/inbox-v2.php's
 * `case 'ghost_draft': case 'ghost-draft': case 'generate_draft':` (lines
 * ~428-469) and `case 'learn_draft': case 'learn-draft':` (lines ~475-512
 * — `../../learn-draft/_lib/learnDraft.ts` imports `extractMentionedDrugs`
 * and `getLastCustomerMessage` from this module, the single-owner
 * cross-route import for this batch's `ghost-draft`/`learn-draft` pair).
 *
 * Ports: `loadApiKey()`/`isConfigured()` (lines 77-114), `generateDraft()`
 * (128-215), `buildDraftPrompt()`/`getStyleInstructions()`/`getStageLabel()`
 * (227-381), `parseDraftResponse()`/`extractJson()` (388-433),
 * `addDisclaimer()`/`containsPrescriptionDrug()`/`getDisclaimerText()`
 * (577-667), `getPredictionConfidence()`/`calculateDraftConfidence()`
 * (675-735), `extractMentionedDrugs()` (742-799), `getCustomerHealthProfile()`
 * (807-857 — THIS SERVICE'S OWN simpler private method, NOT
 * `../../customer-health/_lib/customerHealth.ts`'s `getHealthProfile()` —
 * see the "TWO DISTINCT HEALTH PROFILE READERS" note below),
 * `getDraftStyleForType()` (864-906 — likewise this service's OWN simpler
 * style lookup, NOT `../../draft-style/_lib/draftStyle.ts`'s `getDraftStyle()`
 * — see the "TWO DISTINCT DRAFT STYLE LOOKUPS" note below),
 * `getConversationHistory()`/`getLearningData()`/`getLastCustomerMessage()`
 * (914-977). `callAIWithTimeout()` (985-1050) is ported separately as
 * `./geminiTextClient.ts`'s `callGeminiTextApi()` — the ONLY network call
 * this module makes.
 *
 * ```php
 * public function generateDraft(int $userId, string $lastMessage, array $context = []): array
 * {
 *     $startTime = microtime(true);
 *     if (!$this->isConfigured()) {
 *         return ['success' => false, 'error' => 'API key not configured', 'draft' => null,
 *                 'confidence' => 0.0, 'alternatives' => [], 'disclaimer' => null];
 *     }
 *     try {
 *         $healthProfile = $context['healthProfile'] ?? $this->getCustomerHealthProfile($userId);
 *         $communicationType = $healthProfile['communicationType'] ?? 'A';
 *         $draftStyle = $context['draftStyle'] ?? $this->getDraftStyleForType($communicationType);
 *         $conversationHistory = $context['conversationHistory'] ?? $this->getConversationHistory($userId, 5);
 *         $learningData = $this->getLearningData($userId, 5);
 *         $prompt = $this->buildDraftPrompt($lastMessage, $healthProfile, $draftStyle, $conversationHistory, $learningData, $context);
 *         $response = $this->callAIWithTimeout($prompt, self::DRAFT_TIMEOUT);
 *         if (!$response['success']) {
 *             return ['success' => false, 'error' => $response['error'] ?? 'Failed to generate draft', 'draft' => null,
 *                     'confidence' => 0.0, 'alternatives' => [], 'disclaimer' => null,
 *                     'generationTimeMs' => round((microtime(true) - $startTime) * 1000)];
 *         }
 *         $draftData = $this->parseDraftResponse($response['text']);
 *         $mentionedDrugs = $this->extractMentionedDrugs($draftData['draft']);
 *         $disclaimer = $this->addDisclaimer($draftData['draft'], $mentionedDrugs);
 *         $confidence = $this->calculateDraftConfidence($userId, $draftData['confidence'] ?? 0.7);
 *         $generationTimeMs = round((microtime(true) - $startTime) * 1000);
 *         return ['success' => true, 'draft' => $draftData['draft'], 'confidence' => round($confidence, 2),
 *                 'alternatives' => $draftData['alternatives'] ?? [],
 *                 'disclaimer' => $disclaimer !== $draftData['draft'] ? $this->getDisclaimerText() : null,
 *                 'mentionedDrugs' => $mentionedDrugs, 'communicationType' => $communicationType, 'draftStyle' => $draftStyle,
 *                 'generationTimeMs' => $generationTimeMs, 'withinTimeout' => $generationTimeMs <= (self::DRAFT_TIMEOUT * 1000)];
 *     } catch (Exception $e) {
 *         return ['success' => false, 'error' => $e->getMessage(), 'draft' => null, 'confidence' => 0.0,
 *                 'alternatives' => [], 'disclaimer' => null, 'generationTimeMs' => round((microtime(true) - $startTime) * 1000)];
 *     }
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TWO DISTINCT HEALTH PROFILE READERS — do NOT merge
 * ═══════════════════════════════════════════════════════════════════════
 * `getCustomerHealthProfile()` below is `PharmacyGhostDraftService`'s OWN,
 * much simpler private method (`users.drug_allergies`/`current_medications`/
 * `medical_conditions` text-split + `customer_health_profiles.communication_type`/
 * `confidence` — no `array_filter` on the split pieces, no LINE-mini-app
 * overlay merge at all). `../../customer-health/_lib/customerHealth.ts`'s
 * `getHealthProfile()` is a DIFFERENT, unrelated PHP method on a DIFFERENT
 * class (`CustomerHealthEngineService`) with substantially more logic
 * (mini-app overlay/merge, purchase history, allergy dedup). Both are
 * ported literally and independently, per this batch's brief.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TWO DISTINCT DRAFT STYLE LOOKUPS — do NOT merge
 * ═══════════════════════════════════════════════════════════════════════
 * `getDraftStyleForType()` below is `PharmacyGhostDraftService`'s OWN
 * simpler style lookup — 8 keys (`type`, `typeName`, `typeNameTh`,
 * `maxWords`, `useEmoji`, `includeDetails`, `tone`, `toneTh`), no `tips`/
 * `includePrice`/`includeComparison`/`includeScientific`/`responseStyle`/
 * `sampleOpening`/`sampleClosing`. `../../draft-style/_lib/draftStyle.ts`'s
 * `getDraftStyle()` is `CustomerHealthEngineService`'s DIFFERENT, richer
 * method. Structurally similar (same A/B/C switch shape) but NOT
 * byte-identical — both ported literally and independently.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * DEAD CODE, NOT PORTED — `containsPrescriptionDrug()`'s DB branch
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `containsPrescriptionDrug()` has a 4th check (after keywords, known
 * drug names, and mentioned-drugs-vs-known-names) that queries
 * `business_items WHERE (name IN (...) OR sku IN (...)) AND is_prescription
 * = 1` inside its own try/catch. `business_items.is_prescription` does not
 * exist (confirmed against `BusinessItems` in
 * `packages/db/src/generated/tenant-db.d.ts` — the real column is
 * `requires_prescription`) — this branch's `SELECT` ALWAYS throws in
 * production, ALWAYS caught, ALWAYS contributes nothing. Per this batch's
 * brief this is intentionally left dead-but-documented (unlike
 * `../../customer-health/_lib/customerHealth.ts`'s `getRecentPurchasedMedications()`,
 * which the brief explicitly directs to fix forward with the real column —
 * these are two independent decisions for two independent PHP methods, not
 * a contradiction). No query against `requires_prescription` is invented
 * here — that would be new, never-real-PHP-tested behavior, not a literal
 * port.
 */

// ═══════════════════════════════════════════════════════════════════════
// Small shared helpers
// ═══════════════════════════════════════════════════════════════════════

/** PHP `stripos`/`mb_stripos` !== false — case-insensitive substring check (Thai has no case, so plain lowercasing suffices). */
function ciIncludes(haystack: string, needle: string): boolean {
  return haystack.toLowerCase().includes(needle.toLowerCase());
}

/** `mb_substr($s, 0, $len)` — first `len` Unicode code points (not UTF-16 code units). */
function mbSubstr(str: string, len: number): string {
  return Array.from(str).slice(0, len).join('');
}

/** PHP `round($x, 2)` — half-up rounding to 2 decimal places. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

/** PHP `(float) $v` — non-numeric -> 0.0, matching PHP's cast (NOT a `?? fallback`-style default for garbage input). */
function phpFloatCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value === 'string') {
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : 0;
  }
  return 0;
}

/** PHP `!empty($s)` for a string field — `null`/`''`/`'0'` all count as empty. */
function isNonEmptyPhpString(value: string | null | undefined): value is string {
  return value !== null && value !== undefined && value !== '' && value !== '0';
}

/** `$context[$key] ?? $default` — PHP's `??` triggers on BOTH a missing key and an explicit `null` value. */
function contextValue<T>(context: Record<string, unknown>, key: string): T | undefined {
  const v = context[key];
  return v === undefined || v === null ? undefined : (v as T);
}

export const DEFAULT_MODEL = 'gemini-2.5-flash';
export const DRAFT_TIMEOUT_SECONDS = 15;

// ═══════════════════════════════════════════════════════════════════════
// loadApiKey() / isConfigured() — lines 77-114
// ═══════════════════════════════════════════════════════════════════════

export interface AiCredentials {
  apiKey: string | null;
  model: string;
}

interface AiSettingsRow {
  gemini_api_key: string | null;
  model: string | null;
}

/**
 * PHP: `SELECT gemini_api_key, model FROM ai_settings WHERE line_account_id
 * = ? OR line_account_id IS NULL ORDER BY line_account_id DESC LIMIT 1`,
 * then the `defined('GEMINI_API_KEY')` config-constant fallback (ported as
 * `process.env.GEMINI_API_KEY` — see this batch's build report for the
 * `packages/config` zod-schema flag).
 */
export async function loadGhostDraftCredentials(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<AiCredentials> {
  try {
    const result = await sql<AiSettingsRow>`
      SELECT gemini_api_key, model
      FROM ai_settings
      WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
      ORDER BY line_account_id DESC
      LIMIT 1
    `.execute(db);
    const row = result.rows[0];

    if (row && isNonEmptyPhpString(row.gemini_api_key)) {
      return { apiKey: row.gemini_api_key, model: row.model ?? DEFAULT_MODEL };
    }
  } catch {
    // Table might not exist.
  }

  const envKey = process.env.GEMINI_API_KEY;
  return { apiKey: isNonEmptyPhpString(envKey) ? envKey : null, model: DEFAULT_MODEL };
}

export function isGhostDraftConfigured(credentials: AiCredentials): boolean {
  return !!credentials.apiKey;
}

// ═══════════════════════════════════════════════════════════════════════
// buildDraftPrompt() / getStyleInstructions() / getStageLabel() — lines
// 227-381 (pure string building, no DB)
// ═══════════════════════════════════════════════════════════════════════

function getStyleInstructions(draftStyle: Record<string, unknown>): string {
  const type = typeof draftStyle.type === 'string' ? draftStyle.type : 'A';
  const maxWords = typeof draftStyle.maxWords === 'number' ? draftStyle.maxWords : 50;
  const tone = typeof draftStyle.toneTh === 'string' ? draftStyle.toneTh : 'มืออาชีพ';

  switch (type) {
    case 'A': // Direct
      return `- ตอบสั้น กระชับ ไม่เกิน ${maxWords} คำ
- น้ำเสียง: ${tone}
- บอกชื่อยา ราคา วิธีใช้ ชัดเจน
- ไม่ต้องอธิบายรายละเอียดมาก
- เสนอทางเลือกไม่เกิน 2-3 ตัว`;

    case 'B': // Concerned
      return `- ตอบอย่างเห็นอกเห็นใจ ไม่เกิน ${maxWords} คำ
- น้ำเสียง: ${tone}
- แสดงความเข้าใจและห่วงใย
- อธิบายความปลอดภัยของยา
- ให้ความมั่นใจว่าอาการจะดีขึ้น
- ใช้ emoji ได้ตามเหมาะสม 🙏😊`;

    case 'C': // Detail-oriented
      return `- ให้ข้อมูลครบถ้วน ละเอียด ไม่เกิน ${maxWords} คำ
- น้ำเสียง: ${tone}
- เปรียบเทียบยาหลายตัว
- อธิบายกลไกการออกฤทธิ์
- แนบข้อมูลทางวิทยาศาสตร์ถ้ามี`;

    default:
      return `- ตอบอย่างมืออาชีพ ไม่เกิน ${maxWords} คำ`;
  }
}

function getStageLabel(stage: string): string {
  const labels: Record<string, string> = {
    symptom_assessment: 'ประเมินอาการ',
    drug_recommendation: 'แนะนำยา',
    purchase: 'ตัดสินใจซื้อ',
    follow_up: 'ติดตามผล',
  };
  return labels[stage] ?? stage;
}

/** `is_array($a) ? ($a['name'] ?? '') : $a` — used for both allergies and medications entries. */
function nameOf(v: unknown): string {
  if (typeof v === 'string') return v;
  if (v && typeof v === 'object' && 'name' in v) {
    const n = (v as Record<string, unknown>).name;
    return typeof n === 'string' ? n : n === undefined || n === null ? '' : String(n);
  }
  return v === undefined || v === null ? '' : String(v);
}

interface ConversationHistoryRow {
  role: string;
  content: string;
}

interface LearningDataRow {
  customer_message: string;
  ai_draft: string;
  pharmacist_final: string;
  was_accepted: number;
  edit_distance: number;
}

function buildDraftPrompt(
  lastMessage: string,
  healthProfile: Record<string, unknown>,
  draftStyle: Record<string, unknown>,
  conversationHistory: readonly ConversationHistoryRow[],
  learningData: readonly LearningDataRow[],
  context: Record<string, unknown>
): string {
  const parts: string[] = [];

  parts.push(
    `[บทบาท: เภสัชกรผู้เชี่ยวชาญ]
คุณกำลังช่วยเภสัชกรร่างคำตอบสำหรับลูกค้า กรุณาร่างคำตอบที่เหมาะสมตามสไตล์การสื่อสารของลูกค้า`
  );

  parts.push(`[สไตล์การตอบ]\n${getStyleInstructions(draftStyle)}`);

  if (Object.keys(healthProfile).length > 0) {
    let profileText = '[ข้อมูลลูกค้า]\n';

    const allergies = healthProfile.allergies;
    if (Array.isArray(allergies) && allergies.length > 0) {
      const names = allergies.map(nameOf).filter((n) => n !== '' && n !== '0');
      profileText += `- แพ้ยา: ${names.join(', ')}\n`;
    }

    const medications = healthProfile.medications;
    if (Array.isArray(medications) && medications.length > 0) {
      const names = medications.map(nameOf).filter((n) => n !== '' && n !== '0');
      profileText += `- ยาที่ใช้อยู่: ${names.join(', ')}\n`;
    }

    const conditions = healthProfile.conditions;
    if (conditions !== undefined && conditions !== null && conditions !== '') {
      const conditionText = Array.isArray(conditions) ? conditions.join(', ') : String(conditions);
      profileText += `- โรคประจำตัว: ${conditionText}\n`;
    }

    parts.push(profileText);
  }

  if (conversationHistory.length > 0) {
    let historyText = '[ประวัติการสนทนา]\n';
    for (const msg of conversationHistory) {
      const role = (msg.role ?? 'user') === 'user' ? 'ลูกค้า' : 'เภสัชกร';
      historyText += `- ${role}: ${mbSubstr(msg.content ?? '', 100)}\n`;
    }
    parts.push(historyText);
  }

  if (learningData.length > 0) {
    let learningText = '[ตัวอย่างคำตอบที่ดี]\n';
    for (const example of learningData.slice(0, 3)) {
      if (example.was_accepted) {
        learningText += `- คำถาม: ${mbSubstr(example.customer_message, 50)}\n`;
        learningText += `  คำตอบ: ${mbSubstr(example.pharmacist_final, 100)}\n`;
      }
    }
    parts.push(learningText);
  }

  const stage = context.stage;
  if (typeof stage === 'string' && stage !== '') {
    parts.push(`[ขั้นตอนปัจจุบัน]: ${getStageLabel(stage)}`);
  }

  const symptoms = context.symptoms;
  if (Array.isArray(symptoms) && symptoms.length > 0) {
    parts.push(`[อาการที่ตรวจพบ]: ${symptoms.join(', ')}`);
  }

  parts.push(`[ข้อความลูกค้า]\n${lastMessage}`);

  parts.push(`[คำสั่ง]
กรุณาร่างคำตอบในรูปแบบ JSON ดังนี้:
{
    "draft": "คำตอบที่ร่างไว้",
    "confidence": 0.0-1.0,
    "alternatives": ["ทางเลือกอื่น 1", "ทางเลือกอื่น 2"],
    "mentionedDrugs": ["ชื่อยาที่กล่าวถึง"]
}

ข้อควรระวัง:
- ตอบตามสไตล์ที่กำหนด (ความยาว, น้ำเสียง)
- หลีกเลี่ยงยาที่ลูกค้าแพ้
- ระวังปฏิกิริยากับยาที่ใช้อยู่
- ถ้าเป็นยาแพทย์สั่ง ให้แนะนำพบแพทย์`);

  return parts.join('\n\n');
}

// ═══════════════════════════════════════════════════════════════════════
// parseDraftResponse() / extractJson() — lines 388-433
// ═══════════════════════════════════════════════════════════════════════

interface ParsedDraft {
  draft: string;
  confidence: number;
  alternatives: string[];
  mentionedDrugs: unknown[];
}

/**
 * PHP's `extractJson()` has return type `?array` — if `json_decode()`
 * succeeds on a top-level JSON scalar (e.g. the literal text `"5"`), PHP
 * would actually violate its own return-type hint (a latent bug, not a
 * documented contract). This port only ever returns a parsed plain object
 * (or `null`), which is the only shape `parseDraftResponse()` ever does
 * anything useful with (`isset($json['draft'])` requires array access).
 */
function extractJson(text: string): Record<string, unknown> | null {
  const braceMatch = text.match(/\{[\s\S]*\}/);
  if (braceMatch) {
    try {
      const parsed: unknown = JSON.parse(braceMatch[0]);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        return parsed as Record<string, unknown>;
      }
    } catch {
      // fall through to a direct parse of the whole text
    }
  }

  try {
    const parsed: unknown = JSON.parse(text);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      return parsed as Record<string, unknown>;
    }
  } catch {
    // fall through to the raw-text fallback in parseDraftResponse()
  }

  return null;
}

function parseDraftResponse(responseText: string): ParsedDraft {
  const json = extractJson(responseText);

  if (json && json.draft !== undefined && json.draft !== null) {
    return {
      draft: typeof json.draft === 'string' ? json.draft : String(json.draft),
      confidence: phpFloatCast(contextValue<unknown>(json, 'confidence') ?? 0.7),
      alternatives: Array.isArray(json.alternatives) ? (json.alternatives as string[]) : [],
      mentionedDrugs: Array.isArray(json.mentionedDrugs) ? (json.mentionedDrugs as unknown[]) : [],
    };
  }

  return {
    draft: responseText.trim(),
    confidence: 0.5,
    alternatives: [],
    mentionedDrugs: [],
  };
}

// ═══════════════════════════════════════════════════════════════════════
// extractMentionedDrugs() — lines 742-799 (EXPORTED — imported by
// ../../learn-draft/_lib/learnDraft.ts)
// ═══════════════════════════════════════════════════════════════════════

export interface MentionedDrug {
  name: string;
  sku: string | null;
}

const COMMON_DRUGS = [
  'พาราเซตามอล', 'paracetamol', 'ไทลินอล', 'tylenol',
  'ไอบูโพรเฟน', 'ibuprofen', 'แอสไพริน', 'aspirin',
  'ยาแก้แพ้', 'antihistamine', 'ยาแก้ไอ', 'ยาลดน้ำมูก',
  'ยาธาตุน้ำขาว', 'ยาธาตุน้ำแดง', 'ยาหม่อง', 'ยาดม',
];

interface BusinessItemNameSkuRow {
  name: string;
  sku: string | null;
}

export async function extractMentionedDrugs(
  db: Kysely<TenantDB>,
  lineAccountId: number | null,
  text: string
): Promise<MentionedDrug[]> {
  const drugs: MentionedDrug[] = [];

  try {
    const result = await sql<BusinessItemNameSkuRow>`
      SELECT name, sku
      FROM business_items
      WHERE is_active = 1
      AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      LIMIT 500
    `.execute(db);

    const textLower = text.toLowerCase();
    for (const product of result.rows) {
      const nameLower = product.name.toLowerCase();
      if (textLower.includes(nameLower)) {
        drugs.push({ name: product.name, sku: product.sku });
      }
    }
  } catch {
    // Ignore
  }

  for (const drug of COMMON_DRUGS) {
    if (ciIncludes(text, drug)) {
      const exists = drugs.some((d) => ciIncludes(d.name, drug));
      if (!exists) {
        drugs.push({ name: drug, sku: null });
      }
    }
  }

  return drugs;
}

// ═══════════════════════════════════════════════════════════════════════
// getCustomerHealthProfile() — lines 807-857 (THIS SERVICE'S OWN, simpler
// version — see module doc)
// ═══════════════════════════════════════════════════════════════════════

interface GhostDraftHealthProfile {
  allergies: string[];
  medications: string[];
  conditions: string[];
  communicationType: string;
  confidence?: number;
}

interface UserProfileTextRow {
  drug_allergies: string | null;
  current_medications: string | null;
  medical_conditions: string | null;
}

async function getCustomerHealthProfile(db: Kysely<TenantDB>, userId: number): Promise<GhostDraftHealthProfile> {
  const profile: GhostDraftHealthProfile = { allergies: [], medications: [], conditions: [], communicationType: 'A' };

  try {
    const userResult = await sql<UserProfileTextRow>`
      SELECT drug_allergies, current_medications, medical_conditions FROM users WHERE id = ${userId}
    `.execute(db);
    const user = userResult.rows[0];

    if (user) {
      // NOTE: unlike CustomerHealthEngineService's getAllergies()/getMedications(),
      // this method's own split does NOT array_filter() the pieces — an empty
      // trailing piece (e.g. a trailing comma) survives as `''` here, literally.
      if (isNonEmptyPhpString(user.drug_allergies)) {
        profile.allergies = user.drug_allergies.split(/[,\n]+/).map((s) => s.trim());
      }
      if (isNonEmptyPhpString(user.current_medications)) {
        profile.medications = user.current_medications.split(/[,\n]+/).map((s) => s.trim());
      }
      if (isNonEmptyPhpString(user.medical_conditions)) {
        profile.conditions = user.medical_conditions.split(/[,\n]+/).map((s) => s.trim());
      }
    }

    const chpResult = await sql<{ communication_type: string | null; confidence: unknown }>`
      SELECT communication_type, confidence FROM customer_health_profiles WHERE user_id = ${userId}
    `.execute(db);
    const healthProfile = chpResult.rows[0];

    if (healthProfile && isNonEmptyPhpString(healthProfile.communication_type)) {
      profile.communicationType = healthProfile.communication_type;
      profile.confidence = phpFloatCast(healthProfile.confidence);
    }
  } catch {
    // Return default profile.
  }

  return profile;
}

// ═══════════════════════════════════════════════════════════════════════
// getDraftStyleForType() — lines 864-906 (THIS SERVICE'S OWN, simpler
// version — see module doc)
// ═══════════════════════════════════════════════════════════════════════

interface GhostDraftStyle {
  type: 'A' | 'B' | 'C';
  typeName: string;
  typeNameTh: string;
  maxWords: number;
  useEmoji: boolean;
  includeDetails: boolean;
  tone: string;
  toneTh: string;
}

function getDraftStyleForType(type: string): GhostDraftStyle {
  switch (type) {
    case 'A': // Direct
      return { type: 'A', typeName: 'Direct', typeNameTh: 'ตรงประเด็น', maxWords: 50, useEmoji: false, includeDetails: false, tone: 'professional', toneTh: 'มืออาชีพ' };

    case 'B': // Concerned
      return { type: 'B', typeName: 'Concerned', typeNameTh: 'ห่วงใย', maxWords: 150, useEmoji: true, includeDetails: true, tone: 'empathetic', toneTh: 'เห็นอกเห็นใจ' };

    case 'C': // Detail-oriented
      return { type: 'C', typeName: 'Detail-oriented', typeNameTh: 'ใส่ใจรายละเอียด', maxWords: 300, useEmoji: false, includeDetails: true, tone: 'informative', toneTh: 'ให้ข้อมูล' };

    default:
      return getDraftStyleForType('A');
  }
}

// ═══════════════════════════════════════════════════════════════════════
// getConversationHistory() / getLearningData() / getLastCustomerMessage()
// — lines 914-977
// ═══════════════════════════════════════════════════════════════════════

async function getConversationHistory(db: Kysely<TenantDB>, userId: number, limit = 5): Promise<ConversationHistoryRow[]> {
  try {
    const result = await sql<ConversationHistoryRow>`
      SELECT CASE WHEN direction = 'incoming' THEN 'user' ELSE 'assistant' END as role, content
      FROM messages
      WHERE user_id = ${userId} AND message_type = 'text' AND content != ''
      ORDER BY created_at DESC
      LIMIT ${limit}
    `.execute(db);
    return result.rows.slice().reverse(); // array_reverse()
  } catch {
    return [];
  }
}

async function getLearningData(db: Kysely<TenantDB>, userId: number, limit = 5): Promise<LearningDataRow[]> {
  try {
    const result = await sql<LearningDataRow>`
      SELECT customer_message, ai_draft, pharmacist_final, was_accepted, edit_distance
      FROM pharmacy_ghost_learning
      WHERE user_id = ${userId} AND was_accepted = 1
      ORDER BY created_at DESC
      LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/** EXPORTED — imported by `../../learn-draft/_lib/learnDraft.ts`. */
export async function getLastCustomerMessage(db: Kysely<TenantDB>, userId: number): Promise<string> {
  try {
    const result = await sql<{ content: string | null }>`
      SELECT content FROM messages
      WHERE user_id = ${userId} AND direction = 'incoming' AND message_type = 'text'
      ORDER BY created_at DESC
      LIMIT 1
    `.execute(db);
    return result.rows[0]?.content ?? '';
  } catch {
    return '';
  }
}

// ═══════════════════════════════════════════════════════════════════════
// addDisclaimer() / containsPrescriptionDrug() / getDisclaimerText() —
// lines 577-667
// ═══════════════════════════════════════════════════════════════════════

const PRESCRIPTION_KEYWORDS = [
  // Thai
  'ยาอันตราย', 'ยาควบคุมพิเศษ', 'ยาแพทย์สั่ง', 'ต้องมีใบสั่งยา',
  'ยาปฏิชีวนะ', 'ยาฆ่าเชื้อ', 'ยาสเตียรอยด์', 'ยากดภูมิ',
  'ยานอนหลับ', 'ยาคลายกังวล', 'ยาแก้ปวดกลุ่มโอปิออยด์',
  'ยาลดความดัน', 'ยาเบาหวาน', 'ยาหัวใจ', 'ยาไทรอยด์',
  'ยาต้านไวรัส', 'ยาเคมีบำบัด', 'ยาฮอร์โมน',
  // English
  'prescription', 'controlled', 'antibiotic', 'steroid',
  'sedative', 'opioid', 'antihypertensive', 'antidiabetic',
  'cardiac', 'thyroid', 'antiviral', 'chemotherapy', 'hormone',
];

const PRESCRIPTION_DRUGS = [
  // Antibiotics
  'amoxicillin', 'azithromycin', 'ciprofloxacin', 'doxycycline',
  'อะม็อกซีซิลลิน', 'อะซิโธรมัยซิน',
  // Blood pressure
  'amlodipine', 'losartan', 'enalapril', 'metoprolol',
  'แอมโลดิปีน', 'โลซาร์แทน',
  // Diabetes
  'metformin', 'glipizide', 'insulin',
  'เมทฟอร์มิน', 'อินซูลิน',
  // Pain (controlled)
  'tramadol', 'codeine', 'morphine',
  'ทรามาดอล', 'โคเดอีน',
  // Sedatives
  'diazepam', 'alprazolam', 'lorazepam',
  'ไดอะซีแพม', 'อัลปราโซแลม',
  // Steroids
  'prednisolone', 'dexamethasone',
  'เพรดนิโซโลน', 'เดกซาเมทาโซน',
];

function getDisclaimerText(): string {
  return '⚠️ หมายเหตุ: ยานี้เป็นยาที่ต้องใช้ตามคำสั่งแพทย์ กรุณาปรึกษาแพทย์หรือเภสัชกรก่อนใช้ยา';
}

/**
 * PHP's own DB-backed 4th check is dead code and NOT ported — see the
 * module doc's "DEAD CODE, NOT PORTED" section above.
 */
function containsPrescriptionDrug(draft: string, mentionedDrugs: readonly MentionedDrug[]): boolean {
  const textToCheck = draft.toLowerCase();

  for (const keyword of PRESCRIPTION_KEYWORDS) {
    if (ciIncludes(textToCheck, keyword)) return true;
  }

  for (const drug of PRESCRIPTION_DRUGS) {
    if (ciIncludes(textToCheck, drug)) return true;
  }

  for (const drug of mentionedDrugs) {
    const drugLower = (drug.name ?? '').toLowerCase();
    for (const prescriptionDrug of PRESCRIPTION_DRUGS) {
      if (ciIncludes(drugLower, prescriptionDrug)) return true;
    }
  }

  // DEAD CODE, NOT PORTED: PHP's `business_items ... AND is_prescription = 1`
  // branch — see module doc.

  return false;
}

function addDisclaimer(draft: string, mentionedDrugs: readonly MentionedDrug[]): string {
  const hasPrescriptionDrug = containsPrescriptionDrug(draft, mentionedDrugs);

  if (hasPrescriptionDrug) {
    const disclaimer = getDisclaimerText();
    if (!draft.includes('ปรึกษาแพทย์') && !draft.includes('พบแพทย์') && !draft.includes('ใบสั่งยา')) {
      return `${draft}\n\n${disclaimer}`;
    }
  }

  return draft;
}

// ═══════════════════════════════════════════════════════════════════════
// getPredictionConfidence() / calculateDraftConfidence() — lines 675-735
// ═══════════════════════════════════════════════════════════════════════

interface PredictionStatsRow {
  total_drafts: unknown;
  accepted_drafts: unknown;
  avg_edit_distance: unknown;
}

async function getPredictionConfidence(db: Kysely<TenantDB>, userId: number): Promise<number> {
  try {
    const result = await sql<PredictionStatsRow>`
      SELECT
        COUNT(*) as total_drafts,
        SUM(was_accepted) as accepted_drafts,
        AVG(edit_distance) as avg_edit_distance
      FROM pharmacy_ghost_learning
      WHERE user_id = ${userId}
    `.execute(db);
    const stats = result.rows[0];

    const totalDrafts = Number(stats?.total_drafts ?? 0);
    if (!stats || totalDrafts === 0) {
      return 0.5;
    }

    const acceptedDrafts = Number(stats.accepted_drafts ?? 0);
    const avgEditDistance = Number(stats.avg_edit_distance ?? 0);

    const acceptanceRate = acceptedDrafts / totalDrafts;
    const editDistanceFactor = Math.max(0, 1 - avgEditDistance / 100);

    let confidence = acceptanceRate * 0.6 + editDistanceFactor * 0.4;

    if (totalDrafts >= 10) {
      confidence = Math.min(1.0, confidence * 1.1);
    }

    return round2(Math.max(0.0, Math.min(1.0, confidence)));
  } catch {
    return 0.5;
  }
}

async function calculateDraftConfidence(db: Kysely<TenantDB>, userId: number, aiConfidence: number): Promise<number> {
  const learningConfidence = await getPredictionConfidence(db, userId);
  return aiConfidence * 0.5 + learningConfidence * 0.5;
}

// ═══════════════════════════════════════════════════════════════════════
// generateDraft() — top-level orchestrator, lines 128-215. The ONLY
// network call is via `callGeminiTextApi()` (`./geminiTextClient.ts`).
// ═══════════════════════════════════════════════════════════════════════

export type GenerateDraftResult =
  | {
      success: false;
      error: string;
      draft: null;
      confidence: number;
      alternatives: string[];
      disclaimer: null;
    }
  | {
      success: false;
      error: string;
      draft: null;
      confidence: number;
      alternatives: string[];
      disclaimer: null;
      generationTimeMs: number;
    }
  | {
      success: true;
      draft: string;
      confidence: number;
      alternatives: string[];
      disclaimer: string | null;
      mentionedDrugs: MentionedDrug[];
      communicationType: string;
      draftStyle: Record<string, unknown>;
      generationTimeMs: number;
      withinTimeout: boolean;
    };

export async function generateDraft(
  db: Kysely<TenantDB>,
  lineAccountId: number | null,
  userId: number,
  lastMessage: string,
  context: Record<string, unknown>,
  credentials: AiCredentials
): Promise<GenerateDraftResult> {
  const startTime = Date.now();
  const { apiKey, model } = credentials;

  if (!apiKey) {
    return { success: false, error: 'API key not configured', draft: null, confidence: 0.0, alternatives: [], disclaimer: null };
  }

  try {
    const healthProfile: Record<string, unknown> =
      contextValue<Record<string, unknown>>(context, 'healthProfile') ??
      ((await getCustomerHealthProfile(db, userId)) as unknown as Record<string, unknown>);
    const communicationType = typeof healthProfile.communicationType === 'string' ? healthProfile.communicationType : 'A';
    const draftStyle: Record<string, unknown> =
      contextValue<Record<string, unknown>>(context, 'draftStyle') ?? (getDraftStyleForType(communicationType) as unknown as Record<string, unknown>);
    const conversationHistory =
      contextValue<ConversationHistoryRow[]>(context, 'conversationHistory') ?? (await getConversationHistory(db, userId, 5));
    const learningData = await getLearningData(db, userId, 5);

    const prompt = buildDraftPrompt(lastMessage, healthProfile, draftStyle, conversationHistory, learningData, context);

    const response = await callGeminiTextApi({ apiKey, model, prompt, timeoutMs: DRAFT_TIMEOUT_SECONDS * 1000 });

    if (!response.success) {
      return {
        success: false,
        error: response.error || 'Failed to generate draft',
        draft: null,
        confidence: 0.0,
        alternatives: [],
        disclaimer: null,
        generationTimeMs: Date.now() - startTime,
      };
    }

    const draftData = parseDraftResponse(response.text);
    const mentionedDrugs = await extractMentionedDrugs(db, lineAccountId, draftData.draft);
    const disclaimerApplied = addDisclaimer(draftData.draft, mentionedDrugs);
    const confidence = await calculateDraftConfidence(db, userId, draftData.confidence);

    const generationTimeMs = Date.now() - startTime;

    return {
      success: true,
      draft: draftData.draft,
      confidence: round2(confidence),
      alternatives: draftData.alternatives,
      disclaimer: disclaimerApplied !== draftData.draft ? getDisclaimerText() : null,
      mentionedDrugs,
      communicationType,
      draftStyle,
      generationTimeMs,
      withinTimeout: generationTimeMs <= DRAFT_TIMEOUT_SECONDS * 1000,
    };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return {
      success: false,
      error: message,
      draft: null,
      confidence: 0.0,
      alternatives: [],
      disclaimer: null,
      generationTimeMs: Date.now() - startTime,
    };
  }
}
