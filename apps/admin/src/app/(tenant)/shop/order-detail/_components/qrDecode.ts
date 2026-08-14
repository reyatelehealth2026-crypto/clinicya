/**
 * qrDecode.ts — port of shop/order-detail.php's inline "Admin-side slip QR
 * decoding" `<script>` block (PHP lines 1247-1312): `loadImage()`,
 * `jsqrAtScale()`, `detectSlipQR()`. Browser-only (Image/canvas/
 * BarcodeDetector) — imported exclusively by the `'use client'` SlipCard
 * component.
 *
 * jsQR itself is NOT an npm dependency (installing one would mean editing
 * apps/admin/package.json, outside this batch's allowed-paths boundary) —
 * matching the PHP page's own `<script src="https://cdn.jsdelivr.net/npm/
 * jsqr@1.4.0/dist/jsQR.js">` CDN include, `ensureJsQRLoaded()` below injects
 * that exact same CDN script tag at runtime and resolves the resulting
 * `window.jsQR` global. Every decode function itself takes `jsQR` as a
 * plain injected parameter (not read from `window` internally) so the
 * decision logic is unit-testable without a real browser/network fetch —
 * same injectable-dependency pattern as `_lib/slipVerifier.ts`'s HTTP
 * transport and `@reya/line`'s `LineFetch`.
 */

export type JsQRFn = (
  data: Uint8ClampedArray,
  width: number,
  height: number,
  options?: { inversionAttempts?: string }
) => { data: string } | null;

export interface BarcodeDetectorLike {
  detect(source: CanvasImageSource): Promise<Array<{ rawValue?: string }>>;
}

const JSQR_CDN_URL = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';

/** Port of PHP's `loadImage(src)` (lines 1250-1258). */
export function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('image load failed'));
    img.src = src;
  });
}

/** Port of PHP's `jsqrAtScale(img, scale)` (lines 1260-1271). */
export function jsqrAtScale(img: HTMLImageElement, scale: number, jsQR: JsQRFn | undefined): string | null {
  if (typeof jsQR !== 'function' || !scale || scale <= 0) return null;
  const w = Math.max(1, Math.round(img.naturalWidth * scale));
  const h = Math.max(1, Math.round(img.naturalHeight * scale));
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  if (!ctx) return null;
  ctx.drawImage(img, 0, 0, w, h);
  const d = ctx.getImageData(0, 0, w, h);
  const code = jsQR(d.data, w, h, { inversionAttempts: 'attemptBoth' });
  return code && code.data ? code.data : null;
}

/**
 * 1) Native BarcodeDetector (the OS decoder, more robust than jsQR) — used
 *    first when available. 2) jsQR at several scales as a fallback (a small
 *    QR in a tall slip often needs downscaling). Port of PHP's
 *    `detectSlipQR(img)` (lines 1273-1291).
 */
export async function detectSlipQR(
  img: HTMLImageElement,
  jsQR: JsQRFn | undefined,
  barcodeDetectorFactory?: (() => BarcodeDetectorLike) | undefined
): Promise<string | null> {
  try {
    const factory = barcodeDetectorFactory ?? defaultBarcodeDetectorFactory();
    if (factory) {
      const det = factory();
      const codes = await det.detect(img);
      if (codes && codes.length && codes[0]?.rawValue) {
        return codes[0].rawValue;
      }
    }
  } catch {
    // fall through to jsQR
  }

  const maxDim = Math.max(img.naturalWidth, img.naturalHeight);
  const scales = [1, maxDim > 1200 ? 1200 / maxDim : 0.8, 0.5, 1.5];
  for (const s of scales) {
    const r = jsqrAtScale(img, s, jsQR);
    if (r) return r;
  }
  return null;
}

function defaultBarcodeDetectorFactory(): (() => BarcodeDetectorLike) | undefined {
  if (typeof window === 'undefined') return undefined;
  const w = window as unknown as { BarcodeDetector?: new (opts: { formats: string[] }) => BarcodeDetectorLike };
  if (typeof w.BarcodeDetector !== 'function') return undefined;
  const BarcodeDetectorCtor = w.BarcodeDetector;
  return () => new BarcodeDetectorCtor({ formats: ['qr_code'] });
}

let jsqrLoadPromise: Promise<JsQRFn | undefined> | null = null;

/**
 * Injects the jsQR CDN `<script>` tag (once) and resolves the resulting
 * `window.jsQR` global — the runtime equivalent of the PHP page's own
 * `<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js">`.
 * Resolves `undefined` (never rejects) on load failure so callers degrade
 * to "BarcodeDetector-only" rather than throwing.
 */
export function ensureJsQRLoaded(): Promise<JsQRFn | undefined> {
  if (typeof window === 'undefined') return Promise.resolve(undefined);
  const w = window as unknown as { jsQR?: JsQRFn };
  if (typeof w.jsQR === 'function') return Promise.resolve(w.jsQR);
  if (jsqrLoadPromise) return jsqrLoadPromise;

  jsqrLoadPromise = new Promise((resolve) => {
    const script = document.createElement('script');
    script.src = JSQR_CDN_URL;
    script.onload = () => resolve((window as unknown as { jsQR?: JsQRFn }).jsQR);
    script.onerror = () => resolve(undefined);
    document.head.appendChild(script);
  });
  return jsqrLoadPromise;
}

/** Orchestrates the full decode: load the slip image, ensure jsQR is available, then detect. */
export async function decodeSlipQrFromImage(
  imgSrc: string,
  barcodeDetectorFactory?: (() => BarcodeDetectorLike) | undefined
): Promise<string | null> {
  const img = await loadImage(imgSrc);
  const jsQR = await ensureJsQRLoaded();
  return detectSlipQR(img, jsQR, barcodeDetectorFactory);
}
