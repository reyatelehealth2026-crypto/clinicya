/**
 * Client-side QR decoding for payment slips.
 *
 * The Mini App reads the QR code embedded in a Thai bank-transfer slip and
 * sends the raw payload (`qr_data`) to the PHP checkout API, which verifies it
 * via GhostX. If decoding fails for any reason the slip still uploads — the
 * server falls back to manual admin review.
 *
 * `decodeQrFromImageData` is the pure, unit-tested seam (scanner injected).
 * `decodeSlipQr` is thin DOM glue (canvas + lazily-loaded jsQR).
 */

export type QrScanResult = { data: string } | null
export type QrScanner = (data: Uint8ClampedArray, width: number, height: number) => QrScanResult

export type DecodableImage = {
  data: Uint8ClampedArray
  width: number
  height: number
}

/** Run an injected scanner over raw image data; return a trimmed payload or null. */
export function decodeQrFromImageData(img: DecodableImage, scan: QrScanner): string | null {
  try {
    const code = scan(img.data, img.width, img.height)
    const payload = code?.data?.trim()
    return payload ? payload : null
  } catch {
    return null
  }
}

/** Draw a File into a canvas and return its ImageData (browser only). */
async function fileToImageData(file: File): Promise<DecodableImage | null> {
  if (typeof document === 'undefined') return null
  const url = URL.createObjectURL(file)
  try {
    const image = await new Promise<HTMLImageElement>((resolve, reject) => {
      const el = new Image()
      el.onload = () => resolve(el)
      el.onerror = () => reject(new Error('image load failed'))
      el.src = url
    })
    const canvas = document.createElement('canvas')
    canvas.width = image.naturalWidth
    canvas.height = image.naturalHeight
    const ctx = canvas.getContext('2d')
    if (!ctx) return null
    ctx.drawImage(image, 0, 0)
    return ctx.getImageData(0, 0, canvas.width, canvas.height)
  } finally {
    URL.revokeObjectURL(url)
  }
}

/**
 * Decode the QR payload from a slip image File. Returns null if no QR is found
 * or jsQR is unavailable — never throws.
 */
export async function decodeSlipQr(file: File): Promise<string | null> {
  try {
    const img = await fileToImageData(file)
    if (!img) return null
    const { default: jsQR } = await import('jsqr')
    // attemptBoth also tries an inverted image, which catches more real-world
    // slip photos (dark-on-light vs light-on-dark) than the default.
    return decodeQrFromImageData(img, (d, w, h) => jsQR(d, w, h, { inversionAttempts: 'attemptBoth' }))
  } catch {
    return null
  }
}
