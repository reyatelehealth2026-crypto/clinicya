import { formatThaiTime, getMessagePreview } from './preview';

describe('getMessagePreview', () => {
  it('returns empty string for null content (PHP === null check)', () => {
    expect(getMessagePreview(null, 'text')).toBe('');
    expect(getMessagePreview(null, null)).toBe('');
  });

  it('returns empty string for undefined content', () => {
    expect(getMessagePreview(undefined, 'text')).toBe('');
  });

  it.each([
    ['image', '📷 รูปภาพ'],
    ['video', '🎥 วิดีโอ'],
    ['audio', '🎵 เสียง'],
    ['location', '📍 ตำแหน่งที่อยู่'],
    ['file', '📄 ไฟล์'],
    ['sticker', '😊 สติกเกอร์'],
    ['flex', '📋 Flex'],
  ] as const)('type=%s always returns the emoji label regardless of content', (type, expected) => {
    expect(getMessagePreview('anything', type)).toBe(expected);
    expect(getMessagePreview('', type)).toBe(expected);
  });

  it('returns short plain text unchanged (<=30 codepoints)', () => {
    expect(getMessagePreview('สวัสดีครับ', 'text')).toBe('สวัสดีครับ');
    expect(getMessagePreview('', 'text')).toBe('');
  });

  it('truncates plain text over 30 codepoints and appends "..."', () => {
    const content = 'a'.repeat(31);
    expect(getMessagePreview(content, 'text')).toBe(`${'a'.repeat(30)}...`);
  });

  it('leaves exactly-30-codepoint text untouched (boundary, not > 30)', () => {
    const content = 'a'.repeat(30);
    expect(getMessagePreview(content, 'text')).toBe(content);
  });

  it('truncates by Unicode codepoint, not UTF-16 code unit (astral characters like emoji count as 1)', () => {
    // 31 astral-plane emoji (each 2 UTF-16 code units) — mb_strlen would count 31, not 62.
    const content = '😀'.repeat(31);
    const result = getMessagePreview(content, 'text');
    expect(result).toBe(`${'😀'.repeat(30)}...`);
    expect(Array.from(result.replace(/\.\.\.$/, '')).length).toBe(30);
  });

  it('an unrecognized type falls through to the text-length branch', () => {
    expect(getMessagePreview('hello', 'unknown_type')).toBe('hello');
  });
});

describe('formatThaiTime', () => {
  // "now" = Bangkok 2026-07-14 15:00:00 (UTC+7) == 2026-07-14T08:00:00.000Z
  const now = new Date(Date.UTC(2026, 6, 14, 8, 0, 0));

  it('returns empty string for null/undefined/empty datetime', () => {
    expect(formatThaiTime(null, now)).toBe('');
    expect(formatThaiTime(undefined, now)).toBe('');
    expect(formatThaiTime('', now)).toBe('');
  });

  it('same Bangkok calendar day -> "HH:MM น."', () => {
    expect(formatThaiTime('2026-07-14 09:30:00', now)).toBe('09:30 น.');
  });

  it('accepts an absolute ISO instant equally (not double-shifted)', () => {
    // Same true instant as the naive '2026-07-14 09:30:00' Bangkok case above.
    expect(formatThaiTime('2026-07-14T02:30:00.000Z', now)).toBe('09:30 น.');
  });

  it('Bangkok calendar day = yesterday -> "เมื่อวาน HH:MM"', () => {
    expect(formatThaiTime('2026-07-13 22:15:00', now)).toBe('เมื่อวาน 22:15');
  });

  it('within the last 7 days (not today/yesterday) -> Thai day abbreviation + HH:MM', () => {
    const target = '2026-07-11 15:00:00';
    const thaiDayAbbrev = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
    const expectedDow = new Date(Date.UTC(2026, 6, 11)).getUTCDay();
    expect(formatThaiTime(target, now)).toBe(`${thaiDayAbbrev[expectedDow]} 15:00`);
  });

  it('older than 7 days -> "DD/MM HH:MM"', () => {
    expect(formatThaiTime('2026-07-01 08:00:00', now)).toBe('01/07 08:00');
  });

  it('accepts a numeric epoch for the injectable clock too', () => {
    expect(formatThaiTime('2026-07-14 09:30:00', now.getTime())).toBe('09:30 น.');
  });

  it('returns empty string for an unparseable datetime', () => {
    expect(formatThaiTime('not-a-date', now)).toBe('');
  });
});
