import type { FlexComponentJson } from './types';

/** FlexSeparator — port of `renderFlexSeparator()` (inbox-v2.php lines 8527-8533). */

const MARGIN: Record<string, string> = { none: '0', xs: '1px', sm: '2px', md: '3px', lg: '4px', xl: '6px', xxl: '8px' };

export function FlexSeparator({ separator }: { separator: FlexComponentJson }) {
  const marginValue = MARGIN[separator.margin ?? 'none'] ?? '0';
  const color = separator.color ?? '#E0E0E0';

  return <div style={{ height: 1, background: color, marginTop: marginValue }} data-flex-type="separator" />;
}
