import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getForSymptoms, type GetForSymptomsResult } from './getForSymptoms';

/**
 * recommendations.ts — port of api/inbox-v2.php's `case 'recommendations':
 * case 'get_recommendations': case 'drug_recommendations':` (lines
 * ~1191-1350), the 3-tier priority cascade, plus the own,
 * independent copies of `classes/ConsultationAnalyzerService.php`'s
 * `searchDrugsFromMessage()` (938-1107), `searchDrugsFromChatHistory()`
 * (1116-1332), and `extractSearchTerms()` (1839-1877).
 *
 * ```php
 * case 'recommendations':
 * case 'get_recommendations':
 * case 'drug_recommendations':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     $symptoms = $_GET['symptoms'] ?? '';
 *     $type = $_GET['type'] ?? '';
 *     $message = $_GET['message'] ?? '';
 *     $limit = (int) ($_GET['limit'] ?? 10);
 *     if (!$userId) { sendError('User ID is required'); }
 *
 *     $consultationAnalyzer = loadService('ConsultationAnalyzerService', $db, $lineAccountId);
 *
 *     // Priority 1: chat history
 *     if ($consultationAnalyzer && $type === 'context') {
 *         try {
 *             $matchedDrugs = $consultationAnalyzer->searchDrugsFromChatHistory($userId, $limit);
 *             if (!empty($matchedDrugs)) {
 *                 sendResponse(['success' => true, 'data' => ['recommendations' => $matchedDrugs, 'type' => 'chat_history', 'userId' => $userId, 'count' => count($matchedDrugs)]]);
 *                 break;
 *             }
 *         } catch (Throwable $e) { logInboxApiException($e, 'catch'); error_log(...); }
 *     }
 *
 *     // Priority 2: current message
 *     if (!empty($message) && $consultationAnalyzer) {
 *         try {
 *             $matchedDrugs = $consultationAnalyzer->searchDrugsFromMessage($message);
 *             $searchTerms = $consultationAnalyzer->extractSearchTerms($message);
 *             if (!empty($matchedDrugs)) {
 *                 sendResponse(['success' => true, 'data' => ['recommendations' => $matchedDrugs, 'type' => 'message_search', 'userId' => $userId, 'message' => $message, 'searchTerms' => $searchTerms, 'originalMessage' => $message]]);
 *                 break;
 *             }
 *         } catch (Throwable $e) { logInboxApiException($e, 'catch'); error_log(...); }
 *     }
 *
 *     // Priority 3: popular drugs (fires when type==='context' OR symptoms is empty — regardless of whether priorities 1/2 already ran)
 *     if ($type === 'context' || empty($symptoms)) {
 *         try {
 *             $sql = "SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price, bi.stock, bi.description, bi.image_url, ic.name as category
 *                     FROM business_items bi LEFT JOIN item_categories ic ON bi.category_id = ic.id
 *                     WHERE bi.is_active = 1 AND bi.stock > 0";
 *             if ($lineAccountId) { $sql .= " AND (bi.line_account_id = ? OR bi.line_account_id IS NULL)"; }
 *             $sql .= " ORDER BY bi.stock DESC, bi.name ASC LIMIT ?";
 *             $stmt = $db->prepare($sql);
 *             if ($lineAccountId) { $stmt->execute([$lineAccountId, $limit]); } else { $stmt->execute([$limit]); }
 *             $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *             $recommendations = [];
 *             foreach ($drugs as $drug) {
 *                 $recommendations[] = ['id' => (int)$drug['id'], 'drugId' => (int)$drug['id'], 'name' => $drug['name'], 'sku' => $drug['sku'],
 *                     'price' => (float)($drug['sale_price'] ?? $drug['price'] ?? 0), 'originalPrice' => (float)($drug['price'] ?? 0),
 *                     'stock' => (int)($drug['stock'] ?? 0), 'category' => $drug['category'] ?? 'ยาทั่วไป',
 *                     'description' => $drug['description'], 'imageUrl' => $drug['image_url']];
 *             }
 *             sendResponse(['success' => true, 'data' => ['recommendations' => $recommendations, 'type' => 'popular', 'userId' => $userId]]);
 *         } catch (PDOException $e) {
 *             logInboxApiException($e, 'catch');
 *             sendResponse(['success' => true, 'data' => ['recommendations' => [], 'type' => 'popular', 'userId' => $userId, 'error' => $e->getMessage()]]);
 *         }
 *         break;
 *     }
 *
 *     // Symptom-based (only when type !== 'context' AND symptoms is non-empty)
 *     if (is_string($symptoms)) {
 *         $symptomsArray = json_decode($symptoms, true);
 *         if (!is_array($symptomsArray)) { $symptomsArray = array_map('trim', explode(',', $symptoms)); }
 *     } else { $symptomsArray = $symptoms; }
 *     $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);
 *     if (!$recommendEngine) { sendError('Recommendation engine service not available', 503); }
 *     $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
 *     if ($healthEngine) { $recommendEngine->setHealthEngine($healthEngine); }
 *     $result = $recommendEngine->getForSymptoms($symptomsArray, $userId, $limit);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * `$consultationAnalyzer` / `$recommendEngine` / `$healthEngine` are all
 * treated as ALWAYS successfully loaded in production (`loadService()`'s
 * `file_exists()`/`class_exists()` probe always succeeds for these
 * committed class files) — same "static import, no runtime probe"
 * simplification precedent used throughout this batch (and Phase 4 batch
 * 4a). No 503 branch is fabricated; Priority 1/2's own
 * `$consultationAnalyzer &&` guards collapse to just the `type`/`message`
 * conditions.
 *
 * DELIBERATE DUPLICATION — `searchDrugsFromMessage()`/`searchDrugsFromChatHistory()`/
 * `extractSearchTerms()` below are THIS action's own, independent port —
 * NOT shared with the sibling `consultationWidgets` builder stream's
 * `context-widgets` action (which independently ports its own copy of the
 * same PHP methods for ITS OWN different call site,
 * `ConsultationAnalyzerService::checkForDrugNames()`). Per this batch's
 * scope boundary, these two directories are fully disjoint and neither
 * imports from the other.
 */

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v` on a DB column value — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** PHP `round($x, 1)`. */
function round1(value: number): number {
  return Math.round(value * 10) / 10;
}

/** PHP `empty($v)` for a query-string value (always a string here): falsy for `''` and the exact string `'0'`. */
function isPhpEmptyString(value: string): boolean {
  return value === '' || value === '0';
}

/** PHP `preg_quote($str, '/')` — escapes regex metacharacters for the `/` delimiter. */
function pregQuote(str: string): string {
  return str.replace(/[.\\+*?[\]^$(){}=!<>|:#/-]/g, '\\$&');
}

/** `preg_match('/\b' . preg_quote($word, '/') . '/ui', $text)` — word-boundary, case-insensitive. */
function wordBoundaryMatches(text: string, word: string): boolean {
  const re = new RegExp(`\\b${pregQuote(word)}`, 'iu');
  return re.test(text);
}

// ─────────────────────────────────────────────────────────────────────────
// searchDrugsFromMessage()
// ─────────────────────────────────────────────────────────────────────────

export interface MessageSearchDrug {
  id: number;
  drugId: number;
  name: string;
  nameEn: string;
  genericName: string;
  sku: string | null;
  price: number;
  originalPrice: number;
  costPrice: number;
  margin: number | null;
  stock: number;
  unit: string;
  manufacturer: string;
  category: string;
  dosage: string;
  imageUrl: string | null;
  matchScore: number;
}

interface MessageSearchProductRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
  image_url: string | null;
  generic_name: string | null;
  name_en: string | null;
  active_ingredient: string | null;
  manufacturer: string | null;
  unit: string | null;
}

const AVAILABILITY_QUERY_RE = /มี\s*(.+)/u;
const MESSAGE_SUFFIX_RE = /(มั้ย|ไหม|บ้าง|ครับ|ค่ะ|นะ|จ้า|หรือเปล่า)\s*$/u;
const MAIN_NAME_SPLIT_RE = /[\s\-/([]+/u;
const WORD_SPLIT_RE = /[\s\-/()[\]]+/u;

export async function searchDrugsFromMessage(db: Kysely<TenantDB>, message: string, lineAccountId: number | null): Promise<MessageSearchDrug[]> {
  const drugs: MessageSearchDrug[] = [];
  const messageLower = message.toLowerCase();

  const availMatch = message.match(AVAILABILITY_QUERY_RE);
  const searchTerm = availMatch ? (availMatch[1] as string).trim() : message;
  let searchTermLower = searchTerm.toLowerCase();
  searchTermLower = searchTermLower.replace(MESSAGE_SUFFIX_RE, '').trim();

  try {
    const lineAccountClause = lineAccountId
      ? sql` AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)`
      : sql``;

    const result = await sql<MessageSearchProductRow>`
      SELECT id, name, sku, price, sale_price, stock, description, image_url,
             generic_name, name_en, active_ingredient, manufacturer, unit
      FROM business_items
      WHERE is_active = 1 AND stock > 0${lineAccountClause}
      ORDER BY stock DESC
      LIMIT 500
    `.execute(db);
    const allProducts = result.rows;

    const matchedProducts: MessageSearchProductRow[] = [];
    const matchScores = new Map<number, number>();

    for (const product of allProducts) {
      let score = 0;
      const productNameLower = product.name.toLowerCase();
      const productSku = (product.sku ?? '').toLowerCase();
      const genericName = (product.generic_name ?? '').toLowerCase();
      const nameEn = (product.name_en ?? '').toLowerCase();
      const activeIngredient = (product.active_ingredient ?? '').toLowerCase();

      if (searchTermLower && searchTermLower.length >= 2) {
        if (productNameLower.includes(searchTermLower)) score += 100;
        if (nameEn && nameEn.includes(searchTermLower)) score += 100;
        if (genericName && genericName.includes(searchTermLower)) score += 80;
        if (productSku && productSku.includes(searchTermLower)) score += 90;
        if (activeIngredient && activeIngredient.includes(searchTermLower)) score += 70;
      }

      const mainName = productNameLower.split(MAIN_NAME_SPLIT_RE)[0] ?? '';
      if (mainName.length >= 3 && messageLower.includes(mainName)) score += 50;

      if (nameEn) {
        for (const wordRaw of nameEn.split(WORD_SPLIT_RE)) {
          const word = wordRaw.trim();
          if (word.length >= 3 && messageLower.includes(word)) {
            score += 40;
            break;
          }
        }
      }

      if (genericName) {
        for (const wordRaw of genericName.split(WORD_SPLIT_RE)) {
          const word = wordRaw.trim();
          if (word.length >= 3 && messageLower.includes(word)) {
            score += 30;
            break;
          }
        }
      }

      for (const wordRaw of productNameLower.split(WORD_SPLIT_RE)) {
        const word = wordRaw.trim();
        if (word.length >= 4 && messageLower.includes(word)) {
          score += 20;
          break;
        }
      }

      if (score > 0) {
        matchedProducts.push(product);
        matchScores.set(product.id, score);
      }
    }

    matchedProducts.sort((a, b) => (matchScores.get(b.id) ?? 0) - (matchScores.get(a.id) ?? 0));

    const seenIds = new Set<number>();
    for (const product of matchedProducts) {
      if (seenIds.has(product.id)) continue;
      seenIds.add(product.id);

      const price = toFloatOrZero(product.sale_price ?? product.price ?? 0);
      const cost = price * 0.7;
      const margin = price > 0 ? round1(((price - cost) / price) * 100) : null;

      drugs.push({
        id: product.id,
        drugId: product.id,
        name: product.name,
        nameEn: product.name_en ?? '',
        genericName: product.generic_name ?? '',
        sku: product.sku,
        price,
        originalPrice: toFloatOrZero(product.price ?? 0),
        costPrice: cost,
        margin,
        stock: toIntOrZero(product.stock ?? 0),
        unit: product.unit ?? '',
        manufacturer: product.manufacturer ?? '',
        category: 'ยาทั่วไป',
        dosage: product.description ?? '',
        imageUrl: product.image_url,
        matchScore: matchScores.get(product.id) ?? 0,
      });

      if (drugs.length >= 5) break;
    }
  } catch {
    // ConsultationAnalyzer searchDrugsFromMessage error — swallowed, matches PHP's own catch.
  }

  return drugs;
}

// ─────────────────────────────────────────────────────────────────────────
// searchDrugsFromChatHistory()
// ─────────────────────────────────────────────────────────────────────────

export interface ChatHistorySearchDrug {
  id: number;
  drugId: number;
  name: string;
  sku: string | null;
  price: number;
  originalPrice: number;
  costPrice: number;
  margin: number | null;
  stock: number;
  category: string;
  dosage: string;
  imageUrl: string | null;
  matchScore: number;
  matchType: 'exact' | 'recent' | 'partial';
  matchReasons: string[];
}

interface ChatMessageRow {
  content: string | null;
}

interface ChatProductRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
  image_url: string | null;
  generic_name: string | null;
  active_ingredient: string | null;
}

const IGNORE_WORDS = new Set<string>([
  'ครับ', 'ค่ะ', 'นะ', 'จ้า', 'ได้', 'ไหม', 'มี', 'ไม่', 'อยาก', 'ต้องการ',
  'สั่ง', 'ซื้อ', 'เอา', 'ขอ', 'หน่อย', 'ด้วย', 'กับ', 'และ', 'หรือ',
  'the', 'and', 'for', 'with', 'this', 'that', 'have', 'from',
]);

const CHAT_NAME_WORD_SPLIT_RE = /[\s\-/()[\],.0-9]+/u;
const INGREDIENT_SPLIT_RE = /[\s,/+]+/u;

export async function searchDrugsFromChatHistory(
  db: Kysely<TenantDB>,
  userId: number,
  lineAccountId: number | null,
  limit = 10
): Promise<ChatHistorySearchDrug[]> {
  const drugs: ChatHistorySearchDrug[] = [];

  try {
    const messagesResult = await sql<ChatMessageRow>`
      SELECT content, message_type, created_at
      FROM messages
      WHERE user_id = ${userId}
      AND direction = 'incoming'
      AND message_type = 'text'
      ORDER BY created_at DESC
      LIMIT 100
    `.execute(db);
    const messages = messagesResult.rows;

    if (messages.length === 0) return [];

    let allText = '';
    let recentText = '';
    messages.forEach((msg, idx) => {
      const content = msg.content ?? '';
      allText += ` ${content}`;
      if (idx < 10) recentText += ` ${content}`;
    });
    const allTextLower = allText.toLowerCase();
    const recentTextLower = recentText.toLowerCase();

    const lineAccountClause = lineAccountId
      ? sql` AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)`
      : sql``;

    const productsResult = await sql<ChatProductRow>`
      SELECT id, name, sku, price, sale_price, stock, description, image_url, generic_name, active_ingredient
      FROM business_items
      WHERE is_active = 1${lineAccountClause}
      ORDER BY stock DESC
      LIMIT 2000
    `.execute(db);
    const allProducts = productsResult.rows;

    const matchedProducts: ChatProductRow[] = [];
    const matchScores = new Map<number, number>();
    const matchReasonsMap = new Map<number, string[]>();

    for (const product of allProducts) {
      let score = 0;
      const reasons: string[] = [];
      const productNameLower = product.name.toLowerCase();
      const productSku = (product.sku ?? '').toLowerCase();
      const genericName = (product.generic_name ?? '').toLowerCase();
      const activeIngredient = (product.active_ingredient ?? '').toLowerCase();

      if (productNameLower.length >= 4 && allTextLower.includes(productNameLower)) {
        score += 200;
        reasons.push('exact_name');
        if (recentTextLower.includes(productNameLower)) {
          score += 100;
          reasons.push('recent');
        }
      }

      let significantMatches = 0;
      for (const wordRaw of productNameLower.split(CHAT_NAME_WORD_SPLIT_RE)) {
        const word = wordRaw.trim();
        if (word.length < 4 || IGNORE_WORDS.has(word)) continue;

        const boundaryMatch = wordBoundaryMatches(allText, word);
        if (boundaryMatch) {
          const wordScore = word.length * 5;
          score += wordScore;
          significantMatches++;
          if (wordBoundaryMatches(recentText, word)) {
            score += wordScore * 2;
            reasons.push(`word_recent:${word}`);
          } else {
            reasons.push(`word:${word}`);
          }
        }

        if (allTextLower.includes(word) && !boundaryMatch) {
          score += word.length * 2;
          reasons.push(`fuzzy:${word}`);
        }
      }

      if (significantMatches >= 2) {
        score += significantMatches * 20;
        reasons.push('multi_match');
      }

      if (productSku && productSku.length >= 3 && allTextLower.includes(productSku)) {
        score += 80;
        reasons.push('sku');
      }

      if (genericName && genericName.length >= 4 && allTextLower.includes(genericName)) {
        score += 60;
        reasons.push('generic');
      }

      if (activeIngredient && activeIngredient.length >= 4) {
        for (const ingRaw of activeIngredient.split(INGREDIENT_SPLIT_RE)) {
          const ing = ingRaw.trim();
          if (ing.length >= 4 && allTextLower.includes(ing)) {
            score += 40;
            reasons.push(`ingredient:${ing}`);
          }
        }
      }

      if (score >= 20) {
        matchedProducts.push(product);
        matchScores.set(product.id, score);
        matchReasonsMap.set(product.id, reasons);
      }
    }

    matchedProducts.sort((a, b) => (matchScores.get(b.id) ?? 0) - (matchScores.get(a.id) ?? 0));

    const seenIds = new Set<number>();
    for (const product of matchedProducts) {
      if (seenIds.has(product.id)) continue;
      seenIds.add(product.id);

      const price = toFloatOrZero(product.sale_price ?? product.price ?? 0);
      const cost = price * 0.7;
      const margin = price > 0 ? round1(((price - cost) / price) * 100) : null;
      const score = matchScores.get(product.id) ?? 0;
      const reasons = matchReasonsMap.get(product.id) ?? [];

      let matchType: 'exact' | 'recent' | 'partial' = 'partial';
      if (reasons.includes('exact_name')) {
        matchType = 'exact';
      } else if (reasons.includes('recent') || reasons.some((r) => r.includes('recent'))) {
        matchType = 'recent';
      }

      drugs.push({
        id: product.id,
        drugId: product.id,
        name: product.name,
        sku: product.sku,
        price,
        originalPrice: toFloatOrZero(product.price ?? 0),
        costPrice: cost,
        margin,
        stock: toIntOrZero(product.stock ?? 0),
        category: 'ยาทั่วไป',
        dosage: product.description ?? '',
        imageUrl: product.image_url,
        matchScore: score,
        matchType,
        matchReasons: reasons.slice(0, 3),
      });

      if (drugs.length >= limit) break;
    }
  } catch {
    // ConsultationAnalyzerService searchDrugsFromChatHistory error — swallowed.
  }

  return drugs;
}

// ─────────────────────────────────────────────────────────────────────────
// extractSearchTerms()
// ─────────────────────────────────────────────────────────────────────────

const EXTRACT_PARTICLE_RE =
  /(มั้ย|ไหม|บ้าง|ครับ|ค่ะ|นะ|จ้า|หรือเปล่า|หน่อย|ด้วย|ขอ|เอา|ต้องการ|อยาก|อยากได้|สั่ง|ซื้อ)\s*/gu;
const QUANTITY_RE = /(\d+)\s*(กล่อง|ขวด|แผง|ซอง|ชิ้น|หลอด|ตัว|ถุง|แพ็ค)/gu;
const EXTRACT_SPLIT_RE = /[\s,/\-+]+/u;
const CONTAINS_LETTER_RE = /[ก-๙a-zA-Z]/u;
const IS_NUMERIC_RE = /^[+-]?\d+(\.\d+)?$/;

const EXTRACT_COMMON_WORDS = new Set<string>([
  'และ', 'หรือ', 'กับ', 'ที่', 'ของ', 'ให้', 'ได้', 'จะ', 'แล้ว', 'ก็', 'คือ', 'เป็น',
  'มา', 'ไป', 'อยู่', 'ยัง', 'แต่', 'ถ้า', 'เมื่อ', 'ตอน', 'วัน', 'นี้', 'นั้น', 'นั่น', 'โน่น',
]);

export function extractSearchTerms(message: string): string[] {
  const terms: string[] = [];
  const messageLower = message.trim().toLowerCase();
  const cleanMessage = messageLower.replace(EXTRACT_PARTICLE_RE, '');

  const availMatch = cleanMessage.match(AVAILABILITY_QUERY_RE);
  if (availMatch) {
    terms.push((availMatch[1] as string).trim());
  }

  const quantityMatches = cleanMessage.matchAll(QUANTITY_RE);
  for (const m of quantityMatches) {
    terms.push(m[0]);
  }

  for (const partRaw of cleanMessage.split(EXTRACT_SPLIT_RE)) {
    const part = partRaw.trim();
    if (part.length >= 3 && !EXTRACT_COMMON_WORDS.has(part) && !IS_NUMERIC_RE.test(part)) {
      if (CONTAINS_LETTER_RE.test(part)) {
        terms.push(part);
      }
    }
  }

  return [...new Set(terms)];
}

// ─────────────────────────────────────────────────────────────────────────
// Priority 3: popular drugs (INLINE SQL in the PHP case block itself, NOT a
// service method — its `imageUrl` key is what distinguishes it from the
// superficially similar `ConsultationAnalyzerService::getPopularDrugs()`,
// which has no such key and is not used by this action).
// ─────────────────────────────────────────────────────────────────────────

export interface PopularDrug {
  id: number;
  drugId: number;
  name: string;
  sku: string | null;
  price: number;
  originalPrice: number;
  stock: number;
  category: string;
  description: string | null;
  imageUrl: string | null;
}

interface PopularDrugRow {
  id: number;
  name: string;
  sku: string | null;
  price: unknown;
  sale_price: unknown;
  stock: unknown;
  description: string | null;
  image_url: string | null;
  category: string | null;
}

export type PopularDrugsResult = { recommendations: PopularDrug[]; type: 'popular'; userId: number; error?: string };

async function getPopularDrugs(db: Kysely<TenantDB>, userId: number, lineAccountId: number | null, limit: number): Promise<PopularDrugsResult> {
  try {
    const lineAccountClause = lineAccountId
      ? sql` AND (bi.line_account_id = ${lineAccountId} OR bi.line_account_id IS NULL)`
      : sql``;

    const result = await sql<PopularDrugRow>`
      SELECT bi.id, bi.name, bi.sku, bi.price, bi.sale_price,
             bi.stock, bi.description, bi.image_url,
             ic.name as category
      FROM business_items bi
      LEFT JOIN item_categories ic ON bi.category_id = ic.id
      WHERE bi.is_active = 1
      AND bi.stock > 0${lineAccountClause}
      ORDER BY bi.stock DESC, bi.name ASC LIMIT ${limit}
    `.execute(db);

    const recommendations: PopularDrug[] = result.rows.map((drug) => ({
      id: drug.id,
      drugId: drug.id,
      name: drug.name,
      sku: drug.sku,
      price: toFloatOrZero(drug.sale_price ?? drug.price ?? 0),
      originalPrice: toFloatOrZero(drug.price ?? 0),
      stock: toIntOrZero(drug.stock ?? 0),
      category: drug.category ?? 'ยาทั่วไป',
      description: drug.description,
      imageUrl: drug.image_url,
    }));

    return { recommendations, type: 'popular', userId };
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { recommendations: [], type: 'popular', userId, error: message };
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Orchestration — the full priority cascade
// ─────────────────────────────────────────────────────────────────────────

export type RecommendationsData =
  | { recommendations: ChatHistorySearchDrug[]; type: 'chat_history'; userId: number; count: number }
  | { recommendations: MessageSearchDrug[]; type: 'message_search'; userId: number; message: string; searchTerms: string[]; originalMessage: string }
  | PopularDrugsResult
  | GetForSymptomsResult;

/** `is_string($symptoms) ? (json_decode($symptoms, true) is_array ? that : explode-and-trim) : $symptoms` (symptoms is always a query-string value here, hence always a string; kept general per the literal PHP conditional shape). */
function parseSymptomsParam(symptoms: string): string[] {
  try {
    const decoded: unknown = JSON.parse(symptoms);
    if (Array.isArray(decoded)) {
      return decoded.map((v) => String(v));
    }
  } catch {
    // json_decode() returning non-array (incl. null on parse failure) — fall through.
  }
  return symptoms.split(',').map((s) => s.trim());
}

export interface RecommendationsParams {
  userId: number;
  symptoms: string;
  type: string;
  message: string;
  limit: number;
  lineAccountId: number | null;
}

export async function getRecommendationsData(db: Kysely<TenantDB>, params: RecommendationsParams): Promise<RecommendationsData> {
  const { userId, symptoms, type, message, limit, lineAccountId } = params;

  // Priority 1: chat history (only when type === 'context').
  if (type === 'context') {
    try {
      const matchedDrugs = await searchDrugsFromChatHistory(db, userId, lineAccountId, limit);
      if (matchedDrugs.length > 0) {
        return { recommendations: matchedDrugs, type: 'chat_history', userId, count: matchedDrugs.length };
      }
    } catch {
      // Chat history search error — logged in PHP, swallowed here; cascade continues.
    }
  }

  // Priority 2: current message.
  if (!isPhpEmptyString(message)) {
    try {
      const matchedDrugs = await searchDrugsFromMessage(db, message, lineAccountId);
      const searchTerms = extractSearchTerms(message);
      if (matchedDrugs.length > 0) {
        return { recommendations: matchedDrugs, type: 'message_search', userId, message, searchTerms, originalMessage: message };
      }
    } catch {
      // Message search error — logged in PHP, swallowed here; cascade continues.
    }
  }

  // Priority 3: popular drugs — fires whenever type==='context' OR symptoms is empty,
  // REGARDLESS of whether Priority 1/2 already ran and simply found nothing.
  if (type === 'context' || isPhpEmptyString(symptoms)) {
    return getPopularDrugs(db, userId, lineAccountId, limit);
  }

  // Symptom-based (only when type !== 'context' AND symptoms is non-empty).
  const symptomsArray = parseSymptomsParam(symptoms);
  return getForSymptoms(db, symptomsArray, userId, lineAccountId, limit);
}
