/**
 * types.ts — loose LINE Flex Message JSON shapes, scoped to exactly the
 * fields inbox-v2.php's client-side `renderFlexMessage`/`renderFlexBubble`/
 * `renderFlexCarousel`/`renderFlexBox`/`renderFlexComponent`/`renderFlexText`/
 * `renderFlexImage`/`renderFlexButton`/`renderFlexSeparator`/`renderFlexSpacer`
 * (inbox-v2.php lines 8342-8543) actually read. NOT a full LINE Messaging
 * API Flex Message type (byte-parity against classes/FlexTemplates.php's
 * real JSON output is explicitly out of scope for this batch — see the
 * brief). `[key: string]: unknown` on each shape lets a real (richer)
 * payload flow through without a type error; unread fields are simply
 * ignored, same as the PHP/JS renderer.
 */

export interface FlexAction {
  type?: string;
  label?: string;
  uri?: string;
  [key: string]: unknown;
}

export interface FlexComponentJson {
  type?: string;
  // box
  layout?: 'horizontal' | 'vertical' | 'baseline' | string;
  spacing?: string;
  margin?: string;
  paddingAll?: string;
  backgroundColor?: string;
  contents?: FlexComponentJson[];
  // text
  text?: string;
  size?: string;
  weight?: string;
  color?: string;
  align?: string;
  wrap?: boolean;
  maxLines?: number;
  flex?: number;
  // image
  url?: string;
  aspectRatio?: string;
  aspectMode?: string;
  // button
  style?: string;
  height?: string;
  action?: FlexAction;
  [key: string]: unknown;
}

export interface FlexBubbleJson {
  type?: string; // 'bubble'
  styles?: Record<string, unknown>; // read but never applied by the JS renderer either — see FlexBubble.tsx
  header?: FlexComponentJson;
  hero?: FlexComponentJson;
  body?: FlexComponentJson;
  footer?: FlexComponentJson;
  [key: string]: unknown;
}

export interface FlexCarouselJson {
  type?: string; // 'carousel'
  contents?: FlexBubbleJson[];
  [key: string]: unknown;
}

export type FlexContainerJson = FlexBubbleJson | FlexCarouselJson;

export interface FlexMessageJson {
  type?: string; // 'flex' when wrapped in the outer LINE message envelope
  contents?: FlexContainerJson;
  [key: string]: unknown;
}
