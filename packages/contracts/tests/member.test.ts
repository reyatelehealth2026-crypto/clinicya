import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  MemberCheckQuerySchema,
  MemberCheckResponseSchema,
  MemberGetCardQuerySchema,
  MemberGetCardResponseSchema,
  MemberRegisterRequestSchema,
  MemberRegisterResponseSchema,
  MemberUpdateProfileRequestSchema,
  MemberUpdateProfileResponseSchema,
  POINTS_HISTORY_TABLE_FOR_WELCOME_BONUS,
} from '../src/member';

const FIXTURES_DIR = join(__dirname, '../fixtures/member');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('member contracts — golden fixture round-trip', () => {
  it('check: existing registered member', () => {
    const { request, response } = loadFixture('check-existing-member.json');
    expect(MemberCheckQuerySchema.parse(request)).toBeTruthy();
    expect(MemberCheckResponseSchema.parse(response)).toBeTruthy();
  });

  it('check: auto-register branch (brand-new LINE user)', () => {
    const { request, response } = loadFixture('check-auto-register.json');
    expect(MemberCheckQuerySchema.parse(request)).toBeTruthy();
    const parsed = MemberCheckResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true, auto_registered: true, points: 50, has_profile: false });
  });

  it('check: auto-upgrade branch (existing unregistered user)', () => {
    const { request, response } = loadFixture('check-auto-upgrade.json');
    expect(MemberCheckQuerySchema.parse(request)).toBeTruthy();
    const parsed = MemberCheckResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true, auto_registered: true });
  });

  it('check: missing line_user_id -> flat failure, no data payload', () => {
    const { request, response } = loadFixture('check-missing-line-user-id.json');
    expect(MemberCheckQuerySchema.parse(request)).toBeTruthy();
    expect(MemberCheckResponseSchema.parse(response)).toEqual({ success: false, message: 'Missing line_user_id' });
  });

  it('get_card: ok with next_tier populated', () => {
    const { request, response } = loadFixture('get-card-ok.json');
    expect(MemberGetCardQuerySchema.parse(request)).toBeTruthy();
    expect(MemberGetCardResponseSchema.parse(response)).toBeTruthy();
  });

  it('get_card: user exists but not registered', () => {
    const { request, response } = loadFixture('get-card-not-registered.json');
    expect(MemberGetCardQuerySchema.parse(request)).toBeTruthy();
    const parsed = MemberGetCardResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: false, is_registered: false, user_exists: true });
  });

  it('register: success', () => {
    const { request, response } = loadFixture('register-success.json');
    expect(MemberRegisterRequestSchema.parse(request)).toBeTruthy();
    expect(MemberRegisterResponseSchema.parse(response)).toMatchObject({ success: true, tier: 'bronze' });
  });

  it('register: already a member -> short-circuit with existing member_id', () => {
    const { request, response } = loadFixture('register-already-member.json');
    expect(MemberRegisterRequestSchema.parse(request)).toBeTruthy();
    expect(MemberRegisterResponseSchema.parse(response)).toMatchObject({ success: false, member_id: 'M2600001' });
  });

  it('update_profile: success', () => {
    const { request, response } = loadFixture('update-profile-success.json');
    expect(MemberUpdateProfileRequestSchema.parse(request)).toBeTruthy();
    expect(MemberUpdateProfileResponseSchema.parse(response)).toEqual({ success: true, message: 'อัพเดทข้อมูลสำเร็จ' });
  });
});

describe('member contracts — existing-system quirk regression (contractNote §8)', () => {
  it('the welcome-bonus write table constant is points_history, NOT points_transactions', () => {
    // This is the one thing this batch must NOT "fix": member.php's welcome bonus goes to a different
    // table than the one LoyaltyPoints/rewards.php read/write. See member.ts's own doc comment.
    expect(POINTS_HISTORY_TABLE_FOR_WELCOME_BONUS).toBe('points_history');
    expect(POINTS_HISTORY_TABLE_FOR_WELCOME_BONUS).not.toBe('points_transactions');
  });
});
