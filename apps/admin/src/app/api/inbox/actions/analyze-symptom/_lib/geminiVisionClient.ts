/**
 * geminiVisionClient.ts — THE ONLY GEMINI NETWORK SEAM in this builder's
 * scope (`analyze-symptom`, `analyze-drug`, `analyze-prescription` all
 * funnel through this one file, via `imageAnalyzer.ts`'s `callVisionApi()`
 * orchestrator). Literal port of the network half of
 * `classes/PharmacyImageAnalyzerService.php::callVisionAPI()`
 * (lines 694-777):
 *
 * ```php
 * private function callVisionAPI(string $imageUrl, string $prompt): array
 * {
 *     $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
 *
 *     $imageData = $this->getImageData($imageUrl); // -> imageResolver.ts's getImageData()
 *     if (!$imageData['success']) {
 *         return ['success' => false, 'error' => $imageData['error'] ?? 'Failed to load image'];
 *     }
 *
 *     $data = [
 *         'contents' => [[
 *             'parts' => [
 *                 ['inline_data' => ['mime_type' => $imageData['mimeType'], 'data' => $imageData['base64']]],
 *                 ['text' => $prompt],
 *             ],
 *         ]],
 *         'generationConfig' => [
 *             'temperature' => 0.3, 'maxOutputTokens' => 2000, 'topP' => 0.8, 'topK' => 20,
 *         ],
 *     ];
 *
 *     $ch = curl_init($url);
 *     curl_setopt_array($ch, [
 *         CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
 *         CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
 *         CURLOPT_POSTFIELDS => json_encode($data),
 *         CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
 *     ]);
 *     $response = curl_exec($ch);
 *     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 *     $curlError = curl_error($ch);
 *     curl_close($ch);
 *
 *     if ($curlError) {
 *         return ['success' => false, 'error' => 'Connection error: ' . $curlError];
 *     }
 *
 *     $result = json_decode($response, true);
 *
 *     if ($httpCode !== 200) {
 *         $errorMsg = $result['error']['message'] ?? 'Unknown API error';
 *         return ['success' => false, 'error' => "API Error ($httpCode): $errorMsg"];
 *     }
 *
 *     if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
 *         return ['success' => true, 'text' => trim($result['candidates'][0]['content']['parts'][0]['text'])];
 *     }
 *
 *     return ['success' => false, 'error' => 'No response from API'];
 * }
 * ```
 *
 * SPLIT FROM PHP'S SINGLE METHOD, PER THIS BATCH'S BRIEF: PHP's
 * `callVisionAPI()` does both the image-acquisition step (`getImageData()`)
 * AND the Gemini network call in one method. This port deliberately splits
 * them into two independently-mockable seams — this file (the Gemini call
 * only, given an already-resolved `base64`/`mimeType`) and
 * `imageResolver.ts`'s `getImageData()` (the image-bytes fetch only) — so a
 * test can prove the image-download step alone never reaches the network
 * without also having to reason about the Gemini call, and vice versa. The
 * orchestration that ties them together, matching PHP's method body 1:1,
 * lives in `imageAnalyzer.ts`'s private `callVisionApi()`.
 *
 * NOT added to a shared `@reya/ai*` package — Phase 7 (mig-ai) owns the
 * real shared Gemini-client abstraction later; this batch's job is
 * explicitly NOT to pre-build it (see this batch's brief, "Do NOT create a
 * shared 'AI client'/vision package"). This file stays local to
 * `analyze-symptom/_lib/` — `analyze-drug`/`analyze-prescription` never
 * import it directly, only indirectly via `imageAnalyzer.ts`'s exported
 * orchestrators (`identifyDrug`/`ocrPrescription`).
 *
 * `CURLOPT_SSL_VERIFYPEER => true` has no fetch()-level equivalent to
 * disable (Node's `fetch` always verifies TLS by default) — no behavior
 * change needed, `true` is already the effective default.
 * `CURLOPT_TIMEOUT => 30` / `CURLOPT_CONNECTTIMEOUT => 10` are collapsed
 * into a single 30s `AbortController` timeout below (fetch has no separate
 * connect-vs-total timeout knob); a timeout abort is caught by the same
 * `catch` branch as any other transport failure, mirroring curl's own
 * behavior of surfacing a timeout as a populated `curl_error($ch)`.
 */

const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
const REQUEST_TIMEOUT_MS = 30_000;

export interface GeminiVisionRequest {
  apiKey: string;
  model: string;
  base64: string;
  mimeType: string;
  prompt: string;
}

export type GeminiVisionResult = { success: true; text: string } | { success: false; error: string };

/** Shape of the subset of Gemini's `generateContent` JSON response this port reads. */
interface GeminiGenerateContentResponse {
  candidates?: Array<{
    content?: {
      parts?: Array<{ text?: string }>;
    };
  }>;
  error?: { message?: string };
}

/**
 * Port of `PharmacyImageAnalyzerService::callVisionAPI()`'s network half —
 * see module doc above for the full literal PHP source and the
 * getImageData/Gemini-call split rationale.
 */
export async function callGeminiVisionApi(request: GeminiVisionRequest): Promise<GeminiVisionResult> {
  const url = `${API_BASE}${request.model}:generateContent?key=${request.apiKey}`;

  const body = {
    contents: [
      {
        parts: [
          { inline_data: { mime_type: request.mimeType, data: request.base64 } },
          { text: request.prompt },
        ],
      },
    ],
    generationConfig: {
      temperature: 0.3,
      maxOutputTokens: 2000,
      topP: 0.8,
      topK: 20,
    },
  };

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  let response: Response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: controller.signal,
    });
  } catch (error) {
    // Mirrors curl_exec() returning false + a populated curl_error($ch) —
    // both real transport failures (DNS, TLS, connection refused) and our
    // AbortController timeout land here, exactly like a curl timeout
    // populates $curlError rather than throwing.
    const message = error instanceof Error ? error.message : String(error);
    return { success: false, error: `Connection error: ${message}` };
  } finally {
    clearTimeout(timeoutId);
  }

  let result: GeminiGenerateContentResponse | null = null;
  try {
    result = (await response.json()) as GeminiGenerateContentResponse;
  } catch {
    // Non-JSON body — `result` stays null, matching PHP's `json_decode()`
    // returning null on invalid JSON (no exception either way).
    result = null;
  }

  if (response.status !== 200) {
    const errorMsg = result?.error?.message ?? 'Unknown API error';
    return { success: false, error: `API Error (${response.status}): ${errorMsg}` };
  }

  const text = result?.candidates?.[0]?.content?.parts?.[0]?.text;
  if (typeof text === 'string') {
    return { success: true, text: text.trim() };
  }

  return { success: false, error: 'No response from API' };
}
