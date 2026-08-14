/**
 * geminiTextClient.ts — THE ONLY LLM network seam in this builder's scope
 * (`ghost-draft/**` + `learn-draft/**`). Literal port of
 * `classes/PharmacyGhostDraftService.php::callAIWithTimeout()` (lines
 * 985-1050):
 *
 * ```php
 * private function callAIWithTimeout(string $prompt, int $timeout): array
 * {
 *     $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
 *     $data = [
 *         'contents' => [['parts' => [['text' => $prompt]]]],
 *         'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 500, 'topP' => 0.9, 'topK' => 30]
 *     ];
 *     $ch = curl_init($url);
 *     curl_setopt_array($ch, [
 *         CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
 *         CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
 *         CURLOPT_POSTFIELDS => json_encode($data),
 *         CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
 *         CURLOPT_SSL_VERIFYPEER => true
 *     ]);
 *     $response = curl_exec($ch);
 *     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 *     $curlError = curl_error($ch);
 *     $curlErrno = curl_errno($ch);
 *     curl_close($ch);
 *     if ($curlError || $curlErrno) {
 *         return ['success' => false, 'error' => "Connection error: [{$curlErrno}] {$curlError}"];
 *     }
 *     $result = json_decode($response, true);
 *     if ($httpCode !== 200) {
 *         $errorMsg = $result['error']['message'] ?? 'Unknown API error';
 *         return ['success' => false, 'error' => "API Error ($httpCode): $errorMsg"];
 *     }
 *     if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
 *         return ['success' => true, 'text' => trim($result['candidates'][0]['content']['parts'][0]['text'])];
 *     }
 *     return ['success' => false, 'error' => 'No response from API'];
 * }
 * ```
 *
 * `self::API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/'`,
 * `$this->model`/`$this->apiKey` come from `ai_settings` (loaded by
 * `../_lib/ghostDraft.ts`'s `loadGhostDraftCredentials()` — this module never
 * touches the DB itself, matching PHP's `callAIWithTimeout()` being a pure
 * HTTP call given an already-resolved model/key).
 *
 * SIMPLIFICATION — `CURLOPT_CONNECTTIMEOUT => min(2, $timeout)` (a SEPARATE,
 * shorter timeout just for the TCP-connect phase) has no clean equivalent
 * with the platform `fetch()` API's single `AbortSignal`-based timeout — this
 * port uses one overall `timeoutMs` covering the whole request (connect +
 * response), matching curl's `CURLOPT_TIMEOUT` behavior. Not expected to
 * matter in practice: the connect-timeout only ever fires faster than the
 * overall timeout when the network is unreachable, in which case both
 * ultimately surface the same "connection error" shape to the caller.
 *
 * THIS FILE MUST BE THE ONLY PLACE IN `ghost-draft/**`/`learn-draft/**` THAT
 * CALLS `fetch()` — every other module imports `callGeminiTextApi()` from
 * here rather than issuing its own HTTP request (enforced by this batch's
 * acceptance criteria: `grep -R "generativelanguage.googleapis"` must only
 * match this file).
 */

const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

export interface GeminiTextRequest {
  apiKey: string;
  model: string;
  prompt: string;
  /** Overall request timeout in milliseconds — PHP's `$timeout` param is in seconds (`self::DRAFT_TIMEOUT = 15`); callers convert. */
  timeoutMs: number;
}

export type GeminiTextResult = { success: true; text: string } | { success: false; error: string };

interface GeminiGenerateContentResponse {
  candidates?: Array<{
    content?: {
      parts?: Array<{ text?: string }>;
    };
  }>;
  error?: {
    message?: string;
  };
}

/** `$result['candidates'][0]['content']['parts'][0]['text']` — PHP's `isset()` chain, ported as a sequence of optional-chaining reads. */
function extractCandidateText(result: GeminiGenerateContentResponse): string | null {
  const text = result.candidates?.[0]?.content?.parts?.[0]?.text;
  return typeof text === 'string' ? text : null;
}

export async function callGeminiTextApi({ apiKey, model, prompt, timeoutMs }: GeminiTextRequest): Promise<GeminiTextResult> {
  const url = `${API_BASE}${model}:generateContent?key=${apiKey}`;

  const body = {
    contents: [{ parts: [{ text: prompt }] }],
    generationConfig: { temperature: 0.7, maxOutputTokens: 500, topP: 0.9, topK: 30 },
  };

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: controller.signal,
    });
  } catch (error) {
    // PHP: `"Connection error: [{$curlErrno}] {$curlError}"` — no curl errno
    // equivalent in `fetch()`; the underlying error's name/message stand in.
    const message = error instanceof Error ? `${error.name}: ${error.message}` : String(error);
    return { success: false, error: `Connection error: ${message}` };
  } finally {
    clearTimeout(timer);
  }

  let result: GeminiGenerateContentResponse | null;
  try {
    result = (await response.json()) as GeminiGenerateContentResponse;
  } catch {
    result = null;
  }

  if (response.status !== 200) {
    const errorMsg = result?.error?.message ?? 'Unknown API error';
    return { success: false, error: `API Error (${response.status}): ${errorMsg}` };
  }

  const text = result ? extractCandidateText(result) : null;
  if (text !== null) {
    return { success: true, text: text.trim() };
  }

  return { success: false, error: 'No response from API' };
}
