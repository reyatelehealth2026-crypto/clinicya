import { mbSubstr, mbStrlen, truncateMb } from './text';

describe('mbSubstr / mbStrlen', () => {
  it('counts Thai text by code point (matches naive length here, but via the correct mechanism)', () => {
    const thai = 'สวัสดีครับยินดีต้อนรับ';
    expect(mbStrlen(thai)).toBe(Array.from(thai).length);
    expect(mbSubstr(thai, 5)).toBe(Array.from(thai).slice(0, 5).join(''));
  });

  it('counts a supplementary-plane emoji as ONE unit, unlike UTF-16 .length/.slice()', () => {
    const withEmoji = '😀😀😀'; // each emoji is a surrogate pair = 2 UTF-16 code units
    expect(withEmoji.length).toBe(6); // naive JS length: wrong for this purpose
    expect(mbStrlen(withEmoji)).toBe(3); // code-point length: matches PHP mb_strlen()
    expect(mbSubstr(withEmoji, 1)).toBe('😀');
    // A naive .slice(0, 1) would cut a surrogate pair in half:
    expect(withEmoji.slice(0, 1)).not.toBe('😀');
  });

  it('returns the whole string when length exceeds the content length', () => {
    expect(mbSubstr('abc', 100)).toBe('abc');
  });
});

describe('truncateMb', () => {
  it('appends "..." only when the content exceeds the limit', () => {
    expect(truncateMb('a'.repeat(100), 100)).toBe('a'.repeat(100));
    expect(truncateMb('a'.repeat(101), 100)).toBe(`${'a'.repeat(100)}...`);
  });

  it('truncates at the code-point boundary, not a UTF-16 code-unit boundary', () => {
    const content = `${'ก'.repeat(99)}😀😀`; // 99 Thai chars + 2 emoji = 101 code points
    const result = truncateMb(content, 100);
    expect(result).toBe(`${'ก'.repeat(99)}😀...`);
  });
});
