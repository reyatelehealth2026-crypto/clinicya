/**
 * @jest-environment node
 */
const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import type { NextRequest } from 'next/server';
import { POST } from './route';

let tmpDir: string;

beforeEach(() => {
  tmpDir = mkdtempSync(path.join(tmpdir(), 'inbox-analysis-images-'));
  process.env.INBOX_ANALYSIS_IMAGES_UPLOAD_DIR = tmpDir;
  jest.clearAllMocks();
  mockResolveInboxApiContext.mockResolvedValue({
    ok: true,
    value: { db: {} as never, session: { adminUserId: 7, username: 'pharmacist1' } as never },
  });
});

afterEach(() => {
  delete process.env.INBOX_ANALYSIS_IMAGES_UPLOAD_DIR;
  rmSync(tmpDir, { recursive: true, force: true });
});

function makeForm(fields: Record<string, string>, file?: { name: string; type: string; bytes: number }): FormData {
  const form = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    form.append(k, v);
  }
  if (file) {
    const content = new Uint8Array(file.bytes).fill(3);
    form.append('image', new File([content], file.name, { type: file.type }));
  }
  return form;
}

function req(form: FormData, url = 'https://tenant-1234.re-ya.com/api/inbox/actions/upload-for-analysis'): NextRequest {
  return { formData: async () => form, url } as unknown as NextRequest;
}

describe('POST /api/inbox/actions/upload-for-analysis', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req(makeForm({})));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('400 "No image uploaded" when the image field is absent — user_id is NOT required (PHP never checks it)', async () => {
    const res = await POST(req(makeForm({})));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No image uploaded' });
    expect(readdirSync(tmpDir)).toHaveLength(0);
  });

  it('400 "No image uploaded" even when user_id is present but no file is attached', async () => {
    const res = await POST(req(makeForm({ user_id: '42' })));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No image uploaded' });
  });

  it('400 "Invalid image type" for a disallowed MIME', async () => {
    const form = makeForm({}, { name: 'a.pdf', type: 'application/pdf', bytes: 10 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid image type' });
  });

  it('400 "Image too large. Max 10MB" over the size cap', async () => {
    const form = makeForm({}, { name: 'a.jpg', type: 'image/jpeg', bytes: 10 * 1024 * 1024 + 1 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Image too large. Max 10MB' });
  });

  it('happy path: writes the file to INBOX_ANALYSIS_IMAGES_UPLOAD_DIR, returns {success,image_url,filename} with no DB/LINE involvement', async () => {
    const form = makeForm({ user_id: '999999' }, { name: 'rash.png', type: 'image/png', bytes: 321 });
    const res = await POST(req(form, 'https://tenant-1234.re-ya.com/api/inbox/actions/upload-for-analysis'));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.success).toBe(true);
    expect(body.image_url).toMatch(/^https:\/\/tenant-1234\.re-ya\.com\/uploads\/analysis_images\/analysis_\d+_[0-9a-f]+\.png$/);
    expect(body.filename).toBe((body.image_url as string).split('/').pop());

    const written = readFileSync(path.join(tmpDir, body.filename as string));
    expect(written.length).toBe(321);
  });

  it('extension falls back to jpg when the uploaded filename has none', async () => {
    const form = makeForm({}, { name: 'noextension', type: 'image/webp', bytes: 5 });
    const res = await POST(req(form));
    const body = await res.json();

    expect(body.filename).toMatch(/\.jpg$/);
  });
});
