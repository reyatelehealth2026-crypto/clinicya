import { render, screen } from '@testing-library/react';
import { FlexBubble } from './FlexBubble';

describe('FlexBubble', () => {
  it('renders only the slots present (header/hero/body/footer are each optional)', () => {
    const { container } = render(
      <FlexBubble bubble={{ type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: 'Body only' }] } }} />
    );
    expect(container.querySelector('[data-flex="bubble"]')).toBeInTheDocument();
    expect(container.querySelector('[data-flex-slot="header"]')).not.toBeInTheDocument();
    expect(container.querySelector('[data-flex-slot="hero"]')).not.toBeInTheDocument();
    expect(container.querySelector('[data-flex-slot="body"]')).toBeInTheDocument();
    expect(container.querySelector('[data-flex-slot="footer"]')).not.toBeInTheDocument();
    expect(screen.getByText('Body only')).toBeInTheDocument();
  });

  it('renders all four slots, in header -> hero -> body -> footer DOM order', () => {
    const { container } = render(
      <FlexBubble
        bubble={{
          type: 'bubble',
          header: { type: 'box', contents: [{ type: 'text', text: 'HEADER' }] },
          hero: { type: 'image', url: 'https://x/hero.png' },
          body: { type: 'box', contents: [{ type: 'text', text: 'BODY' }] },
          footer: { type: 'box', contents: [{ type: 'button', action: { label: 'FOOTER' } }] },
        }}
      />
    );
    const bubble = container.querySelector('[data-flex="bubble"]') as HTMLElement;
    const slots = Array.from(bubble.children).map((el) => el.getAttribute('data-flex-slot'));
    expect(slots).toEqual(['header', 'hero', 'body', 'footer']);
    expect(bubble.querySelector('[data-flex-slot="hero"] img')).toHaveAttribute('src', 'https://x/hero.png');
  });
});
