import { existsSync, promises as fs } from 'node:fs';
import { randomBytes } from 'node:crypto';
import path from 'node:path';

/**
 * uploadForAnalysis.ts — literal port of inbox-v2.php's `case 'upload_for_analysis':` (lines
 * 836-874). Read the full case body before editing this file.
 *
 * SCOPE: unlike send_image/send_pdf, this action NEVER touches `$db` and NEVER calls LINE — it
 * only validates the uploaded file and saves it to disk (used by the composer's "analyze this
 * image" flow, feeding a separate downstream AI-analysis call that is out of scope for this
 * batch). Accordingly this function takes no `db`/`session` parameters at all.
 *
 * `$_POST['user_id']` (inbox-v2.php line 837) is read by PHP but never checked or used anywhere
 * in this case body — grep-verified, it's dead code in the original. Do NOT add a `user_id`
 * requirement here that doesn't exist in the source.
 *
 * FILE UPLOAD: writes to `<repo-root>/uploads/analysis_images/` — the SAME on-disk directory
 * inbox-v2.php writes to (`__DIR__ . '/uploads/analysis_images/'`). See
 * resolveAnalysisImagesUploadDir()'s own doc comment for the resolution strategy (same
 * env-override + walk-up-to-pnpm-workspace.yaml approach as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts's resolveSlipsUploadDir()).
 * `image_url` is built from the INCOMING request's own scheme+host (`origin`, computed by
 * route.ts from `new URL(request.url)`), never a hardcoded BASE_URL.
 */

export interface UploadForAnalysisActionResult {
  status: number;
  body: Record<string, unknown>;
}

function errorResult(status: number, error: string): UploadForAnalysisActionResult {
  return { status, body: { success: false, error } };
}

const ALLOWED_IMAGE_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

/**
 * Resolves `<repo-root>/uploads/analysis_images/`. An explicit `INBOX_ANALYSIS_IMAGES_UPLOAD_DIR`
 * env override wins if set. Otherwise walks upward from `process.cwd()` looking for
 * `pnpm-workspace.yaml` (the repo-root marker) — same strategy as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts's resolveSlipsUploadDir().
 */
function resolveAnalysisImagesUploadDir(): string {
  const override = process.env.INBOX_ANALYSIS_IMAGES_UPLOAD_DIR;
  if (override && override.trim() !== '') {
    return override;
  }
  let dir = process.cwd();
  for (let i = 0; i < 12; i++) {
    if (existsSync(path.join(dir, 'pnpm-workspace.yaml'))) {
      return path.join(dir, 'uploads', 'analysis_images');
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      break;
    }
    dir = parent;
  }
  // Documented limitation: no pnpm-workspace.yaml found anywhere in the ancestry chain — fall back
  // to cwd-relative rather than failing outright. Set INBOX_ANALYSIS_IMAGES_UPLOAD_DIR explicitly
  // in any deployment where this matters.
  return path.join(process.cwd(), 'uploads', 'analysis_images');
}

/** `pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg'`. */
function phpFileExtensionOrJpg(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? '';
  const dot = base.lastIndexOf('.');
  const ext = dot > -1 ? base.slice(dot + 1) : '';
  return ext !== '' ? ext : 'jpg';
}

/** Stand-in for PHP's `uniqid()` — an opaque, sufficiently-unique-per-call token for the filename. */
function uniqueToken(): string {
  return randomBytes(8).toString('hex');
}

export async function uploadForAnalysisAction(form: FormData, origin: string): Promise<UploadForAnalysisActionResult> {
  // inbox-v2.php lines 838-840: `if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) throw ...`
  // NOTE: no user_id check — see this file's module doc.
  const file = form.get('image');
  if (!(file instanceof File) || file.size === 0) {
    return errorResult(400, 'No image uploaded');
  }

  // inbox-v2.php lines 842-846.
  const mime = file.type || '';
  if (!ALLOWED_IMAGE_MIME_TYPES.has(mime)) {
    return errorResult(400, 'Invalid image type');
  }

  // inbox-v2.php lines 848-850.
  if (file.size > MAX_IMAGE_BYTES) {
    return errorResult(400, 'Image too large. Max 10MB');
  }

  // inbox-v2.php lines 852-860: mkdir + pathinfo ext-or-jpg + 'analysis_' . time() . '_' . uniqid() . '.' . ext.
  const uploadDir = resolveAnalysisImagesUploadDir();
  await fs.mkdir(uploadDir, { recursive: true });

  const ext = phpFileExtensionOrJpg(file.name || '');
  const filename = `analysis_${Math.floor(Date.now() / 1000)}_${uniqueToken()}.${ext}`;
  const filepath = path.join(uploadDir, filename);

  const buffer = Buffer.from(await file.arrayBuffer());
  await fs.writeFile(filepath, buffer);

  // inbox-v2.php lines 862-864 — `origin` computed by route.ts from the incoming request's own
  // `new URL(request.url)`, never a hardcoded BASE_URL.
  const imageUrl = `${origin}/uploads/analysis_images/${filename}`;

  // inbox-v2.php lines 866-870 — response key is `filename` (the ON-DISK generated name), NOT
  // `file_name` like send_pdf's response envelope.
  return {
    status: 200,
    body: {
      success: true,
      image_url: imageUrl,
      filename,
    },
  };
}
