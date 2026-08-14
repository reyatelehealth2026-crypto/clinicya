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
import { GET, POST } from './route';

let imagesDir: string;
let filesDir: string;

beforeEach(() => {
  imagesDir = mkdtempSync(path.join(tmpdir(), 'inbox-batch-chat-images-'));
  filesDir = mkdtempSync(path.join(tmpdir(), 'inbox-batch-chat-files-'));
  process.env.INBOX_CHAT_IMAGES_UPLOAD_DIR = imagesDir;
  process.env.INBOX_CHAT_FILES_UPLOAD_DIR = filesDir;
  jest.clearAllMocks();
  mockResolveInboxApiContext.mockResolvedValue({
    ok: true,
    value: { db: {} as never, session: { adminUserId: 7, username: 'pharmacist1' } as never },
  });
});

afterEach(() => {
  delete process.env.INBOX_CHAT_IMAGES_UPLOAD_DIR;
  delete process.env.INBOX_CHAT_FILES_UPLOAD_DIR;
  rmSync(imagesDir, { recursive: true, force: true });
  rmSync(filesDir, { recursive: true, force: true });
});

function makeForm(file?: { name: string; type: string; bytes: number }): FormData {
  const form = new FormData();
  if (file) {
    const content = new Uint8Array(file.bytes).fill(3);
    form.append('file', new File([content], file.name, { type: file.type }));
  }
  return form;
}

function req(form: FormData, url = 'https://tenant-1234.re-ya.com/api/inbox/actions/upload-batch-file'): NextRequest {
  return { formData: async () => form, url } as unknown as NextRequest;
}

describe('POST /api/inbox/actions/upload-batch-file', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req(makeForm()));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('405 for GET — method-not-allowed guard', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it('400 "No file uploaded or upload error" when the file field is absent', async () => {
    const res = await POST(req(makeForm()));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No file uploaded or upload error' });
    expect(readdirSync(imagesDir)).toHaveLength(0);
    expect(readdirSync(filesDir)).toHaveLength(0);
  });

  it('400 "File too large (Max 10MB)" over the size cap — checked BEFORE the type check', async () => {
    // Deliberately an invalid MIME type too — size must win to prove size-before-type ordering.
    const form = makeForm({ name: 'huge.exe', type: 'application/x-msdownload', bytes: 10 * 1024 * 1024 + 1 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'File too large (Max 10MB)' });
  });

  it('400 "Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF" for a disallowed MIME under the size cap', async () => {
    const form = makeForm({ name: 'doc.docx', type: 'application/msword', bytes: 10 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF' });
  });

  it.each(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])(
    'happy path for allowed image MIME %s: writes to chat_images/, type="image", url===previewUrl, no ".jpg" fallback ext',
    async (mime) => {
      const form = makeForm({ name: 'rash', type: mime, bytes: 42 }); // no extension at all
      const res = await POST(req(form));
      const body = await res.json();

      expect(res.status).toBe(200);
      expect(body.success).toBe(true);
      expect(body.type).toBe('image');
      expect(body.url).toBe(body.previewUrl);
      expect(body.fileName).toBe('rash');
      expect(body.url).toMatch(/^https:\/\/tenant-1234\.re-ya\.com\/uploads\/chat_images\/img_\d+_[0-9a-f]+\.$/); // trailing dot, NO 'jpg' fallback

      const generatedName = (body.url as string).split('/').pop() as string;
      const written = readFileSync(path.join(imagesDir, generatedName));
      expect(written.length).toBe(42);
      expect(readdirSync(filesDir)).toHaveLength(0);
    }
  );

  it('happy path for application/pdf: writes to chat_files/, type="file", filename prefix "file_"', async () => {
    const form = makeForm({ name: 'invoice.pdf', type: 'application/pdf', bytes: 321 });
    const res = await POST(req(form));
    const body = await res.json();

    expect(res.status).toBe(200);
    expect(body.type).toBe('file');
    expect(body.url).toBe(body.previewUrl);
    expect(body.fileName).toBe('invoice.pdf');
    expect(body.url).toMatch(/^https:\/\/tenant-1234\.re-ya\.com\/uploads\/chat_files\/file_\d+_[0-9a-f]+\.pdf$/);

    const generatedName = (body.url as string).split('/').pop() as string;
    const written = readFileSync(path.join(filesDir, generatedName));
    expect(written.length).toBe(321);
    expect(readdirSync(imagesDir)).toHaveLength(0);
  });

  it('extension is preserved verbatim from the original filename (no case-normalization) and no "jpg" fallback when absent', async () => {
    const form = makeForm({ name: 'photo.JPEG', type: 'image/jpeg', bytes: 5 });
    const res = await POST(req(form));
    const body = await res.json();

    expect(body.url).toMatch(/\.JPEG$/);
  });

  it('no database writes of any kind — the fake session carries a "{}"-shaped db and the route never touches it', async () => {
    const form = makeForm({ name: 'a.png', type: 'image/png', bytes: 10 });
    const res = await POST(req(form));
    expect(res.status).toBe(200);
    // If uploadBatchFileAction() ever tried to call a Kysely method on `db: {}`, this request
    // would throw synchronously — reaching a clean 200 here IS the assertion that no DB access
    // was attempted.
  });
});
