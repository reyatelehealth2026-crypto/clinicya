import { bumpConversationToTop, updateUnreadBadge } from './realtimeDom';

/**
 * Builds a minimal `#userList` fixture with N `.user-item[data-user-id]`
 * rows, each with a `.last-msg`, `.last-time`, and `.relative.flex-shrink-0`
 * avatar container (no pre-existing `.unread-badge` unless requested) —
 * mirroring ConversationListItem.tsx's real rendered markup closely enough
 * for these pure-DOM assertions.
 */
function buildFixture(ids: number[]): void {
  document.body.innerHTML = `
    <div id="userList">
      ${ids
        .map(
          (id) => `
        <a class="user-item" data-user-id="${id}">
          <div class="relative flex-shrink-0">
            <img />
          </div>
          <div>
            <span class="last-time">00:00 น.</span>
            <p class="last-msg">old preview ${id}</p>
          </div>
        </a>`
        )
        .join('\n')}
    </div>
  `;
}

describe('realtimeDom', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  describe('bumpConversationToTop', () => {
    it('moves a non-first row to the top of #userList', () => {
      buildFixture([1, 2, 3]);
      bumpConversationToTop(document, 3, {});

      const ids = Array.from(document.querySelectorAll('.user-item')).map((el) => el.getAttribute('data-user-id'));
      expect(ids).toEqual(['3', '1', '2']);
    });

    it('leaves order unchanged (no-op insertBefore) when the row is already first', () => {
      buildFixture([1, 2, 3]);
      const userList = document.getElementById('userList')!;
      const firstBefore = userList.firstElementChild;

      bumpConversationToTop(document, 1, {});

      const ids = Array.from(document.querySelectorAll('.user-item')).map((el) => el.getAttribute('data-user-id'));
      expect(ids).toEqual(['1', '2', '3']);
      expect(userList.firstElementChild).toBe(firstBefore);
    });

    it('updates .last-msg textContent when message.content is a non-empty value', () => {
      buildFixture([1, 2]);
      bumpConversationToTop(document, 2, { content: 'สวัสดีครับ' });

      const item = document.querySelector('[data-user-id="2"]')!;
      expect(item.querySelector('.last-msg')?.textContent).toBe('สวัสดีครับ');
    });

    it('does NOT touch .last-msg when message.content is empty/falsy (mirrors PHP\'s `if (lastMsgEl && messageData.content)` guard)', () => {
      buildFixture([1, 2]);
      bumpConversationToTop(document, 2, { content: '' });

      const item = document.querySelector('[data-user-id="2"]')!;
      expect(item.querySelector('.last-msg')?.textContent).toBe('old preview 2');
    });

    it('does NOT touch .last-msg when message.content is absent entirely', () => {
      buildFixture([1, 2]);
      bumpConversationToTop(document, 2, {});

      const item = document.querySelector('[data-user-id="2"]')!;
      expect(item.querySelector('.last-msg')?.textContent).toBe('old preview 2');
    });

    it('updates .last-time to a HH:mm น. formatted string', () => {
      buildFixture([1]);
      bumpConversationToTop(document, 1, {});

      const item = document.querySelector('[data-user-id="1"]')!;
      expect(item.querySelector('.last-time')?.textContent).toMatch(/^\d{2}:\d{2} น\.$/);
    });

    it('creates an .unread-badge inside .relative.flex-shrink-0 when none exists yet', () => {
      buildFixture([1]);
      const item = document.querySelector('[data-user-id="1"]')!;
      expect(item.querySelector('.unread-badge')).toBeNull();

      bumpConversationToTop(document, 1, {});

      const badge = item.querySelector('.unread-badge');
      expect(badge).not.toBeNull();
      expect(badge?.textContent).toBe('1');
      expect(badge?.parentElement).toHaveClass('relative', 'flex-shrink-0');
    });

    it('increments an existing numeric .unread-badge', () => {
      buildFixture([1]);
      const item = document.querySelector('[data-user-id="1"]')!;
      const avatar = item.querySelector('.relative.flex-shrink-0')!;
      const badge = document.createElement('div');
      badge.className = 'unread-badge';
      badge.textContent = '3';
      avatar.appendChild(badge);

      bumpConversationToTop(document, 1, {});

      expect(item.querySelector('.unread-badge')?.textContent).toBe('4');
    });

    it('caps the unread badge display at 9+', () => {
      buildFixture([1]);
      const item = document.querySelector('[data-user-id="1"]')!;
      const avatar = item.querySelector('.relative.flex-shrink-0')!;
      const badge = document.createElement('div');
      badge.className = 'unread-badge';
      badge.textContent = '9';
      avatar.appendChild(badge);

      bumpConversationToTop(document, 1, {});
      expect(item.querySelector('.unread-badge')?.textContent).toBe('9+');

      // Incrementing an already-capped '9+' badge stays capped (parseInt('9+') === 9 -> 10 -> capped).
      bumpConversationToTop(document, 1, {});
      expect(item.querySelector('.unread-badge')?.textContent).toBe('9+');
    });

    it('is a no-op (does not throw) when #userList itself is missing from the document', () => {
      document.body.innerHTML = '<div id="somethingElse"></div>';
      expect(() => bumpConversationToTop(document, 1, { content: 'x' })).not.toThrow();
    });

    it('is a no-op (does not throw) when the target data-user-id is not present in the DOM (row not loaded/visible yet)', () => {
      buildFixture([1, 2]);
      expect(() => bumpConversationToTop(document, 999, { content: 'x' })).not.toThrow();

      const ids = Array.from(document.querySelectorAll('.user-item')).map((el) => el.getAttribute('data-user-id'));
      expect(ids).toEqual(['1', '2']);
    });

    it('matches userId whether passed as a number or a string', () => {
      buildFixture([1, 2]);
      bumpConversationToTop(document, '2', { content: 'string id match' });
      const item = document.querySelector('[data-user-id="2"]')!;
      expect(item.querySelector('.last-msg')?.textContent).toBe('string id match');
    });
  });

  describe('updateUnreadBadge', () => {
    it('creates a badge with textContent "1" when none exists', () => {
      buildFixture([5]);
      updateUnreadBadge(document, 5);
      const item = document.querySelector('[data-user-id="5"]')!;
      expect(item.querySelector('.unread-badge')?.textContent).toBe('1');
    });

    it('increments an existing badge, capping display at 9+', () => {
      buildFixture([5]);
      const item = document.querySelector('[data-user-id="5"]')!;
      const avatar = item.querySelector('.relative.flex-shrink-0')!;
      const badge = document.createElement('div');
      badge.className = 'unread-badge';
      badge.textContent = '9';
      avatar.appendChild(badge);

      updateUnreadBadge(document, 5);
      expect(item.querySelector('.unread-badge')?.textContent).toBe('9+');
    });

    it('is a no-op (does not throw) when the target data-user-id is not present', () => {
      buildFixture([1]);
      expect(() => updateUnreadBadge(document, 404)).not.toThrow();
    });
  });
});
