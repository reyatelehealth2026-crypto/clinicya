import { existsSync, promises as fs } from 'node:fs';
import path from 'node:path';

/**
 * general-upload.ts — port of settings.php's inline shop-logo upload
 * handling (lines 158-176, inside the `$postTab === 'general'` save block):
 * an optional `<input type="file" name="logo_file">` alongside the
 * `shop_logo` URL text field — when a file is present AND its extension is
 * allowed, it is saved to disk and its public URL REPLACES whatever was
 * typed into the `shop_logo` field.
 *
 *   $logoUrl = $_POST['shop_logo'] ?? '';
 *   if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
 *       $uploadDir = __DIR__ . '/uploads/shop/';               // __DIR__ = repo root (settings.php lives there)
 *       if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
 *       $fileExt = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
 *       $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
 *       if (in_array($fileExt, $allowedExts)) {
 *           $fileName = 'logo_' . $currentBotId . '_' . time() . '.' . $fileExt;
 *           if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $uploadDir . $fileName)) {
 *               $logoUrl = rtrim(BASE_URL, '/') . '/uploads/shop/' . $fileName;
 *           }
 *       }
 *       // else: silently falls through — $logoUrl stays whatever the shop_logo text field held.
 *   }
 *
 * Validated by FILE EXTENSION ONLY (matching general.php's actual check,
 * confirmed by reading the full source — no `finfo`/`mime_content_type`
 * call anywhere in it) — DELIBERATELY NOT the MIME-type check
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts uses.
 * That file ports a DIFFERENT PHP source (api/checkout.php's
 * handleUploadSlip()) which really does check MIME type; general.php's
 * upload block has no equivalent.
 *
 * BASE_URL: config/config.php's literal `https://clinicya.re-ya.com/`, used
 * verbatim as the stored logo URL's origin — general.php has no
 * per-tenant-subdomain bug-fix comment the way uploadSlip.ts's `image_url`
 * does (see that file's module doc), so this stays an env-override-with-
 * literal-fallback constant (same convention as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/notify.ts's
 * `CHECKOUT_NOTIFY_BASE_URL`), not a `next/headers`-derived request origin.
 *
 * Upload directory resolution mirrors uploadSlip.ts's own
 * resolveSlipsUploadDir() APPROACH (env override + walk-up-to-
 * pnpm-workspace.yaml) — not that function itself (module-private,
 * slips-specific per this batch's allowed-paths note) — targeting
 * `<repo-root>/uploads/shop/` instead of `<repo-root>/uploads/slips/`.
 */

const ALLOWED_LOGO_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp']);

export interface ShopLogoUploadResult {
  logoUrl: string | null;
}

function resolveShopUploadDir(): string {
  const override = process.env.GENERAL_SHOP_UPLOAD_DIR;
  if (override && override.trim() !== '') {
    return override;
  }
  let dir = process.cwd();
  for (let i = 0; i < 12; i++) {
    if (existsSync(path.join(dir, 'pnpm-workspace.yaml'))) {
      return path.join(dir, 'uploads', 'shop');
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      break;
    }
    dir = parent;
  }
  // Documented limitation: no pnpm-workspace.yaml found in the ancestry chain — falls back to
  // cwd-relative rather than failing outright. Set GENERAL_SHOP_UPLOAD_DIR explicitly where this matters.
  return path.join(process.cwd(), 'uploads', 'shop');
}

function resolveShopBaseUrl(): string {
  const env = process.env.GENERAL_SHOP_BASE_URL;
  return env && env.trim() !== '' ? env.replace(/\/+$/, '') : 'https://clinicya.re-ya.com';
}

/** `strtolower(pathinfo($name, PATHINFO_EXTENSION))` — no dot in the name -> ''. */
function phpFileExtensionLower(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? '';
  const dot = base.lastIndexOf('.');
  if (dot === -1) return '';
  return base.slice(dot + 1).toLowerCase();
}

/**
 * Saves an uploaded shop-logo file when present AND its extension is
 * allowed. Returns `{ logoUrl: null }` for "nothing to save" (no file, or a
 * disallowed extension) — callers should keep whatever `shop_logo` URL text
 * field value they already had, matching PHP's silent fall-through.
 */
export async function saveShopLogoUpload(file: File | null, currentBotId: number): Promise<ShopLogoUploadResult> {
  if (!file || file.size === 0) {
    return { logoUrl: null };
  }

  const ext = phpFileExtensionLower(file.name || '');
  if (!ALLOWED_LOGO_EXTENSIONS.has(ext)) {
    return { logoUrl: null };
  }

  const uploadDir = resolveShopUploadDir();
  await fs.mkdir(uploadDir, { recursive: true });

  const fileName = `logo_${currentBotId}_${Math.floor(Date.now() / 1000)}.${ext}`;
  const filepath = path.join(uploadDir, fileName);

  const buffer = Buffer.from(await file.arrayBuffer());
  await fs.writeFile(filepath, buffer);

  return { logoUrl: `${resolveShopBaseUrl()}/uploads/shop/${fileName}` };
}
