import { render, screen } from '@testing-library/react';

let mockPlatform: string | null = null;
jest.mock('next/navigation', () => ({
  useSearchParams: () => ({ get: (key: string) => (key === 'platform' ? mockPlatform : null) }),
}));

import { ChannelSwitcher } from './ChannelSwitcher';

describe('ChannelSwitcher', () => {
  beforeEach(() => {
    mockPlatform = null;
  });

  it('renders all three channel tabs', () => {
    render(<ChannelSwitcher counts={{ facebook: 0, tiktok: 0 }} />);
    expect(screen.getByText('LINE')).toBeInTheDocument();
    expect(screen.getByText('Messenger')).toBeInTheDocument();
    expect(screen.getByText('TikTok')).toBeInTheDocument();
  });

  it('defaults to LINE as the active tab when no platform param is present', () => {
    render(<ChannelSwitcher counts={{ facebook: 0, tiktok: 0 }} />);
    expect(screen.getByText('LINE').closest('a')).toHaveAttribute('aria-current', 'page');
    expect(screen.getByText('Messenger').closest('a')).not.toHaveAttribute('aria-current');
  });

  it('highlights facebook as active when ?platform=facebook', () => {
    mockPlatform = 'facebook';
    render(<ChannelSwitcher counts={{ facebook: 0, tiktok: 0 }} />);
    expect(screen.getByText('Messenger').closest('a')).toHaveAttribute('aria-current', 'page');
    expect(screen.getByText('LINE').closest('a')).not.toHaveAttribute('aria-current');
  });

  it('an invalid platform value falls back to LINE active', () => {
    mockPlatform = 'whatsapp';
    render(<ChannelSwitcher counts={{ facebook: 0, tiktok: 0 }} />);
    expect(screen.getByText('LINE').closest('a')).toHaveAttribute('aria-current', 'page');
  });

  it('links point at /inbox and /inbox?platform=X', () => {
    render(<ChannelSwitcher counts={{ facebook: 0, tiktok: 0 }} />);
    expect(screen.getByText('LINE').closest('a')).toHaveAttribute('href', '/inbox');
    expect(screen.getByText('Messenger').closest('a')).toHaveAttribute('href', '/inbox?platform=facebook');
    expect(screen.getByText('TikTok').closest('a')).toHaveAttribute('href', '/inbox?platform=tiktok');
  });

  it('shows a count badge for facebook/tiktok only when > 0', () => {
    render(<ChannelSwitcher counts={{ facebook: 5, tiktok: 0 }} />);
    expect(screen.getByText('5')).toBeInTheDocument();
    // TikTok's own count (0) should not render as a visible badge.
    expect(screen.getByText('TikTok').closest('a')?.textContent).toBe('TikTok');
  });

  it('never shows a count badge for the LINE tab', () => {
    render(<ChannelSwitcher counts={{ facebook: 5, tiktok: 5 }} />);
    expect(screen.getByText('LINE').closest('a')?.textContent).toBe('LINE');
  });
});
