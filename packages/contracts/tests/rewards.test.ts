import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  RewardsListQuerySchema,
  RewardsListResponseSchema,
  RewardsMyRedemptionsQuerySchema,
  RewardsMyRedemptionsResponseSchema,
  RewardsRedeemRequestSchema,
  RewardsRedeemResponseSchema,
} from '../src/rewards';

const FIXTURES_DIR = join(__dirname, '../fixtures/rewards');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('rewards contracts — golden fixture round-trip', () => {
  it('list: ok, own account has rewards', () => {
    const { request, response } = loadFixture('list-ok.json');
    expect(RewardsListQuerySchema.parse(request)).toBeTruthy();
    const parsed = RewardsListResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.rewards.length).toBe(2);
    }
  });

  it('list: default-account-fallback branch is response-shape-invisible (contractNote)', () => {
    const { request, response } = loadFixture('list-default-account-fallback.json');
    expect(RewardsListQuerySchema.parse(request)).toBeTruthy();
    const parsed = RewardsListResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
  });

  it('redeem: success path returns full shape (code/reward/id/expiry/balance/member)', () => {
    const { request, response } = loadFixture('redeem-success.json');
    expect(RewardsRedeemRequestSchema.parse(request)).toBeTruthy();
    const parsed = RewardsRedeemResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true, redemption_code: 'RW9K3XA1B2C3', new_balance: 620 });
  });

  it('redeem: insufficient points -> failure, message only', () => {
    const { request, response } = loadFixture('redeem-insufficient-points.json');
    expect(RewardsRedeemRequestSchema.parse(request)).toBeTruthy();
    expect(RewardsRedeemResponseSchema.parse(response)).toEqual({ success: false, message: 'แต้มไม่เพียงพอ' });
  });

  it('my_redemptions: ok', () => {
    const { request, response } = loadFixture('my-redemptions-ok.json');
    expect(RewardsMyRedemptionsQuerySchema.parse(request)).toBeTruthy();
    const parsed = RewardsMyRedemptionsResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true });
  });
});
