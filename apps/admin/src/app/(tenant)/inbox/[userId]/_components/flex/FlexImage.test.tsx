import { render } from '@testing-library/react';
import { FlexImage } from './FlexImage';

describe('FlexImage', () => {
  it('renders the url, default md size, and contain object-fit', () => {
    const { container } = render(<FlexImage image={{ type: 'image', url: 'https://x/img.png' }} />);
    const img = container.querySelector('img[data-flex-type="image"]');
    expect(img).toHaveAttribute('src', 'https://x/img.png');
    expect(img).toHaveStyle({ width: '48px', objectFit: 'contain' });
  });

  it('aspectMode=cover maps to object-fit cover', () => {
    const { container } = render(<FlexImage image={{ type: 'image', url: 'x', aspectMode: 'cover', size: 'full' }} />);
    const img = container.querySelector('img');
    expect(img).toHaveStyle({ width: '100%', objectFit: 'cover' });
  });
});
