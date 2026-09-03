import type { FlexComponentJson } from './types';

/**
 * FlexButton — port of `renderFlexButton()` (inbox-v2.php lines 8499-8522).
 *
 * DEVIATION FROM THE LITERAL JS (deliberate, structural-parity-preserving):
 * the JS builds an inline `onclick="window.open(uri,'_blank')"` string for
 * `action.type === 'uri'`. A React port cannot attach a real event handler
 * here without forcing a client-component boundary on every ancestor up to
 * the page (this component renders inside a Server Component when it's part
 * of the SSR'd initial-300 message list) — see this file's sibling
 * components' shared rationale. A plain `<a href>` achieves the same
 * "clicking it navigates to the uri" behavior with zero JS, so `uri` actions
 * render as an anchor; every other action type renders as an inert
 * `<button type="button">` (same as the PHP source, which also leaves
 * non-uri actions with no onclick at all).
 */

const MARGIN: Record<string, string> = { none: '0', xs: '1px', sm: '2px', md: '3px', lg: '4px', xl: '6px', xxl: '8px' };
const HEIGHT: Record<string, string> = { sm: '20px', md: '24px' };

export function FlexButton({ button }: { button: FlexComponentJson }) {
  const style = button.style ?? 'primary';
  const color = button.color ?? '#17C950';
  const marginValue = MARGIN[button.margin ?? 'none'] ?? '0';
  const heightValue = HEIGHT[button.height ?? 'md'] ?? '24px';

  const bgColor = style === 'primary' ? color : 'transparent';
  const textColor = style === 'primary' ? '#FFFFFF' : color;
  const border = style === 'link' ? 'none' : `1px solid ${color}`;
  const label = button.action?.label ?? 'Button';

  const commonStyle = {
    background: bgColor,
    color: textColor,
    border,
    borderRadius: 3,
    padding: '0 8px',
    height: heightValue,
    marginTop: marginValue,
    cursor: 'pointer',
    fontSize: 9,
    fontWeight: 500,
    width: '100%',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    textDecoration: 'none',
    boxSizing: 'border-box' as const,
  };

  if (button.action?.type === 'uri' && button.action.uri) {
    return (
      <a href={button.action.uri} target="_blank" rel="noreferrer" style={commonStyle} data-flex-type="button">
        {label}
      </a>
    );
  }

  return (
    <button type="button" style={commonStyle} data-flex-type="button">
      {label}
    </button>
  );
}
