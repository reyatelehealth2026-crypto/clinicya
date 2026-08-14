import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * imageResolver.ts — THE ONLY IMAGE-BYTES NETWORK SEAM in this builder's
 * scope (used by all 3 routes via `imageAnalyzer.ts`'s `callVisionApi()`
 * orchestrator; independently mockable from `geminiVisionClient.ts` so a
 * test can prove the image-download step alone never touches the network).
 * Literal port of `classes/PharmacyImageAnalyzerService.php::getImageData()`
 * (lines 784-900) and its two network helpers, `getLineImageData()`
 * (907-946) / `getLineAccessToken()` (951-970), plus `detectMimeType()`
 * (978-1014).
 *
 * ```php
 * private function getImageData(string $imageUrl): array
 * {
 *     // 1) data: URLs — pure regex parse, zero network.
 *     if (strpos($imageUrl, 'data:image/') === 0) {
 *         if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $imageUrl, $matches)) {
 *             return ['success' => true, 'base64' => $matches[2], 'mimeType' => 'image/' . $matches[1]];
 *         }
 *         return ['success' => false, 'error' => 'รูปแบบ data URL ไม่ถูกต้อง'];
 *     }
 *
 *     // 2) LINE content URLs — authenticated fetch via getLineImageData().
 *     if (strpos($imageUrl, 'api-data.line.me') !== false) {
 *         return $this->getLineImageData($imageUrl);
 *     }
 *
 *     // 3) "local file path" branch (strpos($imageUrl, 'http') !== 0, reads
 *     //    $_SERVER['DOCUMENT_ROOT'] . $imageUrl from local disk) —
 *     //    DELIBERATELY NOT PORTED, see note below.
 *
 *     // 4) "current-host direct file read" branch (strpos($imageUrl,
 *     //    $_SERVER['HTTP_HOST']) !== false, also reads from local disk via
 *     //    DOCUMENT_ROOT) — DELIBERATELY NOT PORTED, see note below.
 *
 *     // 5) generic http(s) URL — unauthenticated GET, 15s timeout.
 *     $ch = curl_init($imageUrl);
 *     curl_setopt_array($ch, [
 *         CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
 *         CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
 *         CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PharmacyImageAnalyzer/1.0)',
 *     ]);
 *     $imageContent = curl_exec($ch);
 *     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 *     $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
 *     $curlError = curl_error($ch);
 *     curl_close($ch);
 *
 *     if ($curlError) {
 *         return ['success' => false, 'error' => 'ไม่สามารถเชื่อมต่อเพื่อดาวน์โหลดรูปภาพ: ' . $curlError];
 *     }
 *     if ($httpCode === 404) {
 *         return ['success' => false, 'error' => 'ไม่พบไฟล์รูปภาพ (404): ' . basename($imageUrl)];
 *     }
 *     if ($httpCode !== 200 || empty($imageContent)) {
 *         return ['success' => false, 'error' => 'ดาวน์โหลดรูปภาพไม่สำเร็จ (HTTP ' . $httpCode . ')'];
 *     }
 *
 *     $mimeType = $this->detectMimeType($imageContent, $contentType);
 *     return ['success' => true, 'base64' => base64_encode($imageContent), 'mimeType' => $mimeType];
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * DELIBERATE NON-PORT — "local file path" and "current-host" branches
 * ═══════════════════════════════════════════════════════════════════════
 * Both PHP branches assume the PHP process and the image file share one
 * physical filesystem/host (`$_SERVER['DOCUMENT_ROOT'] . $imageUrl`) — that
 * assumption has no equivalent in this app's deployment topology (the
 * Next.js admin app runs in a separate container from any PHP host, with
 * no shared filesystem). Per this batch's brief, any `imageUrl` that would
 * have hit either of those two branches in PHP simply falls through to
 * branch 5 (the generic HTTP(S) fetch) below instead — the closest
 * behavioral equivalent for a URL on this app's own host, and in practice
 * unreachable anyway: `route.ts`'s own URL-format validation (mirroring
 * PHP's `filter_var($imageUrl, FILTER_VALIDATE_URL)` gate at the API layer,
 * a check the old PHP `getImageData()` itself never re-applies) already
 * requires an absolute, parseable URL before `getImageData()` is ever
 * called, so the "relative path, not `http`-prefixed" case PHP's branch 3
 * exists for cannot occur here.
 */

const LINE_CONTENT_HOST_MARKER = 'api-data.line.me';
const GENERIC_FETCH_TIMEOUT_MS = 15_000;
const LINE_FETCH_TIMEOUT_MS = 15_000;

export type ImageDataResult =
  | { success: true; base64: string; mimeType: string }
  | { success: false; error: string };

/** `basename($path)` — PHP treats the whole string as a path and returns everything after the last `/`, query string included (it never parses the URL first). */
function phpBasename(pathOrUrl: string): string {
  const parts = pathOrUrl.split('/');
  return parts[parts.length - 1] ?? '';
}

/**
 * Branch 1 — `data:image/<ext>;base64,<data>` URLs. Pure regex parse, zero
 * network. Malformed `data:image/...` URLs (prefix matches but the full
 * pattern doesn't) fail with the Thai `รูปแบบ data URL ไม่ถูกต้อง` message,
 * exactly like PHP.
 */
function parseDataUrl(imageUrl: string): ImageDataResult {
  const match = /^data:image\/(\w+);base64,(.+)$/.exec(imageUrl);
  if (match) {
    const [, extension, base64Data] = match;
    return { success: true, base64: base64Data ?? '', mimeType: `image/${extension ?? ''}` };
  }
  return { success: false, error: 'รูปแบบ data URL ไม่ถูกต้อง' };
}

/**
 * Port of `PharmacyImageAnalyzerService::detectMimeType()` (lines 978-1014)
 * — content-type-header sniff first, then magic-byte sniff for
 * jpeg/png/gif/webp, default `image/jpeg`. Uses `Buffer` comparison helpers
 * instead of raw byte indexing so this stays correct under
 * `noUncheckedIndexedAccess`.
 */
export function detectMimeType(content: Buffer, contentType?: string | null): string {
  if (contentType) {
    if (contentType.includes('jpeg') || contentType.includes('jpg')) return 'image/jpeg';
    if (contentType.includes('png')) return 'image/png';
    if (contentType.includes('gif')) return 'image/gif';
    if (contentType.includes('webp')) return 'image/webp';
  }

  const header = content.subarray(0, 8);

  if (header.subarray(0, 3).equals(Buffer.from([0xff, 0xd8, 0xff]))) {
    return 'image/jpeg';
  }
  if (header.equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) {
    return 'image/png';
  }
  const first6 = content.subarray(0, 6).toString('latin1');
  if (first6 === 'GIF87a' || first6 === 'GIF89a') {
    return 'image/gif';
  }
  if (content.subarray(0, 4).toString('latin1') === 'RIFF' && content.subarray(8, 12).toString('latin1') === 'WEBP') {
    return 'image/webp';
  }

  return 'image/jpeg';
}

/**
 * Port of `getLineAccessToken()` (lines 951-970): `SELECT
 * channel_access_token FROM line_accounts WHERE id = ? OR id IS NULL ORDER
 * BY id DESC LIMIT 1`, bound with the tenant's `lineAccountId`. `null` on a
 * missing row or a thrown DB error (PHP's own `catch (PDOException $e) {
 * return null; }`).
 */
async function getLineAccessToken(db: Kysely<TenantDB>, lineAccountId: number): Promise<string | null> {
  try {
    const result = await sql<{ channel_access_token: string | null }>`
      SELECT channel_access_token
      FROM line_accounts
      WHERE id = ${lineAccountId} OR id IS NULL
      ORDER BY id DESC
      LIMIT 1
    `.execute(db);
    return result.rows[0]?.channel_access_token ?? null;
  } catch {
    return null;
  }
}

/**
 * Branch 2 — LINE content URLs. Port of `getLineImageData()`
 * (lines 907-946): resolves the channel access token, then GETs the LINE
 * URL with `Authorization: Bearer <token>`. Missing token ->
 * `'LINE access token not configured'`; non-200/empty body -> `'Failed to
 * download LINE image'` (English-only in PHP, deliberately NOT the Thai
 * messages branch 5 below uses — this is a distinct error path).
 */
async function getLineImageData(db: Kysely<TenantDB>, lineAccountId: number, imageUrl: string): Promise<ImageDataResult> {
  const accessToken = await getLineAccessToken(db, lineAccountId);
  if (!accessToken) {
    return { success: false, error: 'LINE access token not configured' };
  }

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), LINE_FETCH_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(imageUrl, {
      headers: { Authorization: `Bearer ${accessToken}` },
      signal: controller.signal,
    });
  } catch {
    // PHP's getLineImageData() has no curl_error() branch of its own (it
    // only checks $httpCode/$imageContent below) — a transport-level
    // failure there would leave $httpCode at 0 and $imageContent at false,
    // which already falls into the same "Failed to download LINE image"
    // branch. Reproduced the same way here: any fetch rejection (including
    // our AbortController timeout) is treated identically to a non-200/empty
    // response.
    clearTimeout(timeoutId);
    return { success: false, error: 'Failed to download LINE image' };
  }
  clearTimeout(timeoutId);

  const buffer = Buffer.from(await response.arrayBuffer());
  if (response.status !== 200 || buffer.length === 0) {
    return { success: false, error: 'Failed to download LINE image' };
  }

  const mimeType = detectMimeType(buffer, response.headers.get('content-type'));
  return { success: true, base64: buffer.toString('base64'), mimeType };
}

/**
 * Branch 5 — generic http(s) URL. Port of the tail of `getImageData()`
 * (lines 850-899): unauthenticated GET, ~15s timeout, 404 handled as its
 * own case before the generic non-200 check (order preserved).
 */
async function getGenericHttpImageData(imageUrl: string): Promise<ImageDataResult> {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), GENERIC_FETCH_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(imageUrl, {
      headers: { 'User-Agent': 'Mozilla/5.0 (compatible; PharmacyImageAnalyzer/1.0)' },
      redirect: 'follow',
      signal: controller.signal,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: `ไม่สามารถเชื่อมต่อเพื่อดาวน์โหลดรูปภาพ: ${message}` };
  } finally {
    clearTimeout(timeoutId);
  }

  if (response.status === 404) {
    return { success: false, error: `ไม่พบไฟล์รูปภาพ (404): ${phpBasename(imageUrl)}` };
  }

  const buffer = Buffer.from(await response.arrayBuffer());
  if (response.status !== 200 || buffer.length === 0) {
    return { success: false, error: `ดาวน์โหลดรูปภาพไม่สำเร็จ (HTTP ${response.status})` };
  }

  const mimeType = detectMimeType(buffer, response.headers.get('content-type'));
  return { success: true, base64: buffer.toString('base64'), mimeType };
}

/**
 * Port of `PharmacyImageAnalyzerService::getImageData()` — see module doc
 * above for the full literal PHP source, branch-by-branch, and the
 * deliberate non-port of the two filesystem-sharing branches.
 */
export async function getImageData(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  imageUrl: string
): Promise<ImageDataResult> {
  if (imageUrl.startsWith('data:image/')) {
    return parseDataUrl(imageUrl);
  }

  if (imageUrl.includes(LINE_CONTENT_HOST_MARKER)) {
    return getLineImageData(db, lineAccountId, imageUrl);
  }

  // NOT PORTED here: PHP's "local file path" and "current-host direct file
  // read" branches — see module doc above. Every other imageUrl shape
  // falls through to the generic HTTP(S) fetch branch.
  return getGenericHttpImageData(imageUrl);
}
