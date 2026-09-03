import { render, screen } from '@testing-library/react';
import { FlexCarousel } from './FlexCarousel';

describe('FlexCarousel', () => {
  it('renders one FlexBubble per carousel entry', () => {
    const { container } = render(
      <FlexCarousel
        carousel={{
          type: 'carousel',
          contents: [
            { type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: 'Bubble 1' }] } },
            { type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: 'Bubble 2' }] } },
          ],
        }}
      />
    );
    expect(container.querySelector('[data-flex="carousel"]')).toBeInTheDocument();
    expect(container.querySelectorAll('[data-flex="bubble"]')).toHaveLength(2);
    expect(screen.getByText('Bubble 1')).toBeInTheDocument();
    expect(screen.getByText('Bubble 2')).toBeInTheDocument();
  });

  it('tolerates a missing/non-array contents field without crashing', () => {
    const { container } = render(<FlexCarousel carousel={{ type: 'carousel' }} />);
    expect(container.querySelectorAll('[data-flex="bubble"]')).toHaveLength(0);
  });
});
