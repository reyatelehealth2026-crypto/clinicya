import type { FlexComponentJson } from './types';

/** FlexSpacer — port of `renderFlexSpacer()` (inbox-v2.php lines 8538-8543). */

const SIZE: Record<string, string> = { xs: '1px', sm: '2px', md: '3px', lg: '4px', xl: '6px', xxl: '8px' };

export function FlexSpacer({ spacer }: { spacer: FlexComponentJson }) {
  const sizeValue = SIZE[spacer.size ?? 'md'] ?? '3px';
  return <div style={{ height: sizeValue }} data-flex-type="spacer" />;
}
