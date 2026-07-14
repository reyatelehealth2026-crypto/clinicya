import type { FlexComponentJson } from './types';

/** FlexImage — port of `renderFlexImage()` (inbox-v2.php lines 8481-8494). */

const SIZE: Record<string, string> = {
  xxs: '16px',
  xs: '24px',
  sm: '32px',
  md: '48px',
  lg: '64px',
  xl: '80px',
  xxl: '96px',
  '3xl': '112px',
  '4xl': '128px',
  '5xl': '144px',
  full: '100%',
};

const MARGIN: Record<string, string> = { none: '0', xs: '1px', sm: '2px', md: '3px', lg: '4px', xl: '6px', xxl: '8px' };

export function FlexImage({ image }: { image: FlexComponentJson }) {
  const width = SIZE[image.size ?? 'md'] ?? '48px';
  const marginValue = MARGIN[image.margin ?? 'none'] ?? '0';
  const objectFit = image.aspectMode === 'cover' ? 'cover' : 'contain';

  return (
    // eslint-disable-next-line @next/next/no-img-element -- structural port of a preview-only <img>, not next/image's optimization pipeline.
    <img
      src={image.url ?? ''}
      alt=""
      style={{ width, maxWidth: '100%', objectFit, borderRadius: 3, marginTop: marginValue }}
      loading="lazy"
      data-flex-type="image"
    />
  );
}
