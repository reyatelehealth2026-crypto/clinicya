import { render } from '@testing-library/react';
import { FlexSeparator } from './FlexSeparator';

describe('FlexSeparator', () => {
  it('renders a 1px line with the default color when unset', () => {
    const { container } = render(<FlexSeparator separator={{ type: 'separator' }} />);
    const el = container.querySelector('[data-flex-type="separator"]');
    expect(el).toHaveStyle({ height: '1px', background: '#E0E0E0' });
  });

  it('honors a custom color + margin', () => {
    const { container } = render(<FlexSeparator separator={{ type: 'separator', color: '#123456', margin: 'lg' }} />);
    const el = container.querySelector('[data-flex-type="separator"]');
    expect(el).toHaveStyle({ background: '#123456', marginTop: '4px' });
  });
});
