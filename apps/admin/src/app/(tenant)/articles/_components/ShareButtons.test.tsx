import { render, screen, fireEvent } from '@testing-library/react';
import { ShareButtons } from './ShareButtons';

const URL = 'https://tenant-abcd.re-ya.com/articles/how-to-vitamin-c';
const TITLE = 'วิธีทานวิตามินซี';

describe('ShareButtons', () => {
  it('builds facebook/twitter/line share links with the encoded url (+ title for twitter)', () => {
    render(<ShareButtons url={URL} title={TITLE} />);

    expect(screen.getByRole('link', { name: 'Share on Facebook' })).toHaveAttribute(
      'href',
      `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(URL)}`
    );
    expect(screen.getByRole('link', { name: 'Share on Twitter' })).toHaveAttribute(
      'href',
      `https://twitter.com/intent/tweet?url=${encodeURIComponent(URL)}&text=${encodeURIComponent(TITLE)}`
    );
    expect(screen.getByRole('link', { name: 'Share on LINE' })).toHaveAttribute(
      'href',
      `https://social-plugins.line.me/lineit/share?url=${encodeURIComponent(URL)}`
    );
  });

  it('copies the url to the clipboard and alerts on click', async () => {
    const writeText = jest.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});

    render(<ShareButtons url={URL} title={TITLE} />);
    fireEvent.click(screen.getByRole('button', { name: 'Copy Link' }));
    await Promise.resolve();
    await Promise.resolve();

    expect(writeText).toHaveBeenCalledWith(URL);
    expect(alertSpy).toHaveBeenCalledWith('คัดลอกลิงก์แล้ว!');

    alertSpy.mockRestore();
  });

  it('does not throw when the clipboard API rejects', async () => {
    const writeText = jest.fn().mockRejectedValue(new Error('denied'));
    Object.assign(navigator, { clipboard: { writeText } });
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});

    render(<ShareButtons url={URL} title={TITLE} />);
    fireEvent.click(screen.getByRole('button', { name: 'Copy Link' }));
    await Promise.resolve();
    await Promise.resolve();

    expect(alertSpy).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });
});
