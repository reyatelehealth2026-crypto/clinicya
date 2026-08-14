import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  addShareButtonToFlex,
  buildAutoReplyReply,
  matchAutoReplyRule,
  resolveAutoReply,
  type AutoReplyBuildConfig,
  type AutoReplyRuleRow,
  type FlexBubbleOrCarousel,
} from '../src/auto-reply';

// Load-bearing: this package's own subdirectory, NOT the shared top-level src/__fixtures__/ dir
// that flex.test.ts's listFixtureFiles() does a non-recursive readdirSync over — dropping a file
// flat into the shared directory instead of here would make flex.test.ts throw on a request.fn
// it doesn't recognize. See the porting brief's "allowed paths" boundary note.
const FIXTURES_DIR = join(__dirname, '../src/__fixtures__/auto-reply');

interface Fixture {
  description: string;
  request: Record<string, unknown>;
  response: unknown;
}

function loadFixture(name: string): Fixture {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8')) as Fixture;
}

function listFixtureFiles(): string[] {
  return readdirSync(FIXTURES_DIR)
    .filter((f) => f.endsWith('.json'))
    .sort();
}

/** Dispatches a fixture's `request.fn` to the matching ported function, exactly reproducing each
 *  function's real call signature (see auto-reply.ts's exported signatures). Unlike flex.ts's
 *  fixtures, nothing here is date/time-derived, so no field normalization is needed anywhere in
 *  this file — every fixture is asserted via exact structural equality. */
function callPortedFunction(request: Record<string, unknown>): unknown {
  switch (request.fn) {
    case 'matchAutoReplyRule':
      return matchAutoReplyRule(request.rules as AutoReplyRuleRow[], request.text as string);
    case 'buildAutoReplyReply':
      return buildAutoReplyReply(request.rule as AutoReplyRuleRow, request.config as AutoReplyBuildConfig);
    case 'resolveAutoReply':
      return resolveAutoReply(
        request.rules as AutoReplyRuleRow[],
        request.text as string,
        request.config as AutoReplyBuildConfig
      );
    case 'addShareButtonToFlex':
      return addShareButtonToFlex(
        request.flexContent as FlexBubbleOrCarousel,
        request.ruleId as number,
        request.label as string,
        request.liffShareId as string
      );
    default:
      throw new Error(`fixture request.fn is not a recognized ported function: ${String(request.fn)}`);
  }
}

describe('auto-reply.ts — golden fixture round-trip against real PHP checkAutoReply()/addShareButtonToFlex() output', () => {
  const files = listFixtureFiles();

  // Fails loudly (rather than silently passing on an empty/missing directory) if fixtures go
  // missing — this number must stay >= 16 per the porting brief's acceptance criteria.
  it('has at least 16 committed fixtures', () => {
    expect(files.length).toBeGreaterThanOrEqual(16);
  });

  for (const file of files) {
    it(`${file} — matches PHP output exactly (no normalization: nothing here is time-derived)`, () => {
      const fixture = loadFixture(file);
      const actual = callPortedFunction(fixture.request);
      expect(actual).toEqual(fixture.response);
    });
  }
});

describe('resolveAutoReply() — exact-once-match short-circuit (the porting brief\'s "landmine")', () => {
  it('match-succeeds-but-build-fails on the first rule returns null, even though a second, lower-priority rule would also have matched', () => {
    const badFlexRule: AutoReplyRuleRow = {
      id: 901,
      line_account_id: null,
      keyword: 'โปร',
      match_type: 'contains',
      reply_type: 'flex',
      reply_content: '{not valid json,,,', // json_decode() failure -> buildAutoReplyReply() returns null
      alt_text: null,
      sender_name: null,
      sender_icon: null,
      quick_reply: null,
      enable_share: 0,
      share_button_label: null,
      is_active: 1,
      priority: 10,
    };
    const wouldAlsoMatchRule: AutoReplyRuleRow = {
      id: 902,
      line_account_id: null,
      keyword: 'โปร',
      match_type: 'contains',
      reply_type: 'text',
      reply_content: 'ควรถูกมองข้ามไป ไม่ควรถูกใช้เป็นคำตอบ',
      alt_text: null,
      sender_name: null,
      sender_icon: null,
      quick_reply: null,
      enable_share: 0,
      share_button_label: null,
      is_active: 1,
      priority: 1,
    };

    // Sanity check: if the first rule were absent, the second rule alone WOULD match and build.
    const secondRuleAlone = resolveAutoReply([wouldAlsoMatchRule], 'มีโปรไหมคะ', {});
    expect(secondRuleAlone).not.toBeNull();

    // With both rules present (first one wins the match, and only it is ever tried to build):
    const result = resolveAutoReply([badFlexRule, wouldAlsoMatchRule], 'มีโปรไหมคะ', {});
    expect(result).toBeNull();

    // matchAutoReplyRule() on its own confirms rule 1 (not rule 2) is what actually matched first.
    const matched = matchAutoReplyRule([badFlexRule, wouldAlsoMatchRule], 'มีโปรไหมคะ');
    expect(matched?.id).toBe(901);
  });
});

describe('matchAutoReplyRule() — invalid regex pattern parity (non-match, not a throw)', () => {
  it('a malformed regex pattern is skipped as a non-match instead of throwing, matching PHP preg_match()\'s false-return (not exception) behavior', () => {
    const invalidRegexRule: AutoReplyRuleRow = {
      id: 903,
      line_account_id: null,
      keyword: '[unterminated(', // malformed: unbalanced character class + group
      match_type: 'regex',
      reply_type: 'text',
      reply_content: 'should never be reached',
      alt_text: null,
      sender_name: null,
      sender_icon: null,
      quick_reply: null,
      enable_share: 0,
      share_button_label: null,
      is_active: 1,
      priority: 0,
    };

    expect(() => matchAutoReplyRule([invalidRegexRule], 'any text at all')).not.toThrow();
    expect(matchAutoReplyRule([invalidRegexRule], 'any text at all')).toBeNull();
    expect(resolveAutoReply([invalidRegexRule], 'any text at all', {})).toBeNull();
  });

  it('a second, valid rule after an invalid-regex rule is still reachable (invalid pattern only fails ITS OWN match, not the loop)', () => {
    const invalidRegexRule: AutoReplyRuleRow = {
      id: 904,
      line_account_id: null,
      keyword: '(unclosed[',
      match_type: 'regex',
      reply_type: 'text',
      reply_content: 'unreachable',
      alt_text: null,
      sender_name: null,
      sender_icon: null,
      quick_reply: null,
      enable_share: 0,
      share_button_label: null,
      is_active: 1,
      priority: 10,
    };
    const catchAllRule: AutoReplyRuleRow = {
      id: 905,
      line_account_id: null,
      keyword: '',
      match_type: 'all',
      reply_type: 'text',
      reply_content: 'fallback reached',
      alt_text: null,
      sender_name: null,
      sender_icon: null,
      quick_reply: null,
      enable_share: 0,
      share_button_label: null,
      is_active: 1,
      priority: 1,
    };

    const matched = matchAutoReplyRule([invalidRegexRule, catchAllRule], 'hello');
    expect(matched?.id).toBe(905);
  });
});
