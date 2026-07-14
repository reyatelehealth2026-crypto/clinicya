import { FlexBox } from './FlexBox';
import { FlexComponent } from './index';
import type { FlexBubbleJson } from './types';

/**
 * FlexBubble — port of `renderFlexBubble()` (inbox-v2.php lines 8371-8389).
 * `bubble.styles` is read but never applied by the JS renderer either
 * (dead field, replicated as-is — not a gap in this port).
 *
 * header/body/footer are always rendered via `FlexBox` (they're LINE Flex
 * `box` components by spec); `hero` is rendered via the general
 * `FlexComponent` dispatcher directly (matches `renderFlexComponent(bubble.hero)`
 * at inbox-v2.php line 8374 — hero is typically an `image` or `box`, never
 * assumed to be a box the way header/body/footer are).
 *
 * `data-flex-slot="hero"` wraps the hero output in a marker `<div>` — a
 * React-port-only addition (no PHP/JS wrapper element exists there) purely
 * so the Flex structural snapshot test can locate the hero region the same
 * way it locates header/body/footer (which get their marker via `FlexBox`'s
 * own `slot` prop).
 */
export function FlexBubble({ bubble }: { bubble: FlexBubbleJson }) {
  return (
    <div
      style={{
        width: '100%',
        maxWidth: 340,
        maxHeight: 480,
        borderRadius: 10,
        overflow: 'hidden',
        overflowY: 'auto',
        boxShadow: '0 2px 8px rgba(0,0,0,0.08)',
        background: 'white',
        fontSize: 13,
        lineHeight: 1.4,
        scrollbarWidth: 'thin',
      }}
      data-flex="bubble"
    >
      {bubble.header ? <FlexBox box={bubble.header} slot="header" /> : null}
      {bubble.hero ? (
        <div data-flex-slot="hero">
          <FlexComponent comp={bubble.hero} />
        </div>
      ) : null}
      {bubble.body ? <FlexBox box={bubble.body} slot="body" /> : null}
      {bubble.footer ? <FlexBox box={bubble.footer} slot="footer" /> : null}
    </div>
  );
}
