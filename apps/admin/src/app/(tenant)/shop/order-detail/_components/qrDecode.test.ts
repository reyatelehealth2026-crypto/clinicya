import { jsqrAtScale, detectSlipQR, loadImage, ensureJsQRLoaded, type JsQRFn, type BarcodeDetectorLike } from './qrDecode';

function fakeImage(naturalWidth: number, naturalHeight: number): HTMLImageElement {
  return { naturalWidth, naturalHeight } as unknown as HTMLImageElement;
}

describe('jsqrAtScale', () => {
  let getContextSpy: jest.SpyInstance;
  let getImageDataMock: jest.Mock;
  let drawImageMock: jest.Mock;

  beforeEach(() => {
    getImageDataMock = jest.fn().mockReturnValue({ data: new Uint8ClampedArray(4) });
    drawImageMock = jest.fn();
    getContextSpy = jest.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
      drawImage: drawImageMock,
      getImageData: getImageDataMock,
    } as unknown as CanvasRenderingContext2D);
  });

  afterEach(() => {
    getContextSpy.mockRestore();
  });

  it('returns null when jsQR is not a function', () => {
    expect(jsqrAtScale(fakeImage(100, 100), 1, undefined)).toBeNull();
  });

  it('returns null when scale is <= 0', () => {
    const jsQR: JsQRFn = jest.fn().mockReturnValue({ data: 'X' });
    expect(jsqrAtScale(fakeImage(100, 100), 0, jsQR)).toBeNull();
    expect(jsQR).not.toHaveBeenCalled();
  });

  it('draws at the scaled dimensions and returns the decoded data', () => {
    const jsQR: JsQRFn = jest.fn().mockReturnValue({ data: 'QRPAYLOAD' });
    const result = jsqrAtScale(fakeImage(200, 100), 0.5, jsQR);

    expect(drawImageMock).toHaveBeenCalledWith(expect.anything(), 0, 0, 100, 50);
    expect(getImageDataMock).toHaveBeenCalledWith(0, 0, 100, 50);
    expect(jsQR).toHaveBeenCalledWith(expect.any(Uint8ClampedArray), 100, 50, { inversionAttempts: 'attemptBoth' });
    expect(result).toBe('QRPAYLOAD');
  });

  it('returns null when jsQR finds nothing', () => {
    const jsQR: JsQRFn = jest.fn().mockReturnValue(null);
    expect(jsqrAtScale(fakeImage(100, 100), 1, jsQR)).toBeNull();
  });
});

describe('detectSlipQR', () => {
  it('prefers the native BarcodeDetector and never calls jsQR when it finds a code', async () => {
    const jsQR: JsQRFn = jest.fn();
    const detector: BarcodeDetectorLike = { detect: jest.fn().mockResolvedValue([{ rawValue: 'FROM-BARCODE-DETECTOR' }]) };
    const result = await detectSlipQR(fakeImage(100, 100), jsQR, () => detector);

    expect(result).toBe('FROM-BARCODE-DETECTOR');
    expect(jsQR).not.toHaveBeenCalled();
  });

  it('falls through to jsQR when the BarcodeDetector factory is undefined', async () => {
    const getContextSpy = jest
      .spyOn(HTMLCanvasElement.prototype, 'getContext')
      .mockReturnValue({ drawImage: jest.fn(), getImageData: jest.fn().mockReturnValue({ data: new Uint8ClampedArray(4) }) } as unknown as CanvasRenderingContext2D);

    const jsQR: JsQRFn = jest.fn().mockReturnValue({ data: 'FROM-JSQR' });
    const result = await detectSlipQR(fakeImage(100, 100), jsQR, undefined);

    expect(result).toBe('FROM-JSQR');
    expect(jsQR).toHaveBeenCalled();
    getContextSpy.mockRestore();
  });

  it('falls through to jsQR when the BarcodeDetector throws/finds nothing', async () => {
    const getContextSpy = jest
      .spyOn(HTMLCanvasElement.prototype, 'getContext')
      .mockReturnValue({ drawImage: jest.fn(), getImageData: jest.fn().mockReturnValue({ data: new Uint8ClampedArray(4) }) } as unknown as CanvasRenderingContext2D);

    const detector: BarcodeDetectorLike = { detect: jest.fn().mockRejectedValue(new Error('no support')) };
    const jsQR: JsQRFn = jest.fn().mockReturnValue({ data: 'FROM-JSQR-FALLBACK' });
    const result = await detectSlipQR(fakeImage(100, 100), jsQR, () => detector);

    expect(result).toBe('FROM-JSQR-FALLBACK');
    getContextSpy.mockRestore();
  });

  it('returns null when both BarcodeDetector and every jsQR scale fail', async () => {
    const getContextSpy = jest
      .spyOn(HTMLCanvasElement.prototype, 'getContext')
      .mockReturnValue({ drawImage: jest.fn(), getImageData: jest.fn().mockReturnValue({ data: new Uint8ClampedArray(4) }) } as unknown as CanvasRenderingContext2D);

    const jsQR: JsQRFn = jest.fn().mockReturnValue(null);
    const result = await detectSlipQR(fakeImage(100, 100), jsQR, undefined);

    expect(result).toBeNull();
    getContextSpy.mockRestore();
  });
});

describe('loadImage', () => {
  it('rejects when the image fails to load', async () => {
    class FailingImage {
      onload: (() => void) | null = null;
      onerror: (() => void) | null = null;
      crossOrigin = '';
      set src(_v: string) {
        queueMicrotask(() => this.onerror?.());
      }
    }
    const OriginalImage = global.Image;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    global.Image = FailingImage as any;

    await expect(loadImage('bad-url')).rejects.toThrow('image load failed');

    global.Image = OriginalImage;
  });

  it('resolves when the image loads successfully', async () => {
    class SucceedingImage {
      onload: (() => void) | null = null;
      onerror: (() => void) | null = null;
      crossOrigin = '';
      naturalWidth = 400;
      naturalHeight = 300;
      set src(_v: string) {
        queueMicrotask(() => this.onload?.());
      }
    }
    const OriginalImage = global.Image;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    global.Image = SucceedingImage as any;

    const img = await loadImage('good-url');
    expect((img as unknown as SucceedingImage).naturalWidth).toBe(400);

    global.Image = OriginalImage;
  });
});

describe('ensureJsQRLoaded', () => {
  afterEach(() => {
    delete (window as unknown as { jsQR?: unknown }).jsQR;
    document.head.querySelectorAll('script').forEach((s) => s.remove());
  });

  it('resolves immediately when window.jsQR already exists', async () => {
    const existing: JsQRFn = jest.fn();
    (window as unknown as { jsQR: JsQRFn }).jsQR = existing;
    const resolved = await ensureJsQRLoaded();
    expect(resolved).toBe(existing);
  });

  it('injects the CDN script tag and resolves window.jsQR on load', async () => {
    const promise = ensureJsQRLoaded();
    const script = document.head.querySelector('script[src*="jsqr"]');
    expect(script).not.toBeNull();

    // Simulate the CDN script executing and setting window.jsQR, then firing load.
    const fakeFn: JsQRFn = jest.fn();
    (window as unknown as { jsQR: JsQRFn }).jsQR = fakeFn;
    script!.dispatchEvent(new Event('load'));

    const resolved = await promise;
    expect(resolved).toBe(fakeFn);
  });
});
