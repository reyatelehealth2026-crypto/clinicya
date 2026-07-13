import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  POINTS_HISTORY_STATUS,
  POINTS_TRANSACTIONS_TABLE_FOR_HISTORY,
  PointsHistoryQuerySchema,
  PointsHistoryResponseSchema,
} from '../src/points-history';

const FIXTURES_DIR = join(__dirname, '../fixtures/points-history');

function loadFixture(name: string): { request: unknown; response: unknown; status: number } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('points-history contracts (action=history) — golden fixture round-trip', () => {
  it('history-ok: mixed earn/redeem log', () => {
    const fx = loadFixture('history-ok.json');
    expect(PointsHistoryQuerySchema.parse(fx.request)).toBeTruthy();
    const parsed = PointsHistoryResponseSchema.parse(fx.response);
    expect(parsed).toMatchObject({ success: true });
    if (parsed.success) {
      expect(parsed.history).toHaveLength(2);
      expect(parsed.history[0]?.type).toBe('redeem');
      expect(parsed.history[1]?.type).toBe('earn');
    }
    expect(fx.status).toBe(POINTS_HISTORY_STATUS);
  });

  it('history-empty: member with no transactions yet', () => {
    const fx = loadFixture('history-empty.json');
    expect(PointsHistoryQuerySchema.parse(fx.request)).toBeTruthy();
    expect(PointsHistoryResponseSchema.parse(fx.response)).toMatchObject({ success: true, history: [] });
  });

  it('missing-line-user-id: still HTTP 200 (matches PHP\'s always-200 error path)', () => {
    const fx = loadFixture('missing-line-user-id.json');
    expect(PointsHistoryResponseSchema.parse(fx.response)).toEqual({
      success: false,
      error: 'Missing line_user_id',
    });
    expect(fx.status).toBe(200);
  });

  it('user-not-found: still HTTP 200', () => {
    const fx = loadFixture('user-not-found.json');
    expect(PointsHistoryResponseSchema.parse(fx.response)).toEqual({ success: false, error: 'User not found' });
    expect(fx.status).toBe(200);
  });
});

describe('points-history contracts — existing-system quirk regression (contractNote §8)', () => {
  it('the table this action reads from is points_transactions, NOT points_history (the welcome-bonus write table)', () => {
    expect(POINTS_TRANSACTIONS_TABLE_FOR_HISTORY).toBe('points_transactions');
    expect(POINTS_TRANSACTIONS_TABLE_FOR_HISTORY).not.toBe('points_history');
  });
});
