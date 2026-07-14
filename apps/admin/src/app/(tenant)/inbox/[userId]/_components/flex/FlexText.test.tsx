import { render, screen } from '@testing-library/react';
import { FlexText } from './FlexText';

describe('FlexText', () => {
  it('renders the text content with default size/weight/align', () => {
    render(<FlexText text={{ type: 'text', text: 'Hello' }} />);
    const el = screen.getByText('Hello');
    expect(el).toHaveAttribute('data-flex-type', 'text');
    expect(el).toHaveStyle({ fontSize: '10px', fontWeight: '400', textAlign: 'left' });
  });

  it('maps size/weight/color/align', () => {
    render(<FlexText text={{ type: 'text', text: 'Big', size: 'xl', weight: 'bold', color: '#FF0000', align: 'center' }} />);
    const el = screen.getByText('Big');
    expect(el).toHaveStyle({ fontSize: '12px', fontWeight: '700', color: '#FF0000', textAlign: 'center' });
  });

  it('empty text falls back to an empty string, not "undefined"', () => {
    const { container } = render(<FlexText text={{ type: 'text' }} />);
    expect(container.querySelector('[data-flex-type="text"]')).toHaveTextContent('');
  });
});
