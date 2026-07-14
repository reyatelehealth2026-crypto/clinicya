import { createElement } from 'react';
import { FlexBubble } from './FlexBubble';
import { FlexCarousel } from './FlexCarousel';
import { FlexBox } from './FlexBox';
import { FlexText } from './FlexText';
import { FlexImage } from './FlexImage';
import { FlexButton } from './FlexButton';
import { FlexSeparator } from './FlexSeparator';
import { FlexSpacer } from './FlexSpacer';
import type { FlexBubbleJson, FlexCarouselJson, FlexComponentJson, FlexMessageJson } from './types';

export { FlexBubble } from './FlexBubble';
export { FlexCarousel } from './FlexCarousel';
export { FlexBox } from './FlexBox';
export { FlexText } from './FlexText';
export { FlexImage } from './FlexImage';
export { FlexButton } from './FlexButton';
export { FlexSeparator } from './FlexSeparator';
export { FlexSpacer } from './FlexSpacer';
export * from './types';

/**
 * FlexComponent — port of `renderFlexComponent()` (inbox-v2.php lines
 * 8429-8450): the leaf/nested-box type dispatcher shared by `FlexBox`
 * (contents array) and `FlexBubble` (hero slot). `filler` has no dedicated
 * file (matches the brief: only Bubble/Carousel/Box/Text/Image/Button/
 * Separator/Spacer are separate components) — it's a single-line case here,
 * same as the JS source's `case 'filler': return '<div style="flex: 1;"></div>'`.
 * An unrecognized/missing `type` renders nothing (`return ''` in the JS ->
 * `null` here), matching the `default:` branch exactly.
 */
export function FlexComponent({ comp }: { comp: FlexComponentJson | undefined | null }) {
  if (!comp || !comp.type) return null;

  switch (comp.type) {
    case 'text':
      return createElement(FlexText, { text: comp });
    case 'image':
      return createElement(FlexImage, { image: comp });
    case 'button':
      return createElement(FlexButton, { button: comp });
    case 'separator':
      return createElement(FlexSeparator, { separator: comp });
    case 'spacer':
      return createElement(FlexSpacer, { spacer: comp });
    case 'box':
      return createElement(FlexBox, { box: comp });
    case 'filler':
      return createElement('div', { style: { flex: 1 }, 'data-flex-type': 'filler' });
    default:
      return null;
  }
}

function FlexFallback({ error = false }: { error?: boolean }) {
  return createElement(
    'div',
    { className: 'bg-white rounded-lg border p-3 text-xs text-gray-500', 'data-flex': 'fallback' },
    createElement('i', { className: 'fas fa-cube mr-1', 'aria-hidden': true }),
    `Flex Message${error ? ' (Error)' : ''}`
  );
}

function routeFlexContents(data: unknown) {
  const flexData = data as FlexMessageJson;
  let contents: FlexMessageJson['contents'] = flexData;
  if (flexData && typeof flexData === 'object' && flexData.type === 'flex' && flexData.contents) {
    contents = flexData.contents;
  }

  if (contents && typeof contents === 'object' && (contents as FlexBubbleJson).type === 'bubble') {
    return createElement(FlexBubble, { bubble: contents as FlexBubbleJson });
  }
  if (
    contents &&
    typeof contents === 'object' &&
    (contents as FlexCarouselJson).type === 'carousel' &&
    Array.isArray((contents as FlexCarouselJson).contents)
  ) {
    return createElement(FlexCarousel, { carousel: contents as FlexCarouselJson });
  }
  return createElement(FlexFallback, {});
}

/**
 * FlexMessage — port of `renderFlexMessage()` (inbox-v2.php lines
 * 8342-8366) PLUS the SSR container `<script>`'s own outer try/catch
 * (inbox-v2.php lines 3476-3505), collapsed into one entry point since this
 * port renders the tree directly instead of deferring to client JS.
 *
 * Two distinct fallback texts, both literal ports:
 *  - malformed JSON (`JSON.parse` throws) -> "Flex Message (Error)" — matches
 *    the SSR script's own catch (line 3499), which is what the brief calls
 *    out by name.
 *  - valid JSON but not a recognized bubble/carousel shape -> plain
 *    "Flex Message" (no "(Error)") — matches `renderFlexMessage()`'s own
 *    fallback/catch text (lines 8361, 8364), which never appends detail.
 *
 * Unlike the JS version (a single string-building function whose synchronous
 * try/catch also catches deep rendering exceptions), this component cannot
 * catch an exception thrown by a CHILD component's own render pass (JSX
 * element creation is deferred, not synchronous — see this file's `git
 * blame`/PR description for why). `FlexBox`/`FlexCarousel` compensate by
 * defensively guarding their `contents` arrays (`Array.isArray(...) ? ... :
 * []`) instead, so a malformed-but-valid-JSON payload degrades to an empty
 * region rather than crashing — a strictly safer outcome than the PHP/JS
 * source's own fragile try/catch, not a behavior regression.
 */
export function FlexMessage({ content }: { content: string }) {
  let parsed: unknown;
  try {
    parsed = JSON.parse(content);
  } catch {
    return createElement(FlexFallback, { error: true });
  }
  return routeFlexContents(parsed);
}
