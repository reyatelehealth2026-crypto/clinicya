/**
 * @jest-environment node
 */

/**
 * geminiVisionClient.test.ts — dedicated unit coverage for
 * `callGeminiVisionApi()`, run against the REAL implementation (no
 * `jest.mock('./geminiVisionClient')` here). `global.fetch` is a
 * per-test controllable mock.
 */

const mockFetch = jest.fn();
global.fetch = mockFetch as unknown as typeof fetch;

import { callGeminiVisionApi } from './geminiVisionClient';

function fakeResponse(status: number, jsonBody: unknown): Response {
  return {
    status,
    json: async () => jsonBody,
  } as unknown as Response;
}

const REQUEST = {
  apiKey: 'test-api-key',
  model: 'gemini-2.5-flash',
  base64: 'ZmFrZQ==',
  mimeType: 'image/jpeg',
  prompt: 'วิเคราะห์รูปภาพนี้',
};

beforeEach(() => {
  jest.clearAllMocks();
});

describe('callGeminiVisionApi()', () => {
  it('POSTs to API_BASE + model + :generateContent?key=... with the exact request body shape', async () => {
    mockFetch.mockResolvedValue(
      fakeResponse(200, { candidates: [{ content: { parts: [{ text: '  {"condition":"x"}  ' }] } }] })
    );

    const result = await callGeminiVisionApi(REQUEST);

    expect(mockFetch).toHaveBeenCalledTimes(1);
    const [url, init] = mockFetch.mock.calls[0] as [string, { method: string; headers: Record<string, string>; body: string }];
    expect(url).toBe('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=test-api-key');
    expect(init.method).toBe('POST');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(JSON.parse(init.body)).toEqual({
      contents: [
        {
          parts: [{ inline_data: { mime_type: 'image/jpeg', data: 'ZmFrZQ==' } }, { text: 'วิเคราะห์รูปภาพนี้' }],
        },
      ],
      generationConfig: { temperature: 0.3, maxOutputTokens: 2000, topP: 0.8, topK: 20 },
    });

    // trim()'d, matching PHP's trim($result['candidates'][0]['content']['parts'][0]['text'])
    expect(result).toEqual({ success: true, text: '{"condition":"x"}' });
  });

  it("non-200 status -> {success:false, error:'API Error (<code>): <message>'}", async () => {
    mockFetch.mockResolvedValue(fakeResponse(429, { error: { message: 'Quota exceeded' } }));

    const result = await callGeminiVisionApi(REQUEST);

    expect(result).toEqual({ success: false, error: 'API Error (429): Quota exceeded' });
  });

  it("non-200 status with no parseable error message -> 'Unknown API error'", async () => {
    mockFetch.mockResolvedValue({ status: 500, json: async () => { throw new Error('not json'); } } as unknown as Response);

    const result = await callGeminiVisionApi(REQUEST);

    expect(result).toEqual({ success: false, error: 'API Error (500): Unknown API error' });
  });

  it("200 status with no candidates -> {success:false, error:'No response from API'}", async () => {
    mockFetch.mockResolvedValue(fakeResponse(200, { candidates: [] }));

    const result = await callGeminiVisionApi(REQUEST);

    expect(result).toEqual({ success: false, error: 'No response from API' });
  });

  it("fetch rejection -> {success:false, error:'Connection error: <message>'}", async () => {
    mockFetch.mockRejectedValue(new Error('socket hang up'));

    const result = await callGeminiVisionApi(REQUEST);

    expect(result).toEqual({ success: false, error: 'Connection error: socket hang up' });
  });
});
