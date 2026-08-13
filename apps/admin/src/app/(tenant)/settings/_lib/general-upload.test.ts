/**
 * @jest-environment node
 */
import { mkdtempSync, readFileSync, rmSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { saveShopLogoUpload } from './general-upload';

let tmpDir: string;

beforeEach(() => {
  tmpDir = mkdtempSync(path.join(tmpdir(), 'shop-logo-'));
  process.env.GENERAL_SHOP_UPLOAD_DIR = tmpDir;
});

afterEach(() => {
  delete process.env.GENERAL_SHOP_UPLOAD_DIR;
  delete process.env.GENERAL_SHOP_BASE_URL;
  rmSync(tmpDir, { recursive: true, force: true });
});

function makeFile(name: string, bytes = 10): File {
  const content = new Uint8Array(bytes).fill(7);
  return new File([content], name);
}

describe('saveShopLogoUpload', () => {
  it('returns { logoUrl: null } when no file is provided', async () => {
    const result = await saveShopLogoUpload(null, 1);
    expect(result).toEqual({ logoUrl: null });
    expect(readdirSync(tmpDir)).toEqual([]);
  });

  it('returns { logoUrl: null } for a zero-byte file, matching the empty $_FILES upload guard', async () => {
    const result = await saveShopLogoUpload(makeFile('logo.png', 0), 1);
    expect(result).toEqual({ logoUrl: null });
  });

  it('writes an allowed-extension file under uploads/shop/ (not uploads/slips/ or any other dir)', async () => {
    const result = await saveShopLogoUpload(makeFile('logo.png'), 42);
    expect(result.logoUrl).toContain('/uploads/shop/');
    expect(result.logoUrl).not.toContain('/uploads/slips/');

    const files = readdirSync(tmpDir);
    expect(files).toHaveLength(1);
    expect(files[0]).toMatch(/^logo_42_\d+\.png$/);
    expect(readFileSync(path.join(tmpDir, files[0]!)).length).toBe(10);
  });

  it('names the file logo_<currentBotId>_<unixTimestamp>.<ext>', async () => {
    const before = Math.floor(Date.now() / 1000);
    const result = await saveShopLogoUpload(makeFile('photo.JPG'), 7);
    const after = Math.floor(Date.now() / 1000);

    const match = result.logoUrl?.match(/logo_7_(\d+)\.jpg$/);
    expect(match).not.toBeNull();
    const ts = Number(match![1]);
    expect(ts).toBeGreaterThanOrEqual(before);
    expect(ts).toBeLessThanOrEqual(after);
  });

  it.each(['jpg', 'jpeg', 'png', 'gif', 'webp'])('accepts the allowed extension "%s" (case-insensitively)', async (ext) => {
    const result = await saveShopLogoUpload(makeFile(`logo.${ext.toUpperCase()}`), 1);
    expect(result.logoUrl).toMatch(new RegExp(`\\.${ext}$`));
  });

  it('rejects a disallowed extension by EXTENSION CHECK ALONE (no MIME sniffing) — e.g. .svg', async () => {
    const result = await saveShopLogoUpload(makeFile('logo.svg'), 1);
    expect(result).toEqual({ logoUrl: null });
    expect(readdirSync(tmpDir)).toEqual([]);
  });

  it('rejects a disallowed extension even when the File object claims an image/* MIME type', async () => {
    const content = new Uint8Array(10).fill(1);
    const file = new File([content], 'logo.bmp', { type: 'image/png' });
    const result = await saveShopLogoUpload(file, 1);
    expect(result).toEqual({ logoUrl: null });
  });

  it('rejects a file with no extension at all', async () => {
    const result = await saveShopLogoUpload(makeFile('logo'), 1);
    expect(result).toEqual({ logoUrl: null });
  });

  it('falls back to the literal BASE_URL constant when no GENERAL_SHOP_BASE_URL override is set', async () => {
    const result = await saveShopLogoUpload(makeFile('logo.png'), 1);
    expect(result.logoUrl).toMatch(/^https:\/\/clinicya\.re-ya\.com\/uploads\/shop\//);
  });

  it('prefers GENERAL_SHOP_BASE_URL and strips trailing slashes when set', async () => {
    process.env.GENERAL_SHOP_BASE_URL = 'https://tenant-abcd.re-ya.com/';
    const result = await saveShopLogoUpload(makeFile('logo.png'), 1);
    expect(result.logoUrl).toMatch(/^https:\/\/tenant-abcd\.re-ya\.com\/uploads\/shop\//);
  });
});
