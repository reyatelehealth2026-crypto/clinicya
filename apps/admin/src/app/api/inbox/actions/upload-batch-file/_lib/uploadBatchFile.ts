import { existsSync, promises as fs } from 'node:fs';
import { randomBytes } from 'node:crypto';
import path from 'node:path';

/**
 * uploadBatchFile.ts — literal port of `api/inbox-v2.php`'s
 * `case 'upload_batch_file':` (lines 3489-3543). Read the full case body
 * before editing this file.
 *
 * ```php
 * case 'upload_batch_file':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
 *         sendError('No file uploaded or upload error');
 *     }
 *     $file = $_FILES['file'];
 *     $maxSize = 10 * 1024 * 1024;
 *     if ($file['size'] > $maxSize) { sendError('File too large (Max 10MB)'); }
 *     $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
 *     if (!in_array($file['type'], $allowedTypes)) {
 *         sendError('Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF');
 *     }
 *     $isImage = strpos($file['type'], 'image/') === 0;
 *     $uploadDir = __DIR__ . '/../uploads/' . ($isImage ? 'chat_images' : 'chat_files') . '/';
 *     if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
 *     $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
 *     $filename = ($isImage ? 'img_' : 'file_') . time() . '_' . uniqid() . '.' . $ext;
 *     $filepath = $uploadDir . $filename;
 *     if (!move_uploaded_file($file['tmp_name'], $filepath)) { sendError('Failed to save file'); }
 *     $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
 *     $host = $_SERVER['HTTP_HOST'];
 *     $baseUrl = $protocol . $host . '/uploads/' . ($isImage ? 'chat_images' : 'chat_files') . '/' . $filename;
 *     sendResponse([
 *         'success' => true,
 *         'type' => $isImage ? 'image' : 'file',
 *         'url' => $baseUrl,
 *         'previewUrl' => $baseUrl, // For images, typically same. For videos/files, might differ.
 *         'fileName' => $file['name']
 *     ]);
 *     break;
 * ```
 *
 * SCOPE: this action NEVER touches `$db` and NEVER calls LINE — it only
 * validates the uploaded file and saves it to disk, staging it for a LATER
 * `send_batch_messages` call (whose `originalContentUrl`/`previewImageUrl`/
 * `fileName` fields this response's `url`/`previewUrl`/`fileName` are meant
 * to feed straight into from the client). Accordingly this function takes
 * no `db`/`session` parameters at all — same shape as
 * `upload-for-analysis/_lib/uploadForAnalysis.ts` (the closest sibling
 * precedent in the mediaSend batch this same round).
 *
 * VALIDATION ORDER (mirrors PHP exactly — note SIZE is checked BEFORE
 * TYPE here, the OPPOSITE order from `send-image`/`send-pdf`'s own
 * type-then-size checks): file presence -> size <= 10MB -> MIME type in
 * the 5-item allow-list -> [file written to disk] -> response. Every
 * validation error is a flat HTTP 400 `{success:false, error}`.
 *
 * DIRECTORY SELECTION is by MIME PREFIX (`strpos($file['type'], 'image/')
 * === 0`), NOT by file extension — `application/pdf` and anything else
 * that isn't `image/*` always lands in `chat_files/`, even though the
 * allow-list only ever admits `application/pdf` into that bucket in
 * practice (the allow-list itself is the only thing keeping arbitrary
 * non-image MIME types out).
 *
 * FILE UPLOAD TARGETS the SAME two on-disk directories `send-image`/
 * `send-pdf` (mediaSend batch, this same round) already write to —
 * `<repo-root>/uploads/chat_images/` and `<repo-root>/uploads/chat_files/`
 * — because `upload_batch_file` really does write to those same physical
 * PHP directories (`__DIR__ . '/../uploads/{chat_images,chat_files}/'`).
 * This file therefore reuses the SAME env-var override names those two
 * folders already established (`INBOX_CHAT_IMAGES_UPLOAD_DIR` /
 * `INBOX_CHAT_FILES_UPLOAD_DIR`) — a deliberate, documented naming
 * convergence on the physical directory identity, NOT a cross-folder code
 * import (this file's own directory-resolution logic is fully
 * self-contained, per this round's ownership split; see the runbook §"shared
 * upload helper" note for why no cross-folder import exists between the two
 * batches). Resolution strategy itself: env override wins, else walk
 * upward from `process.cwd()` to the `pnpm-workspace.yaml` repo-root marker
 * — same approach as `apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts`'s
 * `resolveSlipsUploadDir()`.
 *
 * `$ext = pathinfo($file['name'], PATHINFO_EXTENSION);` — NO `?: 'jpg'`
 * fallback here (unlike `send-image`/`upload-for-analysis`'s own
 * `phpFileExtensionOrJpg()` helper in the sibling mediaSend batch). A
 * filename with no extension produces an EMPTY extension segment in the
 * generated filename (e.g. `img_1699999999_abc123.` — trailing dot, no
 * suffix) — a real, confirmed difference between the two source PHP files,
 * preserved exactly, not unified.
 *
 * `previewUrl` is ALWAYS identical to `url` — PHP's own comment
 * ("For images, typically same. For videos/files, might differ.") is
 * aspirational; there is no divergent code path in the actual case body,
 * so this port does not invent one either.
 */

export interface UploadBatchFileActionResult {
  status: number;
  body: Record<string, unknown>;
}

function errorResult(status: number, error: string): UploadBatchFileActionResult {
  return { status, body: { success: false, error } };
}

const ALLOWED_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf']);
const MAX_FILE_BYTES = 10 * 1024 * 1024;

/** `pathinfo($name, PATHINFO_EXTENSION)` — empty string when the name has no dot. Deliberately NO 'jpg' fallback — see module doc. */
function phpFileExtension(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? '';
  const dot = base.lastIndexOf('.');
  return dot > -1 ? base.slice(dot + 1) : '';
}

/** Stand-in for PHP's `uniqid()` — an opaque, sufficiently-unique-per-call token for the filename. */
function uniqueToken(): string {
  return randomBytes(8).toString('hex');
}

/** Resolves `<repo-root>/uploads/{chat_images|chat_files}/` — see module doc for the env-var-naming convergence with `send-image`/`send-pdf`. */
function resolveUploadDir(isImage: boolean): string {
  const envVar = isImage ? 'INBOX_CHAT_IMAGES_UPLOAD_DIR' : 'INBOX_CHAT_FILES_UPLOAD_DIR';
  const override = process.env[envVar];
  if (override && override.trim() !== '') {
    return override;
  }
  const subdir = isImage ? 'chat_images' : 'chat_files';
  let dir = process.cwd();
  for (let i = 0; i < 12; i++) {
    if (existsSync(path.join(dir, 'pnpm-workspace.yaml'))) {
      return path.join(dir, 'uploads', subdir);
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      break;
    }
    dir = parent;
  }
  // Documented limitation: no pnpm-workspace.yaml found anywhere in the ancestry chain — fall back
  // to cwd-relative rather than failing outright. Set the env var explicitly in any deployment
  // where this matters.
  return path.join(process.cwd(), 'uploads', subdir);
}

export async function uploadBatchFileAction(form: FormData, origin: string): Promise<UploadBatchFileActionResult> {
  // inbox-v2.php lines 3492-3494: `if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw ...`
  const file = form.get('file');
  if (!(file instanceof File) || file.size === 0) {
    return errorResult(400, 'No file uploaded or upload error');
  }

  // inbox-v2.php lines 3497-3500 — SIZE checked BEFORE type (opposite order from send-image/send-pdf).
  if (file.size > MAX_FILE_BYTES) {
    return errorResult(400, 'File too large (Max 10MB)');
  }

  // inbox-v2.php lines 3502-3506.
  const mime = file.type || '';
  if (!ALLOWED_MIME_TYPES.has(mime)) {
    return errorResult(400, 'Invalid file type. Allowed: JPG, PNG, WEBP, GIF, PDF');
  }

  // inbox-v2.php line 3508: `$isImage = strpos($file['type'], 'image/') === 0;` — MIME prefix, not extension.
  const isImage = mime.startsWith('image/');

  const uploadDir = resolveUploadDir(isImage);
  await fs.mkdir(uploadDir, { recursive: true });

  const ext = phpFileExtension(file.name || '');
  const filename = `${isImage ? 'img_' : 'file_'}${Math.floor(Date.now() / 1000)}_${uniqueToken()}.${ext}`;
  const filepath = path.join(uploadDir, filename);

  const buffer = Buffer.from(await file.arrayBuffer());
  try {
    await fs.writeFile(filepath, buffer);
  } catch {
    // inbox-v2.php lines 3517-3519: `if (!move_uploaded_file(...)) sendError('Failed to save file');`
    return errorResult(400, 'Failed to save file');
  }

  // inbox-v2.php lines 3521-3524 — `origin` computed by route.ts from the incoming request's own
  // `new URL(request.url)`, never a hardcoded BASE_URL.
  const subdir = isImage ? 'chat_images' : 'chat_files';
  const url = `${origin}/uploads/${subdir}/${filename}`;

  // inbox-v2.php lines 3526-3532 — previewUrl is ALWAYS identical to url (see module doc); NO
  // database writes of any kind (this action only stages the file for a later
  // send-batch-messages call).
  return {
    status: 200,
    body: {
      success: true,
      type: isImage ? 'image' : 'file',
      url,
      previewUrl: url,
      fileName: file.name,
    },
  };
}
