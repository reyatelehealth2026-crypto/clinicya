import { createHash } from 'crypto';
import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getImageData } from './imageResolver';
import { callGeminiVisionApi, type GeminiVisionResult } from './geminiVisionClient';

/**
 * imageAnalyzer.ts — canonical shared engine for this builder's 3 routes
 * (`analyze-symptom`, `analyze-drug`, `analyze-prescription`), a literal
 * port of `classes/PharmacyImageAnalyzerService.php` (1,448 LOC) MINUS the
 * two network seams that live in `geminiVisionClient.ts` (the Gemini call)
 * and `imageResolver.ts` (the image-bytes fetch) — everything here is
 * prompt-building, response-parsing, caching, matching, and
 * interaction/allergy logic: pure/DB-only, no `fetch()` call sites.
 *
 * `analyze-drug`/`analyze-prescription` import `identifyDrug`/
 * `ocrPrescription` directly from this file (single-owner cross-route
 * import, all 3 routes owned by this same builder this round — mirrors the
 * precedent set by Phase 4 batch 4a's drug-info -> max-discount import).
 *
 * `getAnalysisHistory()` (PHP lines 1386-1421) and `clearExpiredCache()`
 * (lines 1425-1448) are NOT ported — neither is called by any of the 3
 * ported actions (out of scope for this batch, per brief).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED PHP BUG, FIXED FORWARD — cache-hit responses missing `success`
 * ═══════════════════════════════════════════════════════════════════════
 * In `analyzeSymptom()`/`identifyDrug()`, PHP orders its work as: parse ->
 * compute urgency/matched-product fields -> **cache the array** ->
 * `$analysis['success'] = true;` -> `$analysis['imageHash'] = $imageHash;`
 * -> `return $analysis;`. Caching happens *before* `success`/`imageHash`
 * are added, so the JSON persisted to `symptom_analysis_cache` /
 * `drug_recognition_cache` never contains either key. On a cache HIT,
 * `getCachedSymptomAnalysis()`/`getCachedDrugRecognition()` decode that
 * JSON, bolt on `cached`/`cachedAt`, and return it directly — `success` is
 * never present. `analyzeSymptom()`/`identifyDrug()` then `return $cached;`
 * as-is (no override). The API layer (`api/inbox-v2.php`) checks
 * `!($result['success'] ?? false)` — with `success` absent, this is always
 * `true`, so **every cache hit today, in production, is reported to the
 * caller as a failure** (`sendError($result['error'] ?? '...ล้มเหลว')`,
 * since `error` is absent too, it's always the generic default message).
 * This is deterministic and 100%-reproducible on every cache hit — not a
 * rare edge case.
 *
 * `ocrPrescription()` has no cache-READ path at all (no
 * `getCachedPrescriptionOCR()` method exists in the PHP class — its
 * `prescription_ocr_results` table is INSERT-only, per the brief), so this
 * bug is specific to the symptom/drug caches.
 *
 * This is a deliberate, documented FIX-FORWARD deviation (not a
 * reproduction of a bug that would make the cache actively harmful) — same
 * precedent as Phase 4 batch 3's `assign-conversation` route (see
 * `docs/runbooks/phase4-batch3-inbox-actions-parity.md` §1, "PHP's
 * `InboxService::assignConversation()` always returns a truthy non-empty
 * array even on its own failure paths... This port reads the domain
 * result's own `.success` field... instead of reproducing that bug").
 * `getCachedSymptomAnalysis()`/`getCachedDrugRecognition()` below add
 * `success: true` and `imageHash` themselves on a cache hit, so a cached
 * result is reported to the caller exactly like a fresh analysis — a
 * functioning short-circuit, not a permanently-broken one. Flagged for
 * mig-orchestrator in this batch's final report; the runbook (infra-owned)
 * should record it as an intentional deviation.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED SCHEMA-DRIFT FIX — `matchDrugToProduct()`'s `stock_quantity` -> `stock`
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `matchDrugToProduct()` (lines 1228-1264) explicitly selects
 * `SELECT id, name, price, stock_quantity FROM business_items ...` —
 * verified against both `packages/db/src/generated/tenant-db.d.ts`'s
 * `BusinessItems` interface AND `database/install_complete_latest.sql`'s
 * real DDL: no `stock_quantity` column exists on `business_items` anywhere
 * in this schema; the real column is `stock` (same column already used by
 * `drug-info`/`drug-inventory`/`low-stock-drugs` elsewhere in this
 * codebase). EFFECT IN CURRENT PRODUCTION: this explicit `SELECT ...
 * stock_quantity ...` throws a PDOException ("Unknown column") on EVERY
 * call — caught by `matchDrugToProduct()`'s own `catch (PDOException $e) {
 * // Ignore }`, which then falls through to `return null;` — so
 * `identifyDrug()` NEVER successfully attaches a matched product in
 * production today, regardless of whether a real matching row exists
 * (`matchedProductId`/`matchedProductName`/`price` are always `null`,
 * `inStock` always `false`). Same root cause, same fix-forward precedent
 * already established by `drug-inventory`'s and `low-stock-drugs`'s own
 * `is_prescription` -> `requires_prescription` finding (see
 * `../../drug-inventory/_lib/drugInventory.ts`'s module doc for the full
 * writeup of that precedent's shape). This port selects the real `stock`
 * column instead — a deliberate, documented fix-forward deviation, not a
 * reproduction of the always-broken PHP behavior.
 */

// ─────────────────────────────────────────────────────────────────────────
// Shared constants (PHP class constants, lines 22-33)
// ─────────────────────────────────────────────────────────────────────────

export const DEFAULT_MODEL = 'gemini-2.5-flash';
const CACHE_EXPIRY_HOURS = 24;

const SEVERITY_MILD = 'mild';
const SEVERITY_MODERATE = 'moderate';
const SEVERITY_SEVERE = 'severe';
const SEVERITY_URGENT = 'urgent';

/** Literal port of `$urgentConditions` (PHP lines 35-57) — order preserved (first match wins). */
const URGENT_CONDITIONS: readonly string[] = [
  'severe allergic reaction',
  'anaphylaxis',
  'difficulty breathing',
  'chest pain',
  'severe bleeding',
  'deep wound',
  'burn',
  'severe infection',
  'high fever',
  'loss of consciousness',
  'severe swelling',
  'อาการแพ้รุนแรง',
  'หายใจลำบาก',
  'เจ็บหน้าอก',
  'เลือดออกมาก',
  'แผลลึก',
  'ไฟไหม้',
  'ติดเชื้อรุนแรง',
  'ไข้สูงมาก',
  'หมดสติ',
  'บวมมาก',
];

// ─────────────────────────────────────────────────────────────────────────
// PHP-semantics helpers
// ─────────────────────────────────────────────────────────────────────────

/** PHP `empty($v)` on a scalar: true for `null`/`undefined`/`''`/`'0'`/`0`/`false`. */
function phpEmpty(value: unknown): boolean {
  return value === null || value === undefined || value === '' || value === '0' || value === 0 || value === false;
}

/** `!empty($v)` narrowed to "is a usable non-empty string". */
function phpNotEmptyString(value: unknown): value is string {
  return typeof value === 'string' && !phpEmpty(value);
}

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v` — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

// ─────────────────────────────────────────────────────────────────────────
// API key / model resolution — port of loadApiKey() (lines 71-100) + isConfigured() (105-108)
// ─────────────────────────────────────────────────────────────────────────

export interface AiConfig {
  apiKey: string | null;
  model: string;
}

/**
 * Port of `loadApiKey()`. Query row wins if `gemini_api_key` is non-empty
 * (`model` in that branch is `$result['model'] ?? self::DEFAULT_MODEL` —
 * PHP `??`, null-only fallback, an empty-string `model` column value is
 * kept as-is, NOT replaced). Otherwise falls back to
 * `defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)` (here:
 * `process.env.GEMINI_API_KEY`) for the key, and `model` is ALWAYS
 * `DEFAULT_MODEL` in this branch (a `model` value from a row whose
 * `gemini_api_key` was empty is discarded, not reused) — matches PHP's own
 * unconditional `$this->model = self::DEFAULT_MODEL;` tail statement.
 *
 * `process.env.GEMINI_API_KEY` is read directly rather than through
 * `packages/config`'s env schema — this batch's allowed paths are
 * exclusively `api/inbox/actions/{analyze-symptom,analyze-drug,
 * analyze-prescription}/**`, so `packages/config` cannot be touched here;
 * flagged in this batch's final report as a follow-up for whichever batch
 * next touches `packages/config/src/env.ts` to add it as a typed optional
 * entry.
 */
export async function loadAiConfig(db: Kysely<TenantDB>, lineAccountId: number): Promise<AiConfig> {
  try {
    const result = await sql<{ gemini_api_key: string | null; model: string | null }>`
      SELECT gemini_api_key, model
      FROM ai_settings
      WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
      ORDER BY line_account_id DESC
      LIMIT 1
    `.execute(db);
    const row = result.rows[0];
    if (row && phpNotEmptyString(row.gemini_api_key)) {
      return { apiKey: row.gemini_api_key, model: row.model ?? DEFAULT_MODEL };
    }
  } catch {
    // ai_settings table might not exist — matches PHP's catch (PDOException $e) {}
  }

  const envKey = process.env.GEMINI_API_KEY;
  return { apiKey: phpNotEmptyString(envKey) ? envKey : null, model: DEFAULT_MODEL };
}

/** Port of `isConfigured()`. */
export async function isConfigured(db: Kysely<TenantDB>, lineAccountId: number): Promise<boolean> {
  const config = await loadAiConfig(db, lineAccountId);
  return phpNotEmptyString(config.apiKey);
}

// ─────────────────────────────────────────────────────────────────────────
// hashImage() (lines 1052-1058)
// ─────────────────────────────────────────────────────────────────────────

function hashImage(imageUrl: string): string {
  return createHash('sha256').update(imageUrl, 'utf8').digest('hex');
}

// ─────────────────────────────────────────────────────────────────────────
// extractJson() (lines 1017-1048) — 3-tier fallback
// ─────────────────────────────────────────────────────────────────────────

type JsonRecord = Record<string, unknown>;

function tryParseJsonObject(text: string): JsonRecord | null {
  try {
    const parsed: unknown = JSON.parse(text);
    if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
      return parsed as JsonRecord;
    }
    // PHP's `json_decode($text, true) !== null` check technically also
    // passes for a bare scalar/array JSON payload (its "return as-is"
    // behavior). Every caller here only ever reads named object keys
    // (`$json['condition']` etc. via `??`), so a non-object decode is
    // functionally indistinguishable from "nothing useful found" — treated
    // as such here instead of threading a non-object success case through
    // 3 different parse* functions for a case Gemini's prompted JSON-object
    // responses never realistically produce.
    return null;
  } catch {
    return null;
  }
}

/** Port of `extractJson()` — direct parse, then fenced ```json block, then greedy `{...}` match. */
function extractJson(text: string): JsonRecord | null {
  const direct = tryParseJsonObject(text);
  if (direct !== null) return direct;

  const codeBlockMatch = /```(?:json)?\s*\n?([\s\S]*?)\n?```/.exec(text);
  if (codeBlockMatch) {
    const inner = tryParseJsonObject((codeBlockMatch[1] ?? '').trim());
    if (inner !== null) return inner;
  }

  const objectMatch = /\{[\s\S]*\}/.exec(text);
  if (objectMatch) {
    const inner = tryParseJsonObject(objectMatch[0]);
    if (inner !== null) return inner;
  }

  return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Vision API orchestration — ties imageResolver.ts + geminiVisionClient.ts
// together, matching PHP's single callVisionAPI() method body (lines 694-777)
// ─────────────────────────────────────────────────────────────────────────

async function callVisionApi(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  apiKey: string,
  model: string,
  imageUrl: string,
  prompt: string
): Promise<GeminiVisionResult> {
  const imageData = await getImageData(db, lineAccountId, imageUrl);
  if (!imageData.success) {
    return { success: false, error: imageData.error ?? 'Failed to load image' };
  }
  return callGeminiVisionApi({ apiKey, model, base64: imageData.base64, mimeType: imageData.mimeType, prompt });
}

// ═══════════════════════════════════════════════════════════════════════
// Symptom analysis — analyzeSymptom() (lines 122-186)
// ═══════════════════════════════════════════════════════════════════════

interface ParsedSymptomAnalysis {
  condition: string | null;
  conditionEn: string | null;
  description: string;
  severity: string;
  possibleCauses: unknown[];
  recommendations: unknown[];
  warnings: string[];
  needsDoctor: boolean;
  doctorReason: string | null;
  rawResponse: string;
}

interface SymptomAnalysisFields extends ParsedSymptomAnalysis {
  urgency: boolean;
  urgencyReason: string | null;
  urgencyRecommendation: string | null;
}

export type SymptomAnalysisResult =
  | (SymptomAnalysisFields & { success: true; imageHash: string; cached?: true; cachedAt?: unknown })
  | { success: false; error: string; condition: null; severity: null; urgency: false; recommendations: never[] };

/** Port of `buildSymptomAnalysisPrompt()` (lines 191-213), verbatim Thai prompt text. */
function buildSymptomAnalysisPrompt(): string {
  return `คุณคือเภสัชกรผู้เชี่ยวชาญ กรุณาวิเคราะห์รูปภาพอาการนี้และตอบในรูปแบบ JSON ดังนี้:

{
    "condition": "ชื่ออาการ/โรคที่เป็นไปได้ (ภาษาไทย)",
    "conditionEn": "Condition name in English",
    "description": "คำอธิบายอาการที่เห็นในรูป",
    "severity": "mild/moderate/severe/urgent",
    "possibleCauses": ["สาเหตุที่เป็นไปได้ 1", "สาเหตุที่เป็นไปได้ 2"],
    "recommendations": [
        {"type": "medication", "name": "ชื่อยา", "usage": "วิธีใช้"},
        {"type": "care", "instruction": "คำแนะนำการดูแล"}
    ],
    "warnings": ["ข้อควรระวัง"],
    "needsDoctor": true/false,
    "doctorReason": "เหตุผลที่ควรพบแพทย์ (ถ้ามี)"
}

กรุณาวิเคราะห์อย่างระมัดระวังและให้คำแนะนำที่ปลอดภัย หากอาการรุนแรงหรือไม่แน่ใจ ให้แนะนำพบแพทย์`;
}

/** Port of `normalizeSeverity()` (lines 663-685) — verbatim mapping table. */
const SEVERITY_NORMALIZATION_MAP: Readonly<Record<string, string>> = {
  mild: SEVERITY_MILD,
  เล็กน้อย: SEVERITY_MILD,
  น้อย: SEVERITY_MILD,
  moderate: SEVERITY_MODERATE,
  ปานกลาง: SEVERITY_MODERATE,
  กลาง: SEVERITY_MODERATE,
  severe: SEVERITY_SEVERE,
  รุนแรง: SEVERITY_SEVERE,
  มาก: SEVERITY_SEVERE,
  urgent: SEVERITY_URGENT,
  ฉุกเฉิน: SEVERITY_URGENT,
  emergency: SEVERITY_URGENT,
};

function normalizeSeverity(severity: string): string {
  const key = severity.toLowerCase().trim();
  return SEVERITY_NORMALIZATION_MAP[key] ?? SEVERITY_MILD;
}

/** Port of `getSeverityLabel()` (lines 644-652). */
function getSeverityLabel(severity: string): string {
  const labels: Readonly<Record<string, string>> = {
    [SEVERITY_MILD]: 'เล็กน้อย',
    [SEVERITY_MODERATE]: 'ปานกลาง',
    [SEVERITY_SEVERE]: 'รุนแรง',
    [SEVERITY_URGENT]: 'ฉุกเฉิน',
  };
  return labels[severity] ?? 'ไม่ระบุ';
}

/** Port of `parseSymptomResponse()` (lines 218-258). */
function parseSymptomResponse(responseText: string): ParsedSymptomAnalysis {
  const json = extractJson(responseText);
  if (json) {
    return {
      condition: (json.condition as string | undefined) ?? 'ไม่สามารถระบุได้',
      conditionEn: (json.conditionEn as string | undefined) ?? null,
      description: (json.description as string | undefined) ?? '',
      severity: normalizeSeverity(String((json.severity as string | undefined) ?? 'mild')),
      possibleCauses: (json.possibleCauses as unknown[] | undefined) ?? [],
      recommendations: (json.recommendations as unknown[] | undefined) ?? [],
      warnings: (json.warnings as string[] | undefined) ?? [],
      needsDoctor: (json.needsDoctor as boolean | undefined) ?? false,
      doctorReason: (json.doctorReason as string | undefined) ?? null,
      rawResponse: responseText,
    };
  }

  return {
    condition: 'ไม่สามารถวิเคราะห์ได้',
    conditionEn: null,
    description: responseText,
    severity: SEVERITY_MILD,
    possibleCauses: [],
    recommendations: [],
    warnings: ['กรุณาปรึกษาเภสัชกรหรือแพทย์'],
    needsDoctor: true,
    doctorReason: 'ไม่สามารถวิเคราะห์รูปภาพได้ชัดเจน',
    rawResponse: responseText,
  };
}

export interface UrgencyCheckResult {
  isUrgent: boolean;
  reason: string | null;
  recommendation: string | null;
  severity: string;
  severityLabel: string;
}

/** Port of `checkUrgency()` (lines 578-638) — order of checks preserved exactly (first match per stage wins). */
function checkUrgency(analysisResult: ParsedSymptomAnalysis): UrgencyCheckResult {
  let isUrgent = false;
  let reason: string | null = null;
  let recommendation: string | null = null;

  const severity = analysisResult.severity || SEVERITY_MILD;
  if (severity === SEVERITY_SEVERE || severity === SEVERITY_URGENT) {
    isUrgent = true;
    reason = `อาการมีความรุนแรงระดับ ${getSeverityLabel(severity)}`;
    recommendation = 'แนะนำให้พบแพทย์โดยเร็วที่สุด';
  }

  if (!isUrgent && analysisResult.needsDoctor) {
    isUrgent = true;
    reason = analysisResult.doctorReason ?? 'อาการต้องการการตรวจจากแพทย์';
    recommendation = 'แนะนำให้พบแพทย์เพื่อตรวจวินิจฉัย';
  }

  if (!isUrgent) {
    const condition = (analysisResult.condition ?? '').toLowerCase();
    const conditionEn = (analysisResult.conditionEn ?? '').toLowerCase();
    const description = (analysisResult.description ?? '').toLowerCase();

    for (const urgentCondition of URGENT_CONDITIONS) {
      const urgentLower = urgentCondition.toLowerCase();
      if (condition.includes(urgentLower) || conditionEn.includes(urgentLower) || description.includes(urgentLower)) {
        isUrgent = true;
        reason = `ตรวจพบอาการที่อาจเป็นอันตราย: ${urgentCondition}`;
        recommendation = '⚠️ กรุณาไปพบแพทย์หรือห้องฉุกเฉินทันที';
        break;
      }
    }
  }

  if (!isUrgent && analysisResult.warnings.length > 0) {
    for (const warning of analysisResult.warnings) {
      const warningLower = warning.toLowerCase();
      if (
        warningLower.includes('ฉุกเฉิน') ||
        warningLower.includes('อันตราย') ||
        warningLower.includes('รุนแรง') ||
        warningLower.includes('emergency') ||
        warningLower.includes('urgent')
      ) {
        isUrgent = true;
        reason = warning;
        recommendation = 'แนะนำให้พบแพทย์โดยเร็ว';
        break;
      }
    }
  }

  return { isUrgent, reason, recommendation, severity, severityLabel: getSeverityLabel(severity) };
}

/**
 * Port of `getCachedSymptomAnalysis()` (lines 1064-1084). FIX-FORWARD (see
 * module doc): adds `success: true` + `imageHash` itself, which PHP's own
 * cache-hit path never does.
 */
async function getCachedSymptomAnalysis(db: Kysely<TenantDB>, imageHash: string): Promise<SymptomAnalysisResult | null> {
  try {
    const result = await sql<{ analysis_result: string | null; is_urgent: number | null; created_at: unknown }>`
      SELECT analysis_result, is_urgent, created_at
      FROM symptom_analysis_cache
      WHERE image_hash = ${imageHash} AND expires_at > NOW()
    `.execute(db);
    const row = result.rows[0];
    if (!row || !row.analysis_result) return null;

    let parsed: unknown;
    try {
      parsed = JSON.parse(row.analysis_result);
    } catch {
      return null;
    }
    if (parsed === null || typeof parsed !== 'object') return null;

    return {
      ...(parsed as SymptomAnalysisFields),
      success: true,
      imageHash,
      cached: true,
      cachedAt: row.created_at,
    };
  } catch {
    // symptom_analysis_cache table might not exist
    return null;
  }
}

/** Port of `cacheSymptomAnalysis()` (lines 1090-1112). Failures are swallowed, matching PHP's own `catch` + `error_log`. */
async function cacheSymptomAnalysis(
  db: Kysely<TenantDB>,
  imageHash: string,
  imageUrl: string,
  analysis: SymptomAnalysisFields
): Promise<void> {
  try {
    await sql`
      INSERT INTO symptom_analysis_cache (image_hash, image_url, analysis_result, is_urgent, expires_at)
      VALUES (
        ${imageHash}, ${imageUrl}, ${JSON.stringify(analysis)}, ${analysis.urgency ? 1 : 0},
        DATE_ADD(NOW(), INTERVAL ${CACHE_EXPIRY_HOURS} HOUR)
      )
      ON DUPLICATE KEY UPDATE
        analysis_result = VALUES(analysis_result),
        is_urgent = VALUES(is_urgent),
        expires_at = VALUES(expires_at)
    `.execute(db);
  } catch {
    // matches PHP's catch (PDOException $e) { error_log(...); return false; }
  }
}

/** Port of `analyzeSymptom()` (lines 122-186). */
export async function analyzeSymptom(db: Kysely<TenantDB>, lineAccountId: number, imageUrl: string): Promise<SymptomAnalysisResult> {
  const config = await loadAiConfig(db, lineAccountId);
  if (!phpNotEmptyString(config.apiKey)) {
    return { success: false, error: 'API key not configured', condition: null, severity: null, urgency: false, recommendations: [] };
  }

  const imageHash = hashImage(imageUrl);
  const cached = await getCachedSymptomAnalysis(db, imageHash);
  if (cached) return cached;

  const prompt = buildSymptomAnalysisPrompt();

  try {
    const response = await callVisionApi(db, lineAccountId, config.apiKey, config.model, imageUrl, prompt);
    if (!response.success) {
      return {
        success: false,
        error: response.error ?? 'API call failed',
        condition: null,
        severity: null,
        urgency: false,
        recommendations: [],
      };
    }

    const parsed = parseSymptomResponse(response.text);
    const urgencyCheck = checkUrgency(parsed);
    const analysis: SymptomAnalysisFields = {
      ...parsed,
      urgency: urgencyCheck.isUrgent,
      urgencyReason: urgencyCheck.reason,
      urgencyRecommendation: urgencyCheck.recommendation,
    };

    await cacheSymptomAnalysis(db, imageHash, imageUrl, analysis);

    return { ...analysis, success: true, imageHash };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message, condition: null, severity: null, urgency: false, recommendations: [] };
  }
}

// ═══════════════════════════════════════════════════════════════════════
// Drug identification — identifyDrug() (lines 264-336)
// ═══════════════════════════════════════════════════════════════════════

interface ParsedDrugInfo {
  drugName: string | null;
  genericName: string | null;
  manufacturer: string | null;
  dosageForm: string | null;
  strength: string | null;
  dosage: string | null;
  usage: string | null;
  indications: unknown[];
  contraindications: unknown[];
  sideEffects: unknown[];
  warnings: string[];
  drugCategory: string | null;
  isPrescriptionRequired: boolean;
  confidence: number;
  rawResponse: string;
}

interface MatchedProductFields {
  matchedProductId: number | null;
  matchedProductName: string | null;
  inStock: boolean;
  price: number | null;
}

export type DrugIdentificationResult =
  | (ParsedDrugInfo & MatchedProductFields & { success: true; imageHash: string; cached?: true; cachedAt?: unknown })
  | {
      success: false;
      error: string;
      drugName: null;
      genericName: null;
      dosage: null;
      usage: null;
      contraindications: never[];
    };

/** Port of `buildDrugIdentificationPrompt()` (lines 339-361), verbatim Thai prompt text. */
function buildDrugIdentificationPrompt(): string {
  return `คุณคือเภสัชกรผู้เชี่ยวชาญ กรุณาระบุยาจากรูปภาพนี้และตอบในรูปแบบ JSON ดังนี้:

{
    "drugName": "ชื่อการค้าของยา",
    "genericName": "ชื่อสามัญทางยา",
    "manufacturer": "บริษัทผู้ผลิต",
    "dosageForm": "รูปแบบยา (เม็ด/แคปซูล/น้ำ/ครีม)",
    "strength": "ความแรง (เช่น 500mg)",
    "usage": "วิธีใช้และขนาดยา",
    "indications": ["ข้อบ่งใช้ 1", "ข้อบ่งใช้ 2"],
    "contraindications": ["ข้อห้ามใช้ 1", "ข้อห้ามใช้ 2"],
    "sideEffects": ["ผลข้างเคียง 1", "ผลข้างเคียง 2"],
    "warnings": ["คำเตือน"],
    "drugCategory": "หมวดหมู่ยา (ยาสามัญประจำบ้าน/ยาอันตราย/ยาควบคุมพิเศษ)",
    "isPrescriptionRequired": true/false,
    "confidence": 0.0-1.0
}

หากไม่สามารถระบุยาได้ชัดเจน ให้ตั้ง confidence ต่ำและระบุเหตุผล`;
}

/** Port of `parseDrugResponse()` (lines 367-416). */
function parseDrugResponse(responseText: string): ParsedDrugInfo {
  const json = extractJson(responseText);
  if (json) {
    const strength = (json.strength as string | undefined) ?? null;
    return {
      drugName: (json.drugName as string | undefined) ?? null,
      genericName: (json.genericName as string | undefined) ?? null,
      manufacturer: (json.manufacturer as string | undefined) ?? null,
      dosageForm: (json.dosageForm as string | undefined) ?? null,
      strength,
      dosage: strength, // Alias for compatibility — PHP: 'dosage' => $json['strength'] ?? null
      usage: (json.usage as string | undefined) ?? null,
      indications: (json.indications as unknown[] | undefined) ?? [],
      contraindications: (json.contraindications as unknown[] | undefined) ?? [],
      sideEffects: (json.sideEffects as unknown[] | undefined) ?? [],
      warnings: (json.warnings as string[] | undefined) ?? [],
      drugCategory: (json.drugCategory as string | undefined) ?? null,
      isPrescriptionRequired: (json.isPrescriptionRequired as boolean | undefined) ?? false,
      confidence: toFloatOrZero(json.confidence ?? 0.5),
      rawResponse: responseText,
    };
  }

  return {
    drugName: null,
    genericName: null,
    manufacturer: null,
    dosageForm: null,
    strength: null,
    dosage: null,
    usage: null,
    indications: [],
    contraindications: [],
    sideEffects: [],
    warnings: ['ไม่สามารถระบุยาได้ กรุณาปรึกษาเภสัชกร'],
    drugCategory: null,
    isPrescriptionRequired: true,
    confidence: 0.0,
    rawResponse: responseText,
  };
}

interface MatchedProduct {
  id: number;
  name: string;
  price: number;
  inStock: boolean;
}

/**
 * Port of `matchDrugToProduct()` (lines 1228-1264) — see the module doc's
 * "CONFIRMED SCHEMA-DRIFT FIX" section above for the `stock_quantity` ->
 * `stock` fix-forward.
 */
async function matchDrugToProduct(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  drugName: string | null,
  genericName: string | null
): Promise<MatchedProduct | null> {
  if (phpEmpty(drugName) && phpEmpty(genericName)) {
    return null;
  }

  try {
    const result = await sql<{ id: number; name: string; price: unknown; stock: unknown }>`
      SELECT id, name, price, stock
      FROM business_items
      WHERE (name LIKE ${`%${drugName ?? ''}%`} OR name LIKE ${`%${genericName ?? ''}%`})
      AND is_active = 1
      AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
      LIMIT 1
    `.execute(db);
    const product = result.rows[0];
    if (product) {
      return {
        id: toIntOrZero(product.id),
        name: product.name,
        price: toFloatOrZero(product.price),
        inStock: toIntOrZero(product.stock) > 0,
      };
    }
  } catch {
    // Ignore — matches PHP's catch (PDOException $e) { // Ignore }
  }

  return null;
}

/** Port of `getCachedDrugRecognition()` (lines 1115-1135). FIX-FORWARD (see module doc) for the same missing-`success` cache-hit bug. */
async function getCachedDrugRecognition(db: Kysely<TenantDB>, imageHash: string): Promise<DrugIdentificationResult | null> {
  try {
    const result = await sql<{
      recognition_result: string | null;
      drug_name: string | null;
      generic_name: string | null;
      matched_product_id: number | null;
      created_at: unknown;
    }>`
      SELECT recognition_result, drug_name, generic_name, matched_product_id, created_at
      FROM drug_recognition_cache
      WHERE image_hash = ${imageHash} AND expires_at > NOW()
    `.execute(db);
    const row = result.rows[0];
    if (!row || !row.recognition_result) return null;

    let parsed: unknown;
    try {
      parsed = JSON.parse(row.recognition_result);
    } catch {
      return null;
    }
    if (parsed === null || typeof parsed !== 'object') return null;

    return {
      ...(parsed as ParsedDrugInfo & MatchedProductFields),
      success: true,
      imageHash,
      cached: true,
      cachedAt: row.created_at,
    };
  } catch {
    return null;
  }
}

/** Port of `cacheDrugRecognition()` (lines 1141-1165). */
async function cacheDrugRecognition(
  db: Kysely<TenantDB>,
  imageHash: string,
  imageUrl: string,
  drugInfo: ParsedDrugInfo & MatchedProductFields
): Promise<void> {
  try {
    await sql`
      INSERT INTO drug_recognition_cache
        (image_hash, image_url, drug_name, generic_name, matched_product_id, recognition_result, expires_at)
      VALUES (
        ${imageHash}, ${imageUrl}, ${drugInfo.drugName}, ${drugInfo.genericName}, ${drugInfo.matchedProductId},
        ${JSON.stringify(drugInfo)}, DATE_ADD(NOW(), INTERVAL ${CACHE_EXPIRY_HOURS} HOUR)
      )
      ON DUPLICATE KEY UPDATE
        drug_name = VALUES(drug_name),
        generic_name = VALUES(generic_name),
        matched_product_id = VALUES(matched_product_id),
        recognition_result = VALUES(recognition_result),
        expires_at = VALUES(expires_at)
    `.execute(db);
  } catch {
    // matches PHP's catch (PDOException $e) { error_log(...); return false; }
  }
}

/** Port of `identifyDrug()` (lines 264-336). */
export async function identifyDrug(db: Kysely<TenantDB>, lineAccountId: number, imageUrl: string): Promise<DrugIdentificationResult> {
  const config = await loadAiConfig(db, lineAccountId);
  if (!phpNotEmptyString(config.apiKey)) {
    return { success: false, error: 'API key not configured', drugName: null, genericName: null, dosage: null, usage: null, contraindications: [] };
  }

  const imageHash = hashImage(imageUrl);
  const cached = await getCachedDrugRecognition(db, imageHash);
  if (cached) return cached;

  const prompt = buildDrugIdentificationPrompt();

  try {
    const response = await callVisionApi(db, lineAccountId, config.apiKey, config.model, imageUrl, prompt);
    if (!response.success) {
      return {
        success: false,
        error: response.error ?? 'API call failed',
        drugName: null,
        genericName: null,
        dosage: null,
        usage: null,
        contraindications: [],
      };
    }

    const parsed = parseDrugResponse(response.text);
    const matched = await matchDrugToProduct(db, lineAccountId, parsed.drugName, parsed.genericName);
    const drugInfo: ParsedDrugInfo & MatchedProductFields = {
      ...parsed,
      matchedProductId: matched?.id ?? null,
      matchedProductName: matched?.name ?? null,
      inStock: matched?.inStock ?? false,
      price: matched?.price ?? null,
    };

    await cacheDrugRecognition(db, imageHash, imageUrl, drugInfo);

    return { ...drugInfo, success: true, imageHash };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message, drugName: null, genericName: null, dosage: null, usage: null, contraindications: [] };
  }
}

// ═══════════════════════════════════════════════════════════════════════
// Prescription OCR — ocrPrescription() (lines 424-475)
// ═══════════════════════════════════════════════════════════════════════

export interface PrescriptionDrugEntry {
  name?: string | null;
  genericName?: string | null;
  dosage?: string | null;
  frequency?: string | null;
  duration?: string | null;
  quantity?: string | null;
  instructions?: string | null;
}

export interface DrugInteractionFinding {
  drug1: string;
  drug2: string;
  severity: string | null;
  description: string | null;
  recommendation: string | null;
}

export interface AllergyWarning {
  drug: string | null;
  allergy: string;
  message: string;
}

interface ParsedPrescription {
  doctor: string | null;
  hospital: string | null;
  date: string | null;
  patientName: string | null;
  diagnosis: string | null;
  drugs: PrescriptionDrugEntry[];
  notes: string | null;
  confidence: number;
  rawResponse: string;
}

export type PrescriptionOcrResult =
  | (ParsedPrescription & {
      success: true;
      imageHash: string;
      interactions: DrugInteractionFinding[];
      allergyWarnings: AllergyWarning[];
    })
  | { success: false; error: string; drugs: never[]; doctor: null; date: null; hospital: null };

/** Port of `buildPrescriptionOCRPrompt()` (lines 468-490), verbatim Thai prompt text. */
function buildPrescriptionOCRPrompt(): string {
  return `คุณคือเภสัชกรผู้เชี่ยวชาญ กรุณาอ่านใบสั่งยานี้และแปลงเป็น JSON ดังนี้:

{
    "doctor": "ชื่อแพทย์ผู้สั่งยา",
    "hospital": "ชื่อโรงพยาบาล/คลินิก",
    "date": "วันที่ในใบสั่งยา (YYYY-MM-DD)",
    "patientName": "ชื่อผู้ป่วย (ถ้ามี)",
    "diagnosis": "การวินิจฉัย (ถ้ามี)",
    "drugs": [
        {
            "name": "ชื่อยา",
            "genericName": "ชื่อสามัญ (ถ้าทราบ)",
            "dosage": "ขนาดยา",
            "frequency": "ความถี่ในการรับประทาน",
            "duration": "ระยะเวลา",
            "quantity": "จำนวน",
            "instructions": "คำแนะนำเพิ่มเติม"
        }
    ],
    "notes": "หมายเหตุอื่นๆ",
    "confidence": 0.0-1.0
}

กรุณาอ่านให้ละเอียดและถูกต้อง หากอ่านไม่ชัดให้ระบุ [อ่านไม่ชัด] และตั้ง confidence ต่ำ`;
}

/** Port of `parsePrescriptionResponse()` (lines 496-528). */
function parsePrescriptionResponse(responseText: string): ParsedPrescription {
  const json = extractJson(responseText);
  if (json) {
    return {
      doctor: (json.doctor as string | undefined) ?? null,
      hospital: (json.hospital as string | undefined) ?? null,
      date: (json.date as string | undefined) ?? null,
      patientName: (json.patientName as string | undefined) ?? null,
      diagnosis: (json.diagnosis as string | undefined) ?? null,
      drugs: (json.drugs as PrescriptionDrugEntry[] | undefined) ?? [],
      notes: (json.notes as string | undefined) ?? null,
      confidence: toFloatOrZero(json.confidence ?? 0.5),
      rawResponse: responseText,
    };
  }

  return {
    doctor: null,
    hospital: null,
    date: null,
    patientName: null,
    diagnosis: null,
    drugs: [],
    notes: 'ไม่สามารถอ่านใบสั่งยาได้ กรุณาส่งรูปที่ชัดเจนกว่านี้',
    confidence: 0.0,
    rawResponse: responseText,
  };
}

/**
 * Port of `checkDrugInteractions()` (lines 1272-1330). Both queries (the
 * `current_medications` lookup and the O(n^2) pairwise `drug_interactions`
 * lookup) are individually try/caught, matching PHP's two separate
 * `try { ... } catch (PDOException $e) { ... }` blocks — a failure in the
 * pairwise loop stops iteration immediately (matching PHP's try wrapping
 * the *entire* nested loop) but returns whatever interactions were already
 * accumulated, not an empty array.
 */
async function checkDrugInteractions(
  db: Kysely<TenantDB>,
  drugs: PrescriptionDrugEntry[],
  userId: number | null
): Promise<DrugInteractionFinding[]> {
  const interactions: DrugInteractionFinding[] = [];
  let drugNames = drugs.map((d) => (d.name as string | null | undefined) ?? (d.genericName as string | null | undefined) ?? '');

  if (userId) {
    try {
      const result = await sql<{ current_medications: string | null }>`
        SELECT current_medications FROM users WHERE id = ${userId}
      `.execute(db);
      const user = result.rows[0];
      if (user && user.current_medications) {
        const currentMeds = user.current_medications.split(/[,\n]+/).map((s) => s.trim());
        drugNames = drugNames.concat(currentMeds);
      }
    } catch {
      // Ignore — matches PHP's catch (PDOException $e) { // Ignore }
    }
  }

  try {
    for (let i = 0; i < drugNames.length; i++) {
      const drug1 = drugNames[i] ?? '';
      for (let j = i + 1; j < drugNames.length; j++) {
        const drug2 = drugNames[j] ?? '';
        const result = await sql<{ severity: string | null; description: string | null; recommendation: string | null }>`
          SELECT severity, description, recommendation
          FROM drug_interactions
          WHERE (drug1_name LIKE ${`%${drug1}%`} AND drug2_name LIKE ${`%${drug2}%`})
          OR (drug1_name LIKE ${`%${drug2}%`} AND drug2_name LIKE ${`%${drug1}%`})
        `.execute(db);
        const interaction = result.rows[0];
        if (interaction) {
          interactions.push({
            drug1,
            drug2,
            severity: interaction.severity,
            description: interaction.description,
            recommendation: interaction.recommendation,
          });
        }
      }
    }
  } catch {
    // drug_interactions table might not exist — stop iterating, keep what's accumulated so far
  }

  return interactions;
}

/** Port of `checkPrescriptionAllergies()` (lines 1336-1386). */
async function checkPrescriptionAllergies(
  db: Kysely<TenantDB>,
  drugs: PrescriptionDrugEntry[],
  userId: number
): Promise<AllergyWarning[]> {
  const warnings: AllergyWarning[] = [];

  try {
    const result = await sql<{ drug_allergies: string | null }>`
      SELECT drug_allergies FROM users WHERE id = ${userId}
    `.execute(db);
    const user = result.rows[0];
    if (!user || !user.drug_allergies) return [];

    const allergies = user.drug_allergies
      .split(/[,\n]+/)
      .filter((s) => s !== '' && s !== '0')
      .map((s) => s.trim());

    for (const drug of drugs) {
      const drugName = ((drug.name as string | null | undefined) ?? '').toLowerCase();
      const genericName = ((drug.genericName as string | null | undefined) ?? '').toLowerCase();

      for (const allergy of allergies) {
        const allergyLower = allergy.toLowerCase();

        if (
          drugName.includes(allergyLower) ||
          genericName.includes(allergyLower) ||
          allergyLower.includes(drugName) ||
          allergyLower.includes(genericName)
        ) {
          warnings.push({
            drug: (drug.name as string | null | undefined) ?? (drug.genericName as string | null | undefined) ?? null,
            allergy,
            message: `⚠️ ลูกค้าแพ้ยา ${allergy} - ยา ${drug.name ?? ''} อาจไม่ปลอดภัย`,
          });
        }
      }
    }
  } catch {
    // Ignore — matches PHP's catch (PDOException $e) { // Ignore }
  }

  return warnings;
}

/** Port of `cachePrescriptionOCR()` (lines 1172-1194) — INSERT-only, no upsert (no `getCachedPrescriptionOCR()` read path exists in PHP either). */
async function cachePrescriptionOCR(
  db: Kysely<TenantDB>,
  imageHash: string,
  imageUrl: string,
  prescription: ParsedPrescription,
  userId: number | null
): Promise<void> {
  try {
    await sql`
      INSERT INTO prescription_ocr_results
        (user_id, image_hash, image_url, extracted_drugs, doctor_name, hospital_name, prescription_date, ocr_confidence)
      VALUES (
        ${userId ?? 0}, ${imageHash}, ${imageUrl}, ${JSON.stringify(prescription.drugs)},
        ${prescription.doctor}, ${prescription.hospital}, ${prescription.date}, ${prescription.confidence}
      )
    `.execute(db);
  } catch {
    // matches PHP's catch (PDOException $e) { error_log(...); return false; }
  }
}

/** Port of `ocrPrescription()` (lines 424-475). */
export async function ocrPrescription(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  imageUrl: string,
  userId: number | null
): Promise<PrescriptionOcrResult> {
  const config = await loadAiConfig(db, lineAccountId);
  if (!phpNotEmptyString(config.apiKey)) {
    return { success: false, error: 'API key not configured', drugs: [], doctor: null, date: null, hospital: null };
  }

  const prompt = buildPrescriptionOCRPrompt();

  try {
    const response = await callVisionApi(db, lineAccountId, config.apiKey, config.model, imageUrl, prompt);
    if (!response.success) {
      return { success: false, error: response.error ?? 'API call failed', drugs: [], doctor: null, date: null, hospital: null };
    }

    const parsed = parsePrescriptionResponse(response.text);

    const interactions = parsed.drugs.length > 1 ? await checkDrugInteractions(db, parsed.drugs, userId) : [];
    const allergyWarnings = userId ? await checkPrescriptionAllergies(db, parsed.drugs, userId) : [];

    const imageHash = hashImage(imageUrl);
    await cachePrescriptionOCR(db, imageHash, imageUrl, parsed, userId);

    return { ...parsed, success: true, imageHash, interactions, allergyWarnings };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: message, drugs: [], doctor: null, date: null, hospital: null };
  }
}
