import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
  DATA_RIGHTS_CONFIRMATION_CODE_REGEX,
  DataRightsExportDataRequestSchema,
  DataRightsExportDataResponseSchema,
  DataRightsRequestDeletionRequestSchema,
  DataRightsRequestDeletionResponseSchema,
  DataRightsWithdrawConsentRequestSchema,
  DataRightsWithdrawConsentResponseSchema,
} from '../src/data-rights';

const FIXTURES_DIR = join(__dirname, '../fixtures/data-rights');

function loadFixture(name: string): { request: unknown; response: unknown } {
  return JSON.parse(readFileSync(join(FIXTURES_DIR, name), 'utf-8'));
}

describe('data-rights contracts — golden fixture round-trip (all 3 actions)', () => {
  it('withdraw_consent: ok', () => {
    const { request, response } = loadFixture('withdraw-consent-ok.json');
    expect(DataRightsWithdrawConsentRequestSchema.parse(request)).toBeTruthy();
    expect(DataRightsWithdrawConsentResponseSchema.parse(response)).toEqual({
      success: true,
      message: 'ถอนความยินยอมเรียบร้อยแล้ว',
      consent_type: 'health_data',
    });
  });

  it('request_deletion: ok — SOFT flag only, confirmation_code matches FORMAT_CHECKS regex', () => {
    const { request, response } = loadFixture('request-deletion-ok.json');
    expect(DataRightsRequestDeletionRequestSchema.parse(request)).toBeTruthy();
    const parsed = DataRightsRequestDeletionResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true, status: 'requested' });
    if (parsed.success) {
      expect(parsed.confirmation_code).toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
      // No ambiguous glyphs (0/O/1/I) anywhere in the random suffix.
      expect(parsed.confirmation_code).not.toMatch(/[01OI]/);
    }
  });

  it('export_data: ok — 34-field profile allowlist + best-effort lists', () => {
    const { request, response } = loadFixture('export-data-ok.json');
    expect(DataRightsExportDataRequestSchema.parse(request)).toBeTruthy();
    const parsed = DataRightsExportDataResponseSchema.parse(response);
    expect(parsed).toMatchObject({ success: true, message: 'ส่งออกข้อมูลเรียบร้อยแล้ว' });
    if (parsed.success) {
      expect(parsed.data.export_meta.user_id).toBe(42);
      expect(parsed.data.profile.deletion_status).toBe('none');
      expect(parsed.data.orders).toHaveLength(1);
    }
  });

  it('user not found: shared generic-fail shape works for every action', () => {
    const { request, response } = loadFixture('user-not-found.json');
    expect(DataRightsWithdrawConsentRequestSchema.parse(request)).toBeTruthy();
    expect(DataRightsWithdrawConsentResponseSchema.parse(response)).toEqual({ success: false, message: 'User not found' });
  });
});

describe('DATA_RIGHTS_CONFIRMATION_CODE_REGEX — exported for the parity harness FORMAT_CHECKS', () => {
  it('matches the documented REYA-DEL-XXXXXXXX shape', () => {
    expect('REYA-DEL-A7BQXK9M').toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
  });

  it('rejects the ambiguous glyphs 0/O/1/I', () => {
    expect('REYA-DEL-A0BQXK9M').not.toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
    expect('REYA-DEL-A1BQXK9M').not.toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
    expect('REYA-DEL-AOBQXK9M').not.toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
    expect('REYA-DEL-AIBQXK9M').not.toMatch(DATA_RIGHTS_CONFIRMATION_CODE_REGEX);
  });
});
