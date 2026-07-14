import type { CSSProperties } from 'react';
import type { FlexComponentJson } from './types';

/**
 * FlexText — port of `renderFlexText()` (inbox-v2.php lines 8455-8476).
 * Numeric maps ported 1:1 from the preview-scaled JS renderer (a deliberate
 * downsize from LINE's real Flex font sizes — see renderFlexBubble's own
 * 2026-05-25 comment — not LINE's actual spec; structural parity only).
 */

const FONT_SIZE: Record<string, string> = {
  xxs: '7px',
  xs: '8px',
  sm: '9px',
  md: '10px',
  lg: '11px',
  xl: '12px',
  xxl: '14px',
  '3xl': '16px',
  '4xl': '18px',
  '5xl': '20px',
};

const FONT_WEIGHT: Record<string, string> = { regular: '400', bold: '700' };

const TEXT_ALIGN: Record<string, string> = { start: 'left', center: 'center', end: 'right' };

const MARGIN: Record<string, string> = { none: '0', xs: '1px', sm: '2px', md: '3px', lg: '4px', xl: '6px', xxl: '8px' };

export function FlexText({ text }: { text: FlexComponentJson }) {
  const fontSize = FONT_SIZE[text.size ?? 'md'] ?? '10px';
  const fontWeight = FONT_WEIGHT[text.weight ?? 'regular'] ?? '400';
  const textAlign = TEXT_ALIGN[text.align ?? 'start'] ?? 'left';
  const marginValue = MARGIN[text.margin ?? 'none'] ?? '0';
  const wrap = text.wrap !== false;
  const maxLines = text.maxLines ?? 0;
  const flex = text.flex ?? 0;

  const style: CSSProperties = {
    fontSize,
    fontWeight: fontWeight as CSSProperties['fontWeight'],
    color: text.color ?? '#000000',
    textAlign: textAlign as CSSProperties['textAlign'],
    marginTop: marginValue,
    lineHeight: 1.3,
  };
  if (!wrap) {
    style.whiteSpace = 'nowrap';
    style.overflow = 'hidden';
    style.textOverflow = 'ellipsis';
  }
  if (maxLines > 0) {
    style.display = '-webkit-box';
    style.WebkitLineClamp = maxLines;
    style.WebkitBoxOrient = 'vertical';
    style.overflow = 'hidden';
  }
  if (flex > 0) {
    style.flex = flex;
  }

  return (
    <div style={style} data-flex-type="text">
      {text.text ?? ''}
    </div>
  );
}
