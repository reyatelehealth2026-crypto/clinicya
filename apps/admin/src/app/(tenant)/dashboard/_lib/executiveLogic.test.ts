import { isProblemMessageContent, computeTopIssues, responseTimeStyle, countAlertStyle, PROBLEM_KEYWORDS, TOP_ISSUE_KEYWORDS } from './executiveLogic';

describe('PROBLEM_KEYWORDS', () => {
  it('is the exact 10-keyword list from executive.php line 100', () => {
    expect(PROBLEM_KEYWORDS).toEqual(['ปัญหา', 'ไม่พอใจ', 'ช้า', 'แย่', 'ผิด', 'เสีย', 'ไม่ได้', 'รอนาน', 'ไม่ตอบ', 'complaint', 'problem']);
  });
});

describe('isProblemMessageContent', () => {
  it('matches a Thai keyword substring', () => {
    expect(isProblemMessageContent('สินค้ามีปัญหามาก')).toBe(true);
  });

  it('matches an English keyword case-insensitively (mirrors MySQL LIKE under a _ci collation)', () => {
    expect(isProblemMessageContent('This is a COMPLAINT about service')).toBe(true);
    expect(isProblemMessageContent('minor Problem here')).toBe(true);
  });

  it('returns false for ordinary content', () => {
    expect(isProblemMessageContent('สอบถามราคาสินค้าครับ')).toBe(false);
  });

  it('returns false for null/undefined/empty content', () => {
    expect(isProblemMessageContent(null)).toBe(false);
    expect(isProblemMessageContent(undefined)).toBe(false);
    expect(isProblemMessageContent('')).toBe(false);
  });
});

describe('computeTopIssues', () => {
  it('counts each of the 8 fixed keywords by substring occurrence (case-sensitive, like PHP strpos)', () => {
    const messages = ['สอบถามราคาสินค้า', 'ราคาเท่าไหร่', 'จัดส่งช้าไปหน่อย', 'สินค้าหมด'];
    const result = computeTopIssues(messages);
    const byKeyword = Object.fromEntries(result.map((r) => [r.keyword, r.count]));
    expect(byKeyword['สินค้า']).toBe(2);
    expect(byKeyword['ราคา']).toBe(2);
    expect(byKeyword['สอบถาม']).toBe(1);
    expect(byKeyword['จัดส่ง']).toBe(1);
    expect(byKeyword['ชำระเงิน']).toBe(0);
  });

  it('returns at most 5 entries, sorted descending by count', () => {
    const messages = ['สินค้า สินค้า สินค้า', 'ราคา ราคา', 'จัดส่ง', 'ชำระเงิน', 'คืนสินค้า', 'สอบถาม', 'แนะนำ', 'ปัญหา'];
    const result = computeTopIssues(messages);
    expect(result).toHaveLength(5);
    for (let i = 1; i < result.length; i += 1) {
      expect(result[i - 1]!.count).toBeGreaterThanOrEqual(result[i]!.count);
    }
    expect(result[0]!.keyword).toBe('สินค้า');
  });

  it('preserves original TOP_ISSUE_KEYWORDS insertion order as the tie-break (stable sort, mirrors PHP 8+ arsort())', () => {
    // No messages at all -> every keyword has count 0 -> the top-5 slice should be
    // the first 5 keywords in their declared order (all tied at zero).
    const result = computeTopIssues([]);
    expect(result.map((r) => r.keyword)).toEqual(TOP_ISSUE_KEYWORDS.slice(0, 5));
    expect(result.every((r) => r.count === 0)).toBe(true);
  });

  it('ignores null/undefined message entries without throwing', () => {
    expect(() => computeTopIssues([null, undefined, 'สินค้า'])).not.toThrow();
    expect(computeTopIssues([null, undefined, 'สินค้า']).find((r) => r.keyword === 'สินค้า')?.count).toBe(1);
  });
});

describe('responseTimeStyle', () => {
  it('is emerald/ดีมาก at and under 5 minutes', () => {
    expect(responseTimeStyle(0)).toEqual({ accent: 'emerald', label: 'ดีมาก' });
    expect(responseTimeStyle(5)).toEqual({ accent: 'emerald', label: 'ดีมาก' });
  });

  it('is amber/พอใช้ between 6 and 15 minutes inclusive', () => {
    expect(responseTimeStyle(6)).toEqual({ accent: 'amber', label: 'พอใช้' });
    expect(responseTimeStyle(15)).toEqual({ accent: 'amber', label: 'พอใช้' });
  });

  it('is rose/ต้องปรับปรุง above 15 minutes', () => {
    expect(responseTimeStyle(16)).toEqual({ accent: 'rose', label: 'ต้องปรับปรุง' });
    expect(responseTimeStyle(999)).toEqual({ accent: 'rose', label: 'ต้องปรับปรุง' });
  });
});

describe('countAlertStyle', () => {
  it('is rose+alert when count > 0 (unread and problem-count tiles share this threshold)', () => {
    expect(countAlertStyle(1)).toEqual({ accent: 'rose', alert: true });
    expect(countAlertStyle(20)).toEqual({ accent: 'rose', alert: true });
  });

  it('is emerald+no-alert when count is 0', () => {
    expect(countAlertStyle(0)).toEqual({ accent: 'emerald', alert: false });
  });
});
