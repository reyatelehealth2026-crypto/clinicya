import type { CSSProperties } from 'react';
import { FlexComponent } from './index';
import type { FlexComponentJson } from './types';

/**
 * FlexBox — port of `renderFlexBox()` (inbox-v2.php lines 8406-8424). Used
 * for `bubble.header`/`bubble.body`/`bubble.footer` (each always a `box`)
 * and recursively for any nested `type: 'box'` component.
 *
 * `slot` is a React-port-only addition (no PHP/JS equivalent) — a
 * `data-flex-slot` marker so the Flex structural snapshot test (brief
 * acceptance criteria) can assert header/hero/body/footer regions and their
 * nesting order without a byte/pixel diff.
 */

// Box's own spacing/margin map — LARGER than the leaf components' shared
// margin map (renderFlexText/Image/Button/Separator/Spacer all use a
// different, smaller map) — this asymmetry is literal in the source JS
// (compare inbox-v2.php lines 8414-8415 to line 8468 etc.), not a typo here.
const SPACING: Record<string, string> = { none: '0', xs: '1px', sm: '2px', md: '4px', lg: '6px', xl: '8px', xxl: '10px' };

export function FlexBox({
  box,
  slot,
}: {
  box: FlexComponentJson;
  slot?: 'header' | 'hero' | 'body' | 'footer';
}) {
  const layout = box.layout ?? 'vertical';
  const spacing = box.spacing ?? 'md';
  const margin = box.margin ?? 'none';
  const paddingAll = box.paddingAll ?? '8px';
  const backgroundColor = box.backgroundColor ?? 'transparent';

  const flexDirection: CSSProperties['flexDirection'] = layout === 'horizontal' ? 'row' : 'column';
  const gap = SPACING[spacing] ?? '4px';
  const marginValue = SPACING[margin] ?? '0';
  const contents = Array.isArray(box.contents) ? box.contents : [];

  return (
    <div
      style={{ display: 'flex', flexDirection, gap, marginTop: marginValue, padding: paddingAll, backgroundColor }}
      data-flex-type="box"
      data-flex-slot={slot}
    >
      {contents.map((comp, i) => (
        // eslint-disable-next-line react/no-array-index-key -- flex component lists carry no stable id in the LINE JSON payload, same key strategy the rest of this port uses for index-derived lists.
        <FlexComponent key={i} comp={comp} />
      ))}
    </div>
  );
}
