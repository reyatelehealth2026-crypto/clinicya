import { FlexBubble } from './FlexBubble';
import type { FlexCarouselJson } from './types';

/** FlexCarousel — port of `renderFlexCarousel()` (inbox-v2.php lines 8394-8401). */
export function FlexCarousel({ carousel }: { carousel: FlexCarouselJson }) {
  const bubbles = Array.isArray(carousel.contents) ? carousel.contents : [];
  return (
    <div
      style={{ display: 'flex', gap: 8, overflowX: 'auto', maxWidth: '100%', padding: 4, scrollbarWidth: 'thin' }}
      data-flex="carousel"
    >
      {bubbles.map((bubble, i) => (
        // eslint-disable-next-line react/no-array-index-key -- LINE carousel bubbles carry no stable id.
        <FlexBubble key={i} bubble={bubble} />
      ))}
    </div>
  );
}
