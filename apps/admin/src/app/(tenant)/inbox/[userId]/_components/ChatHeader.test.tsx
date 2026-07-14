import { render, screen } from '@testing-library/react';
import { ChatHeader, effectiveDisplayName } from './ChatHeader';

describe('effectiveDisplayName', () => {
  it('prefers custom_display_name when set', () => {
    expect(effectiveDisplayName({ pictureUrl: null, displayName: 'LINE Name', customDisplayName: 'Custom Name' })).toBe(
      'Custom Name'
    );
  });

  it('falls back to display_name when custom_display_name is empty/null', () => {
    expect(effectiveDisplayName({ pictureUrl: null, displayName: 'LINE Name', customDisplayName: null })).toBe('LINE Name');
    expect(effectiveDisplayName({ pictureUrl: null, displayName: 'LINE Name', customDisplayName: '' })).toBe('LINE Name');
  });
});

describe('ChatHeader', () => {
  it('renders the avatar, effective display name, and tags', () => {
    render(
      <ChatHeader
        user={{ pictureUrl: 'https://x/pic.png', displayName: 'สมศรี', customDisplayName: null }}
        tags={[
          { id: 1, name: 'VIP', color: '#ff0000' },
          { id: 2, name: 'ทดสอบ', color: '#00ff00' },
        ]}
      />
    );
    expect(screen.getByRole('heading', { name: 'สมศรี' })).toBeInTheDocument();
    expect(screen.getByRole('img')).toHaveAttribute('src', 'https://x/pic.png');
    expect(screen.getByText('VIP')).toBeInTheDocument();
    expect(screen.getByText('ทดสอบ')).toBeInTheDocument();
  });

  it('falls back to a placeholder avatar when picture_url is empty', () => {
    render(<ChatHeader user={{ pictureUrl: null, displayName: 'สมศรี', customDisplayName: null }} tags={[]} />);
    expect(screen.getByRole('img').getAttribute('src')).toMatch(/^data:image\/svg\+xml/);
  });

  it('renders no tag chips for an empty tag list', () => {
    const { container } = render(<ChatHeader user={{ pictureUrl: null, displayName: 'x', customDisplayName: null }} tags={[]} />);
    expect(container.querySelector('#userTags')?.children.length).toBe(0);
  });
});
